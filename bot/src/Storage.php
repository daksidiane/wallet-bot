<?php
/**
 * Хранилище данных. Два режима:
 *  - sqlite: имитация БД для локальной разработки и демо (БД-сервер не нужен);
 *  - mysql:  боевая БД на хостинге «Бигет».
 * Переключение — в config.php (ключ db.driver). SQL-запросы у кода одинаковые,
 * так что замена имитации на боевую БД — это только правка конфигурации.
 */

class Storage
{
    private PDO $pdo;

    public function __construct(array $dbCfg, array $secrets)
    {
        if ($dbCfg['driver'] === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $dbCfg['mysql_host'],
                (int)$dbCfg['mysql_port'],
                $dbCfg['mysql_dbname']
            );
            $this->pdo = new PDO($dsn, $secrets['mysql_user'], $secrets['mysql_password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // отключаем эмуляцию prepared — защита от SQL-инъекций на уровне драйвера
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $dir = dirname($dbCfg['sqlite_path']);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $this->pdo = new PDO('sqlite:' . $dbCfg['sqlite_path'], null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}