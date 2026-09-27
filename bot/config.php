<?php
/**
 * Конфигурация бота.
 * Секреты (токен, пароль БД) читаются из secrets.php — см. secrets.example.php.
 *
 * Переключение между имитацией и боевой БД:
 *   - 'sqlite' — имитация для разработки/демо (файл db/wallet.sqlite, БД-сервер не нужен);
 *   - 'mysql'  — боевой режим, когда появится доступ к хостингу «Бигет».
 *   Менять только 'driver' и параметры подключения ниже; SQL-запросы одинаковые.
 */

$secretsFile = __DIR__ . '/secrets.php';
$secrets = is_file($secretsFile) ? require $secretsFile : [];

if (empty($secrets['telegram_bot_token'])) {
    fwrite(STDERR, "[ОШИБКА] Не найден secrets.php или в нём пуст telegram_bot_token.\n");
    fwrite(STDERR, "Скопируй secrets.example.php в secrets.php и вставь токен от @BotFather.\n");
    exit(1);
}

return [
    'secrets' => [
        'telegram_bot_token' => $secrets['telegram_bot_token'],
        'mysql_user'         => $secrets['mysql_user'] ?? '',
        'mysql_password'     => $secrets['mysql_password'] ?? '',
    ],

    'telegram' => [
        // HTTP(S)/SOCKS5-прокси до api.telegram.org. Пусто — напрямую (если сеть пропускает Telegram).
        // Примеры: 'http://127.0.0.1:7890' (Clash/v2ray), 'socks5://127.0.0.1:1080'.
        'proxy' => $secrets['telegram_proxy'] ?? '',
    ],

    'db' => [
        // 'sqlite' — имитация БД (локально). 'mysql' — боевой режим на хостинге.
        'driver'       => 'sqlite',
        // sqlite: путь к файлу БД (папка тоже создаст автоматом).
        'sqlite_path'  => __DIR__ . '/db/wallet.sqlite',
        // mysql: параметры подключения к БД на «Бигете».
        'mysql_host'   => 'localhost',
        'mysql_port'   => 3306,
        'mysql_dbname' => 'wallet_bot',
        // 'mysql_user' и 'mysql_password' берутся из secrets.php
    ],

    'bot' => [
        'timezone'        => 'Europe/Moscow',
        'currency'        => 'RUB',
        'currency_symbol' => '₽',
        // таймаут long-polling getUpdates (сек)
        'polling_timeout' => 50,
        // размер страницы истории операций
        'history_page'    => 10,
        'max_amount'      => 999999999.99,
    ],
];