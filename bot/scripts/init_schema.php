<?php
/**
 * Создание таблиц в текущем режиме БД (см. config.php).
 *
 * Запуск:  php scripts/init_schema.php
 * Повторный запуск безопасен (IF NOT EXISTS) — можно использовать для миграций.
 */

require __DIR__ . '/../src/Storage.php';

$config = require __DIR__ . '/../config.php';
$storage = new Storage($config['db'], $config['secrets']);
$pdo = $storage->pdo();

$driver = $config['db']['driver'];
$ai = $driver === 'mysql'
    ? 'INT AUTO_INCREMENT PRIMARY KEY'
    : 'INTEGER PRIMARY KEY AUTOINCREMENT';

$mysqlIndexes = $driver === 'mysql'
    ? ",\n        KEY idx_user_id (user_id),\n        KEY idx_created_at (created_at)"
    : '';

$tables = [
    'users' => "CREATE TABLE IF NOT EXISTS users (
        id $ai,
        telegram_id BIGINT NOT NULL,
        username VARCHAR(255) DEFAULT NULL,
        first_name VARCHAR(255) DEFAULT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'RUB',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (telegram_id)
    )",
    'transactions' => "CREATE TABLE IF NOT EXISTS transactions (
        id $ai,
        user_id INT NOT NULL,
        type VARCHAR(10) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        category VARCHAR(255) DEFAULT NULL,
        comment TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP$mysqlIndexes
    )",
    'states' => "CREATE TABLE IF NOT EXISTS states (
        user_id INT NOT NULL,
        state VARCHAR(32) NOT NULL DEFAULT 'idle',
        data TEXT DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id)
    )",
    'categories' => "CREATE TABLE IF NOT EXISTS categories (
        id $ai,
        user_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_id, name)
    )",
    'hidden_categories' => "CREATE TABLE IF NOT EXISTS hidden_categories (
        id $ai,
        user_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_id, name)
    )",
];

foreach ($tables as $name => $sql) {
    $pdo->exec($sql);
    echo "✓ таблица '$name' готова\n";
}

if ($driver !== 'mysql') {
    // SQLite: индексы выносятся отдельным оператором
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_user_id ON transactions (user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_created_at ON transactions (created_at)');
    echo "✓ индексы созданы\n";
}

echo "Готово. Режим БД: {$driver}\n";