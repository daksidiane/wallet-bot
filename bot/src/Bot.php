<?php
/**
 * Ядро бота: маршрутизация сообщений/колбэков и конечный автомат ввода операции.
 *
 * Команды:      /start /balance /history /settings /cancel
 * Reply-меню:   💰 Баланс · ➕ Доход · ➖ Расход · 📜 История · ⚙️ Настройки
 * Inline-меню:  баланс, добавление операции, история с фильтрами и пагинацией.
 */

require_once __DIR__ . '/helpers.php';

class Bot
{
    private const MENU_ITEMS = ['💰 Баланс', '📜 История', '➕ Доход', '➖ Расход', '⚙️ Настройки'];

    private Telegram $tg;
    private PDO $pdo;
    private array $config;

    public function __construct(Telegram $tg, Storage $storage, array $config)
    {
        $this->tg = $tg;
        $this->pdo = $storage->pdo();
        $this->config = $config;
        date_default_timezone_set($config['bot']['timezone']);
    }

    /* ------------------------------------------------------------------ */
    /* Точка входа обновлений                                              */
    /* ------------------------------------------------------------------ */

    public function handle(array $update): void
    {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }

    private function handleMessage(array $message): void
    {
        // Личный кошелёк: реагируем только в приватном чате, чтобы данные
        // не оказались в группе/канале (chat.type задаёт Telegram всегда).
        if (($message['chat']['type'] ?? 'private') !== 'private') {
            return;
        }
        $chatId = $message['chat']['id'] ?? 0;
        $from = $message['from'] ?? [];
        if (!$chatId || !isset($from['id'])) {
            return;
        }
        $user = $this->getOrCreateUser($from);
        $text = trim((string)($message['text'] ?? ''));
        if ($text === '') {
            return; // не-текстовые сообщения (стикеры и т.п.) игнорируем
        }

        // Обработка команд
        if (str_starts_with($text, '/')) {
            $parts = explode(' ', $text);
            $cmd = strtolower($parts[0]);
            if (str_contains($cmd, '@')) {
                $cmd = substr($cmd, 0, strpos($cmd, '@'));
            }
            switch ($cmd) {
                case '/start':
                    $this->clearState($user['id']);
                    $name = $user['first_name'] ?: 'друг';
                    $this->showMenu($chatId, "Привет, $name! 👋\nЭто твой личный кошелёк. Выбери действие:");
                    break;
                case '/balance':
                    $this->showBalance($chatId, $user['id']);
                    break;
                case '/history':
                    $this->showHistory($chatId, $user['id'], 0, 'all', 'all');
                    break;
                case '/settings':
                    $this->showSettings($chatId);
                    break;
                case '/cancel':
                    $this->clearState($user['id']);
                    $this->showMenu($chatId, 'Действие отменено. Главное меню:');
                    break;
                default:
                    $this->send($chatId, 'Неизвестная команда. Начни с /start');
            }
            return;
        }

        $state = $this->loadState($user['id']);

        // Во время ввода операции нажатие кнопки главного меню отменяет операцию
        if ($state['state'] !== 'idle' && in_array($text, self::MENU_ITEMS, true)) {
            $this->clearState($user['id']);
            $this->send($chatId, 'Предыдущее действие отменено.');
            $this->runMenuItem($chatId, $user['id'], $text);
            return;
        }

        if ($state['state'] !== 'idle') {
            $this->consumeInput($chatId, $user['id'], $text, $state);
            return;
        }

        $this->runMenuItem($chatId, $user['id'], $text);
    }

