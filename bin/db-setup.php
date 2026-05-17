<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$dbPath = __DIR__ . '/../var/data.sqlite';
@unlink($dbPath);
@mkdir(dirname($dbPath), 0775, true);

$conn = DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'path'   => $dbPath,
]);

$conn->executeStatement(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    name TEXT NOT NULL
)
SQL);

$conn->insert('users', ['email' => 'ada@example.com',   'name' => 'Ada Lovelace']);
$conn->insert('users', ['email' => 'linus@example.com', 'name' => 'Linus Torvalds']);

echo "OK: SQLite DB created at {$dbPath} with 2 seed users.\n";
