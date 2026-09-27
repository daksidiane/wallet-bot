<?php
/**
 * Точка входа бота (long polling). Запуск:  php bot.php
 *
 * Локально:      держи процесс открытым в терминале.
 * На «Бигете»:   запусти через cron каждые 1–2 минуты одним из способов:
 *                php /путь/до/bot/bot.php  — с защитой от параллельного запуска
 *                (процесс сам держит long polling до 50 секунд, повторные вызовы
 *                крана не конфликтуют, т.к. Telegram отдаёт только непрочитанные апдейты).
 *
 * Приложение работает через polling — вебхук, домен и SSL для MVP не нужны.
 */

require __DIR__ . '/src/Telegram.php';
require __DIR__ . '/src/Storage.php';
require __DIR__ . '/src/Bot.php';

$config = require __DIR__ . '/config.php';

$tg = new Telegram($config['secrets']['telegram_bot_token'], $config['telegram']['proxy'] ?? '');
$storage = new Storage($config['db'], $config['secrets']);
$bot = new Bot($tg, $storage, $config);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0700, true);
}

$offset = 0;

while (true) {
    try {
        $updates = $tg->getUpdates($offset > 0 ? $offset + 1 : 0, (int)$config['bot']['polling_timeout']);
    } catch (Throwable $e) {
        file_put_contents($logDir . '/bot.log', sprintf("[%s] getUpdates: %s\n", date('c'), $e->getMessage()), FILE_APPEND);
        $updates = [];
    }
    if ($updates === null) {
        file_put_contents($logDir . '/bot.log', sprintf("[%s] getUpdates: no HTTP response from Telegram (network/proxy?) - waiting\n", date('c')), FILE_APPEND);
        sleep(5);
        continue;
    }

    foreach ($updates as $update) {
        try {
            $bot->handle($update);
        } catch (Throwable $e) {
            file_put_contents(
                $logDir . '/bot.log',
                sprintf(
                    "[%s] update %d: %s in %s:%d\n",
                    date('c'),
                    (int)($update['update_id'] ?? 0),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ),
                FILE_APPEND
            );
        }
        $offset = max($offset, (int)($update['update_id'] ?? 0));
    }

    usleep(200000); // 0.2 с паузы между тиками, чтобы не грузить CPU
}