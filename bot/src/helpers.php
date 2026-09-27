<?php
/**
 * Вспомогательные функции.
 */

/**
 * Проверка окружения перед стартом: нужные расширения для текущего режима БД.
 * Печатает понятную подсказку, возвращает false, если чего-то не хватает.
 */
function check_runtime_env(array $config): bool
{
    $driver = $config['db']['driver'] ?? 'sqlite';
    $checks = [
        'pdo_mysql'  => 'подключение к MySQL (режим mysql)',
        'pdo_sqlite' => 'имитация БД (режим sqlite)',
        'sqlite3'    => 'то же, что и pdo_sqlite',
        'curl'       => 'обмен с Telegram Bot API',
        'mbstring'   => 'корректная работа с русскими текстами',
        'openssl'    => 'HTTPS-соединение с Telegram',
    ];

    $missing = [];
    foreach ($checks as $ext => $why) {
        if ($ext === 'pdo_mysql' && $driver !== 'mysql') {
            continue;
        }
        if (($ext === 'pdo_sqlite' || $ext === 'sqlite3') && $driver === 'mysql') {
            continue;
        }
        if (!extension_loaded($ext)) {
            $missing[$ext] = $why;
        }
    }

    if (!$missing) {
        return true;
    }

    $ini = php_ini_loaded_file();
    fwrite(STDERR, "\n[ОШИБКА] В вашей сборке PHP не включены расширения:\n");
    foreach ($missing as $ext => $why) {
        fwrite(STDERR, "  - extension=" . $ext . "   ($why)\n");
    }
    fwrite(STDERR, "Режим БД в config.php: " . $driver . "\n");
    fwrite(STDERR, "Если запускаете через start.bat со встроенным PHP (папка php\),\n");
    fwrite(STDERR, "расширения подключаются автоматически — перезапустите start.bat.\n");
    fwrite(STDERR, "Если ошибка не уходит, включите расширения вручную в php.ini\n");
    fwrite(STDERR, "  (" . ($ini ?: 'путь покажет команда php --ini') . "):\n");
    fwrite(STDERR, "  1) раскомментируй строку  extension_dir = \"ext\"\n");
    fwrite(STDERR, "  2) для каждого расширения выше убери ';' в начале строки extension=...\n");
    fwrite(STDERR, "  3) сохрани файл и запусти start.bat ещё раз.\n\n");
    return false;
}

/**
 * Разбор суммы из текста пользователя.
 * Принимает: "1500", "1500,50", "1500.50", с пробелами между разрядами.
 * Возвращает float (2 знака) или null при некорректном вводе.
 */
function parse_amount(?string $input): ?float
{
    if ($input === null || trim($input) === '') {
        return null;
    }
    $s = preg_replace('/\s+/', '', trim($input));
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
        return null;
    }
    $value = round((float)$s, 2);
    if ($value <= 0 || $value > 999999999.99) {
        return null;
    }
    return $value;
}

/**
 * Усечение строки (категория/комментарий перед сохранением).
 */
function safe_text(?string $input, int $max): ?string
{
    if ($input === null) {
        return null;
    }
    $s = trim($input);
    if ($s === '') {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}