<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class FeatureTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->resetSchema();
        $this->seed();
    }

    protected function resetSchema(): void
    {
        $this->conn->executeStatement('DROP TABLE IF EXISTS users');
        $this->conn->executeStatement(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                name TEXT NOT NULL
            )
        SQL);
    }

    protected function seed(): void
    {
        $this->conn->insert('users', ['email' => 'ada@example.com',   'name' => 'Ada Lovelace']);
        $this->conn->insert('users', ['email' => 'linus@example.com', 'name' => 'Linus Torvalds']);
    }

    /** @return array<string, mixed> */
    protected function jsonResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
