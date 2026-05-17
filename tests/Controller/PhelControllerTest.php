<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\FeatureTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PhelControllerTest extends FeatureTestCase
{
    public function test_get_users_returns_seeded_list(): void
    {
        $this->client->request('GET', '/users');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            'application/json',
            $this->client->getResponse()->headers->get('content-type'),
        );
        self::assertSame(
            [
                ['id' => 1, 'email' => 'ada@example.com',   'name' => 'Ada Lovelace'],
                ['id' => 2, 'email' => 'linus@example.com', 'name' => 'Linus Torvalds'],
            ],
            $this->jsonResponse(),
        );
    }

    public function test_get_single_user_returns_user(): void
    {
        $this->client->request('GET', '/users/1');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            ['id' => 1, 'email' => 'ada@example.com', 'name' => 'Ada Lovelace'],
            $this->jsonResponse(),
        );
    }

    public function test_get_unknown_user_returns_404(): void
    {
        $this->client->request('GET', '/users/999');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame(['error' => 'not found'], $this->jsonResponse());
    }

    public function test_post_user_creates_and_returns_201(): void
    {
        $this->client->request(
            'POST',
            '/users',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'grace@example.com', 'name' => 'Grace Hopper'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            ['id' => 3, 'email' => 'grace@example.com', 'name' => 'Grace Hopper'],
            $this->jsonResponse(),
        );

        $row = $this->conn->fetchAssociative('SELECT id, email, name FROM users WHERE id = 3');
        self::assertSame(['id' => 3, 'email' => 'grace@example.com', 'name' => 'Grace Hopper'], $row);
    }

    public function test_post_user_with_invalid_body_returns_422(): void
    {
        $this->client->request(
            'POST',
            '/users',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'only@example.com'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame(['error' => 'email and name required'], $this->jsonResponse());
    }

    public function test_delete_users_returns_405(): void
    {
        $this->client->request('DELETE', '/users');

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->client->getResponse()->getStatusCode());
    }

    public function test_unknown_route_returns_404(): void
    {
        $this->client->request('GET', '/nope');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }
}
