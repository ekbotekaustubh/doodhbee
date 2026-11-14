<?php
declare(strict_types=1);

use App\Core\Env;

require_once __DIR__ . '/../src/Core/Env.php';
Env::load(__DIR__ . '/..');

function getDbConfig(): array
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
    }

    return [
        'host' => (string)Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int)Env::get('DB_PORT', 3306),
        'database' => (string)Env::get('DB_DATABASE', 'dairyflow'),
        'username' => (string)Env::get('DB_USERNAME', 'dairyflow'),
        'password' => (string)Env::get('DB_PASSWORD', 'dairyflow123'),
        'charset' => 'utf8mb4',
        'options' => $options
    ];
}

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $config = getDbConfig();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );
        $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    }

    return $pdo;
}