    private function handleCallback(array $callback): void
    {
        $from = $callback['from'] ?? [];
        if (!isset($from['id'])) {
            return;
        }
        // То же ограничение, что и для сообщений: только приватные чаты.
        if (($callback['message']['chat']['type'] ?? 'private') !== 'private') {
            return;
        }
        $user = $this->getOrCreateUser($from);
        $chatId = $callback['message']['chat']['id'] ?? 0;
        $msgId = $callback['message']['message_id'] ?? 0;
        $cbId = $callback['id'] ?? '';
        $data = $callback['data'] ?? '';
        if (!$chatId) {
            return;
        }

        // Фильтры и пагинация истории редактируют текущее сообщение
        if (preg_match('/^hist:(\d+):(all|income|expense):(all|7|30)$/', $data, $m)) {
            $this->tg->answerCallbackQuery($cbId);
            [$text, $keyboard] = $this->historyData((int)$user['id'], (int)$m[1], $m[2], $m[3]);
            $this->tg->editMessage($chatId, $msgId, $text, $keyboard);
            return;
        }

        // Выбор ранее использованной категории (index из списка recent в состоянии)
        if (preg_match('/^cat:select:(\d+)$/', $data, $m)) {
            $this->tg->answerCallbackQuery($cbId);
            $this->tg->editMessageReplyMarkup($chatId, $msgId, []);
            $this->selectCategory($chatId, (int)$user['id'], (int)$m[1]);
            return;
        }

        // Листание страниц выбора категории — перерисовываем это же сообщение
        if (preg_match('/^cat:page:(\d+)$/', $data, $m)) {
            $this->tg->answerCallbackQuery($cbId);
            $this->renderCategoryPage($chatId, $msgId, (int)$user['id'], (int)$m[1]);
            return;
        }

        // Листание страниц управления категориями
        if (preg_match('/^mgt:page:(\d+)$/', $data, $m)) {
            $this->tg->answerCallbackQuery($cbId);
            $this->renderCategoriesPage($chatId, $msgId, (int)$user['id'], (int)$m[1]);
            return;
        }

        // Остальные колбэки: снимаем клавиатуру и отвечаем новым сообщением
        $this->tg->editMessageReplyMarkup($chatId, $msgId, []);
        $this->tg->answerCallbackQuery($cbId);

        if (preg_match('/^mgt:del:(\d+)$/', $data, $m)) {
            $this->deleteCategory($chatId, (int)$user['id'], (int)$m[1]);
            return;
        }

        switch ($data) {
            case 'menu':
                $this->showMenu($chatId);
                break;

            case 'settings':
                $this->showSettings($chatId);
                break;

            case 'cats':
                $this->showCategories($chatId, (int)$user['id'], 0);
                break;

            case 'cat:add':
                $this->beginAddCategory($chatId, (int)$user['id']);
                break;

            case 'cancel':
                $this->clearState((int)$user['id']);
                $this->showMenu($chatId, 'Действие отменено. Главное меню:');
                break;

            case 'op:income':
            case 'op:expense':
                $this->beginOperation($chatId, (int)$user['id'], substr($data, 3));
                break;

            case 'cat:skip':
            case 'com:skip':
                $this->skipOptionalField($chatId, (int)$user['id'], $data === 'cat:skip');
                break;

            case 'confirm:yes':
                $this->commitTransaction($chatId, (int)$user['id']);
                break;

            default:
                $this->showMenu($chatId);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Главное меню и экраны                                                */
    /* ------------------------------------------------------------------ */

    private function runMenuItem(int $chatId, int $dbUserId, string $text): void
    {
        switch ($text) {
            case '💰 Баланс':
                $this->showBalance($chatId, $dbUserId);
                break;
            case '📜 История':
                $this->showHistory($chatId, $dbUserId, 0, 'all', 'all');
                break;
            case '➕ Доход':
                $this->beginOperation($chatId, $dbUserId, 'income');
                break;
            case '➖ Расход':
                $this->beginOperation($chatId, $dbUserId, 'expense');
                break;
            case '⚙️ Настройки':
                $this->showSettings($chatId);
                break;
            default:
                $this->showMenu($chatId, 'Используй кнопки меню 👇');
        }
    }

    private function showMenu(int $chatId, ?string $intro = null): void
    {
        $text = $intro ?? 'Главное меню. Выбери действие:';
        $this->send($chatId, $text, [
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => '💰 Баланс'], ['text' => '📜 История']],
                    [['text' => '➕ Доход'], ['text' => '➖ Расход']],
                    [['text' => '⚙️ Настройки']],
                ],
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function showBalance(int $chatId, int $dbUserId): void
    {
        $t = $this->totals($dbUserId);
        $balance = $t['income'] - $t['expense'];

        $text = "💰 Текущий баланс\n\n"
            . $this->fmt($balance) . "\n\n"
            . "➕ Пополнено: {$this->fmt($t['income'])} ({$t['incomeCount']} оп.)\n"
            . "➖ Списано:   {$this->fmt($t['expense'])} ({$t['expenseCount']} оп.)";

        $this->send($chatId, $text, $this->inline([
            [['text' => '➕ Доход', 'callback_data' => 'op:income'], ['text' => '➖ Расход', 'callback_data' => 'op:expense']],
            [['text' => '🔙 Меню', 'callback_data' => 'menu']],
        ]));
    }

    private function showSettings(int $chatId): void
    {
        $this->send($chatId,
            "⚙️ Настройки\n\n"
            . "Валюта: {$this->config['bot']['currency']} ({$this->config['bot']['currency_symbol']})\n"
            . "Часовой пояс: {$this->config['bot']['timezone']}\n"
            . "Формула расчёта: Баланс = Пополнения − Списания\n"
            . "Уведомления: об операциях — включены\n\n"
            . "Изменение валюты/пояса — в config.php.\n"
            . "Категории редактируются в разделе «🗂 Категории».",
            $this->inline([
                [['text' => '🗂 Категории', 'callback_data' => 'cats']],
                [['text' => '🔙 Меню', 'callback_data' => 'menu']],
            ])
        );
    }

    /* ------------------------------------------------------------------ */
    /* Управление категориями                                               */
    /* ------------------------------------------------------------------ */

    private function showCategories(int $chatId, int $dbUserId, int $page): void
    {
        [$text, $rows] = $this->categoriesScreenData($this->categorySource($dbUserId), $page);
        $this->send($chatId, $text, $this->inline($rows));
    }

    private function renderCategoriesPage(int $chatId, int $msgId, int $dbUserId, int $page): void
    {
        [$text, $rows] = $this->categoriesScreenData($this->categorySource($dbUserId), $page);
        $this->tg->editMessage($chatId, $msgId, $text, $rows);
    }

    /**
     * Экран управления: показывает тот же список, что и подсказки выбора
     * (категории из истории + добавленные вручную, без скрытых).
     * Удаление по имени через индекс в этом же списке.
     */
    private function categoriesScreenData(array $list, int $page): array
    {
        $perPage = 8;
        $maxPages = max(1, (int)ceil(count($list) / $perPage));
        $page = max(0, min($page, $maxPages - 1));

        if (!$list) {
            return [
                "🗂 Мои категории\n\nПока пусто. Категории появятся здесь по мере использования.\nДобавь свою постоянную категорию:",
                [
                    [['text' => '➕ Добавить', 'callback_data' => 'cat:add']],
                    [['text' => '🔙 Настройки', 'callback_data' => 'settings']],
                ],
            ];
        }

        $slice = array_slice($list, $page * $perPage, $perPage, true);
        $rows = [];
        foreach ($slice as $i => $name) {
            $rows[] = [['text' => '❌ ' . mb_substr((string)$name, 0, 40), 'callback_data' => 'mgt:del:' . $i]];
        }

        $rows[] = [
            ['text' => '➕ Добавить', 'callback_data' => 'cat:add'],
            ['text' => '🔙 Настройки', 'callback_data' => 'settings'],
        ];

        if ($maxPages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = ['text' => '◀ Предыдущие', 'callback_data' => 'mgt:page:' . ($page - 1)];
            }
            if ($page < $maxPages - 1) {
                $nav[] = ['text' => 'Следующие ▶', 'callback_data' => 'mgt:page:' . ($page + 1)];
            }
            if (count($nav) === 1) {
                $nav[0]['width'] = 2;
            }
            $rows[] = $nav;
        }

        $pagesTxt = $maxPages > 1 ? "\n\nСтраница " . ($page + 1) . " из $maxPages" : '';
        $text = "🗂 Мои категории\n\n"
            . "Это те же категории, что подсказываются в операциях.\n"
            . "Нажми «❌», чтобы исключить из подсказок (история не меняется).$pagesTxt";

        return [$text, $rows];
    }

    private function beginAddCategory(int $chatId, int $dbUserId): void
    {
        $this->saveState($dbUserId, 'cat_add', []);
        $this->send($chatId, '✏️ Введи название категории (например «Кафе» или «Зарплата»). Для отмены — /cancel. ');
    }

    private function listCategories(int $dbUserId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY name');
        $stmt->execute([$dbUserId]);
        return $stmt->fetchAll();
    }

    private function deleteCategory(int $chatId, int $dbUserId, int $index): void
    {
        $list = $this->categorySource($dbUserId);
        $name = $list[$index] ?? null;

        if ($name === null) {
            $this->send($chatId, 'Категория не найдена, обнови список.');
            $this->showCategories($chatId, $dbUserId, 0);
            return;
        }

        $this->pdo->prepare('DELETE FROM categories WHERE user_id = ? AND name = ?')
            ->execute([$dbUserId, $name]);

        // даже если категория подтянута из истории — исключаем её из подсказок
        $hid = $this->pdo->prepare('INSERT INTO hidden_categories (user_id, name) VALUES (?, ?)');
        try {
            $hid->execute([$dbUserId, $name]);
        } catch (PDOException $ignored) {
        }

        $this->send($chatId, 'Категория «' . $name . '» исключена из подсказок. История не меняется.');
        $this->showCategories($chatId, $dbUserId, 0);
    }

    private function hiddenCategoryNames(int $dbUserId): array
    {
        $stmt = $this->pdo->prepare('SELECT name FROM hidden_categories WHERE user_id = ?');
        $stmt->execute([$dbUserId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /* ------------------------------------------------------------------ */
    /* Добавление операции (конечный автомат)                               */
    /* ------------------------------------------------------------------ */

    private function beginOperation(int $chatId, int $dbUserId, string $type): void
    {
        if ($type !== 'income') {
            $type = 'expense';
        }
        $this->saveState($dbUserId, 'amount', ['type' => $type]);
        $label = $type === 'income' ? 'пополнение' : 'списание';
        $this->send($chatId, "Введи сумму $label.\nНапример: 1500 или 1500,50", $this->inline([
            [['text' => '🔙 Отмена', 'callback_data' => 'cancel']],
        ]));
    }

    private function consumeInput(int $chatId, int $dbUserId, string $text, array $state): void
    {
        $data = json_decode($state['data'] ?? '{}', true) ?: [];

        switch ($state['state']) {
            case 'amount':
                $amount = parse_amount($text);
                if ($amount === null) {
                    $this->send($chatId, '❌ Некорректная сумма. Примеры: 1500, 1500.50, 999.99');
                    return;
                }
                $data['amount'] = $amount;
                $this->saveState($dbUserId, 'category', $data);
                $this->askCategory($chatId, $dbUserId);
                break;

            case 'category':
                $data['category'] = safe_text($text, 120);
                $this->saveState($dbUserId, 'comment', $data);
                $this->askComment($chatId);
                break;

            case 'comment':
                $data['comment'] = safe_text($text, 500);
                $this->saveState($dbUserId, 'confirm', $data);
                $this->askConfirm($chatId, $data);
                break;

            case 'cat_add':
                $this->addCategoryFromInput($chatId, $dbUserId, $text);
                break;

            default:
                $this->send($chatId, 'Используй кнопки ниже 👇');
        }
    }

    private function skipOptionalField(int $chatId, int $dbUserId, bool $isCategory): void
    {
        $state = $this->loadState($dbUserId);
        $data = json_decode($state['data'] ?? '{}', true) ?: [];

        if ($isCategory) {
            if (($state['state'] ?? '') !== 'category') {
                return;
            }
            $data['category'] = null;
            $this->saveState($dbUserId, 'comment', $data);
            $this->askComment($chatId);
        } else {
            if (($state['state'] ?? '') !== 'comment') {
                return;
            }
            $data['comment'] = null;
            $this->saveState($dbUserId, 'confirm', $data);
            $this->askConfirm($chatId, $data);
        }
    }

    private function askCategory(int $chatId, int $dbUserId): void
    {
        $state = $this->loadState($dbUserId);
        $data = json_decode($state['data'] ?? '{}', true) ?: [];

        // Подсказки: управляемые категории + авто-память из истории (без дублей).
        $recent = $this->categorySource($dbUserId);
        $data['recent'] = $recent;
        $data['cat_page'] = 0;
        $this->saveState($dbUserId, 'category', $data);

        $this->send($chatId, $this->categoryPrompt($recent, 0), $this->inline($this->categoryKeyboard($recent, 0)));
    }

    private function categoryPrompt(array $recent, int $page): string
    {
        $text = '🗂 Категория (необязательно). Выбери из ранее введённых или введи свою:';
        $p = $this->categoryPaging($recent, $page);
        if ($p['paged']) {
            $text .= "\n\nСтраница " . ($p['page'] + 1) . ' из ' . $p['maxPages'];
        }
        return $text;
    }

    /** Параметры пагинации подсказок: до 5 за раз, при большем числе — по 4 на страницу. */
    private function categoryPaging(array $recent, int $page): array
    {
        $total = count($recent);
        if ($total === 0) {
            return ['paged' => false, 'perPage' => 1, 'maxPages' => 1, 'page' => 0];
        }
        $paged = $total > 5;
        $perPage = $paged ? 4 : $total;
        $maxPages = max(1, (int)ceil($total / $perPage));
        $page = max(0, min($page, $maxPages - 1));
        return ['paged' => $paged, 'perPage' => $perPage, 'maxPages' => $maxPages, 'page' => $page];
    }

    /**
     * Кнопки выбора категории.
     * Лимит Telegram — 100 inline-кнопок на сообщение, поэтому пагинация не про лимит,
     * а про удобство. Навигация — последняя строка; если доступен только один переход,
     * он растягивается во всю ширину (width=2), чтобы не оставалась пустая ячейка.
     */
    private function categoryKeyboard(array $recent, int $page): array
    {
        if (!$recent) {
            return [
                [['text' => 'Пропустить', 'callback_data' => 'cat:skip']],
                [['text' => '🔙 Отмена', 'callback_data' => 'cancel']],
            ];
        }

        $p = $this->categoryPaging($recent, $page);

        // array_slice с сохранением ключей -> кнопки ссылаются на индекс исходного списка
        $slice = array_slice($recent, $p['page'] * $p['perPage'], $p['perPage'], true);
        $buttons = [];
        foreach ($slice as $i => $cat) {
            $buttons[] = [
                'text'          => mb_substr((string)$cat, 0, 40),
                'callback_data' => 'cat:select:' . $i,
            ];
        }

        $rows = [];
        foreach (array_chunk($buttons, 2) as $chunk) {
            $rows[] = $chunk;
        }

        $rows[] = [
            ['text' => 'Пропустить', 'callback_data' => 'cat:skip'],
            ['text' => '🔙 Отмена', 'callback_data' => 'cancel'],
        ];

        if ($p['paged']) {
            $nav = [];
            if ($p['page'] > 0) {
                $nav[] = ['text' => '◀ Предыдущие', 'callback_data' => 'cat:page:' . ($p['page'] - 1)];
            }
            if ($p['page'] < $p['maxPages'] - 1) {
                $nav[] = ['text' => 'Следующие ▶', 'callback_data' => 'cat:page:' . ($p['page'] + 1)];
            }
            if (count($nav) === 1) {
                $nav[0]['width'] = 2; // одна стрелка — во всю ширину последней строки
            }
            $rows[] = $nav;
        }

        return $rows;
    }

    /** Перерисовка окна выбора категории на страницу $page (editMessage). */
    private function renderCategoryPage(int $chatId, int $msgId, int $dbUserId, int $page): void
    {
        $state = $this->loadState($dbUserId);
        if (($state['state'] ?? '') !== 'category') {
            return;
        }
        $data = json_decode($state['data'] ?? '{}', true) ?: [];
        $recent = is_array($data['recent'] ?? null) ? $data['recent'] : [];
        $data['cat_page'] = $page;
        $this->saveState($dbUserId, 'category', $data);
        $this->tg->editMessage($chatId, $msgId, $this->categoryPrompt($recent, $page), $this->categoryKeyboard($recent, $page));
    }

    /**
     * Категории для подсказки, отсортированные по последнему использованию:
     * свежие первыми, ещё не использованные (новые из «Категорий») — в конце по алфавиту.
     */
    private function categorySource(int $dbUserId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT category, MAX(id) AS last_id FROM transactions
             WHERE user_id = ? AND category IS NOT NULL AND category <> \'\'
             GROUP BY category'
        );
        $stmt->execute([$dbUserId]);
        $lastUse = [];
        foreach ($stmt->fetchAll() as $r) {
            $lastUse[(string)$r['category']] = (int)$r['last_id'];
        }

        $hidden = array_flip($this->hiddenCategoryNames($dbUserId));

        $names = [];
        foreach ($this->listCategories($dbUserId) as $c) {
            $name = (string)$c['name'];
            if (!isset($hidden[$name])) {
                $names[] = $name;
            }
        }
        foreach ($lastUse as $cat => $lastId) {
            if (!isset($hidden[$cat]) && !in_array($cat, $names, true)) {
                $names[] = $cat;
            }
        }

        usort($names, static function (string $a, string $b) use ($lastUse): int {
            $la = $lastUse[$a] ?? -1;
            $lb = $lastUse[$b] ?? -1;
            if ($la !== $lb) {
                return $la > $lb ? -1 : 1; // последняя использованная — первой
            }
            return strcasecmp($a, $b);
        });

        return $names;
    }

    /** Обработка нажатия на сохранённую категорию. */
    private function selectCategory(int $chatId, int $dbUserId, int $index): void
    {
        $state = $this->loadState($dbUserId);
        if (($state['state'] ?? '') !== 'category') {
            return;
        }
        $data = json_decode($state['data'] ?? '{}', true) ?: [];
        $recent = $data['recent'] ?? [];
        if (!is_array($recent) || !isset($recent[$index]) || !is_string($recent[$index])) {
            $this->send($chatId, 'Категория не найдена. Выбери из списка ещё раз или введи свою.');
            return;
        }
        $data['category'] = $recent[$index];
        unset($data['recent'], $data['cat_page']);
        $this->saveState($dbUserId, 'comment', $data);
        $this->askComment($chatId);
    }

    private function addCategoryFromInput(int $chatId, int $dbUserId, string $text): void
    {
        $name = safe_text($text, 120);
        if ($name === null) {
            $this->send($chatId, '❌ Пустое название. Введи текст или отправь /cancel.');
            return;
        }
        try {
            // если категория была скрыта — снова показываем её в подсказках
            $this->pdo->prepare('DELETE FROM hidden_categories WHERE user_id = ? AND name = ?')
                ->execute([$dbUserId, $name]);
            $ins = $this->pdo->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
            $ins->execute([$dbUserId, $name]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                $this->clearState($dbUserId);
                $this->send($chatId, "Категория «{$name}» уже есть.");
                $this->showCategories($chatId, $dbUserId, 0);
                return;
            }
            throw $e;
        }
        $this->clearState($dbUserId);
        $this->send($chatId, "✅ Категория «{$name}» добавлена.");
        $this->showCategories($chatId, $dbUserId, 0);
    }

    private function askComment(int $chatId): void
    {
        $this->send($chatId, '💬 Комментарий (необязательно). Введи текст или нажми «Пропустить»:', $this->inline([
            [['text' => 'Пропустить', 'callback_data' => 'com:skip']],
            [['text' => '🔙 Отмена', 'callback_data' => 'cancel']],
        ]));
    }

    private function askConfirm(int $chatId, array $data): void
    {
        $label = $data['type'] === 'income' ? '➕ Пополнение' : '➖ Списание';
        $text = "Проверь данные:\n"
            . "Тип: $label\n"
            . 'Сумма: ' . $this->fmt((float)$data['amount']) . "\n"
            . 'Категория: ' . ($data['category'] ?: '—') . "\n"
            . 'Комментарий: ' . ($data['comment'] ?: '—');
        $this->send($chatId, $text, $this->inline([
            [['text' => '✅ Подтвердить', 'callback_data' => 'confirm:yes'], ['text' => '❌ Отменить', 'callback_data' => 'cancel']],
        ]));
    }

    private function commitTransaction(int $chatId, int $dbUserId): void
    {
        $state = $this->loadState($dbUserId);
        if (($state['state'] ?? '') !== 'confirm') {
            return;
        }
        $data = json_decode($state['data'] ?? '{}', true) ?: [];
        if (!isset($data['amount'], $data['type'])) {
            $this->clearState($dbUserId);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO transactions (user_id, type, amount, category, comment, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dbUserId,
            $data['type'] === 'income' ? 'income' : 'expense',
            (float)$data['amount'],
            $data['category'] ?: null,
            $data['comment'] ?: null,
            date('Y-m-d H:i:s'),
        ]);

        $this->clearState($dbUserId);

        $t = $this->totals($dbUserId);
        $balance = $t['income'] - $t['expense'];
        $label = $data['type'] === 'income' ? '➕ Пополнение' : '➖ Списание';

        $this->send($chatId,
            "✅ Операция сохранена\n"
            . "$label: {$this->fmt((float)$data['amount'])}\n\n"
            . "💰 Текущий баланс: {$this->fmt($balance)}"
        );
    }

    /* ------------------------------------------------------------------ */
    /* История операций                                                     */
    /* ------------------------------------------------------------------ */

    private function showHistory(int $chatId, int $dbUserId, int $page, string $typeF, string $rangeF): void
    {
        [$text, $keyboard] = $this->historyData($dbUserId, $page, $typeF, $rangeF);
        $this->send($chatId, $text, $this->inline($keyboard));
    }

    /**
     * Формирует текст истории и inline-клавиатуру (фильтры + пагинация).
     * @return array{0: string, 1: array}
     */
    private function historyData(int $dbUserId, int $page, string $typeF, string $rangeF): array
    {
        $pageSize = (int)$this->config['bot']['history_page'];
        $page = max(0, $page);

        $where = ['user_id = ?'];
        $params = [$dbUserId];

        if ($typeF !== 'all') {
            $where[] = 'type = ?';
            $params[] = $typeF;
        }
        if ($rangeF !== 'all') {
            $where[] = 'created_at >= ?';
            $params[] = date('Y-m-d H:i:s', time() - (int)$rangeF * 86400);
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM transactions$whereSql");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = $page * $pageSize;
        $rows = [];
        if ($offset < $total) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM transactions$whereSql ORDER BY id DESC LIMIT $pageSize OFFSET $offset"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }

        $lines = ['📜 История операций'];
        if ($total === 0) {
            $lines[] = 'Операций пока нет. Добавь первую через «➕ Доход» или «➖ Расход».';
        } else {
            $lines[] = sprintf('Показаны %d–%d из %d', min($offset + 1, $total), min($offset + $pageSize, $total), $total);
            foreach ($rows as $r) {
                $icon = $r['type'] === 'income' ? '➕' : '➖';
                $line = sprintf(
                    "#%d · %s · %s %s · %s",
                    $r['id'],
                    date('d.m.y H:i', strtotime($r['created_at'])),
                    $icon,
                    $this->fmt((float)$r['amount']),
                    $r['category'] ?: '—'
                );
                if ($r['comment']) {
                    $line .= "\n    💬 " . $r['comment'];
                }
                $lines[] = $line;
            }
        }

        $typeBtn = function (string $v, string $l) use ($typeF, $rangeF): array {
            return ['text' => $l . ($typeF === $v ? ' ✅' : ''), 'callback_data' => "hist:0:$v:$rangeF"];
        };
        $rangeBtn = function (string $v, string $l) use ($typeF, $rangeF): array {
            return ['text' => $l . ($rangeF === $v ? ' ✅' : ''), 'callback_data' => "hist:0:$typeF:$v"];
        };

        $keyboard = [
            [$typeBtn('all', 'Все'), $typeBtn('income', 'Доходы'), $typeBtn('expense', 'Расходы')],
            [$rangeBtn('all', 'Всё время'), $rangeBtn('7', '7 дней'), $rangeBtn('30', '30 дней')],
        ];

        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '◀ Назад', 'callback_data' => 'hist:' . ($page - 1) . ":$typeF:$rangeF"];
        }
        if ($offset + $pageSize < $total) {
            $nav[] = ['text' => 'Вперёд ▶', 'callback_data' => 'hist:' . ($page + 1) . ":$typeF:$rangeF"];
        }
        if ($nav) {
            $keyboard[] = $nav;
        }
        $keyboard[] = [['text' => '🔙 Меню', 'callback_data' => 'menu']];

        return [implode("\n", $lines), $keyboard];
    }

    /* ------------------------------------------------------------------ */
    /* Пользователи и состояние ввода                                       */
    /* ------------------------------------------------------------------ */

    private function getOrCreateUser(array $from): array
    {
        $telegramId = (int)$from['id'];
        $username = safe_text($from['username'] ?? null, 255);
        $firstName = safe_text($from['first_name'] ?? null, 255);

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE telegram_id = ?');
        $stmt->execute([$telegramId]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['username'] !== $username || $user['first_name'] !== $firstName) {
                $upd = $this->pdo->prepare('UPDATE users SET username = ?, first_name = ? WHERE telegram_id = ?');
                $upd->execute([$username, $firstName, $telegramId]);
                $stmt->execute([$telegramId]);
                $user = $stmt->fetch();
            }
            return $user;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO users (telegram_id, username, first_name, currency) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$telegramId, $username, $firstName, $this->config['bot']['currency']]);
        $stmt->execute([$telegramId]);
        return $stmt->fetch();
    }

    private function loadState(int $dbUserId): array
    {
        $stmt = $this->pdo->prepare('SELECT state, data FROM states WHERE user_id = ?');
        $stmt->execute([$dbUserId]);
        $row = $stmt->fetch();
        return $row ?: ['state' => 'idle', 'data' => '{}'];
    }

    private function saveState(int $dbUserId, string $state, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $check = $this->pdo->prepare('SELECT 1 FROM states WHERE user_id = ?');
        $check->execute([$dbUserId]);
        if ($check->fetch()) {
            $upd = $this->pdo->prepare('UPDATE states SET state = ?, data = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?');
            $upd->execute([$state, $json, $dbUserId]);
        } else {
            $ins = $this->pdo->prepare('INSERT INTO states (user_id, state, data) VALUES (?, ?, ?)');
            $ins->execute([$dbUserId, $state, $json]);
        }
    }

    private function clearState(int $dbUserId): void
    {
        $del = $this->pdo->prepare('DELETE FROM states WHERE user_id = ?');
        $del->execute([$dbUserId]);
    }

    private function totals(int $dbUserId): array
    {
        $t = ['income' => 0.0, 'expense' => 0.0, 'incomeCount' => 0, 'expenseCount' => 0];
        $stmt = $this->pdo->prepare(
            'SELECT type, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS s FROM transactions WHERE user_id = ? GROUP BY type'
        );
        $stmt->execute([$dbUserId]);
        foreach ($stmt->fetchAll() as $r) {
            $key = $r['type'] === 'income' ? 'income' : 'expense';
            $t[$key] = (float)$r['s'];
            $t[$key . 'Count'] = (int)$r['c'];
        }
        return $t;
    }

    /* ------------------------------------------------------------------ */
    /* Утилиты                                                              */
    /* ------------------------------------------------------------------ */

    private function fmt(float $value): string
    {
        return number_format(round($value, 2), 2, ',', ' ') . ' ' . $this->config['bot']['currency_symbol'];
    }

    private function send(int $chatId, string $text, array $extra = []): void
    {
        $this->tg->sendMessage($chatId, $text, $extra);
    }

    private function inline(array $rows): array
    {
        return ['reply_markup' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE)];
    }
}