<?php

declare(strict_types=1);

namespace App\Phel;

use Doctrine\DBAL\Connection;
use Phel\Lang\Registry;
use Phel\Phel;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PhelApp
{
    private static bool $booted = false;
    private mixed $rootHandler = null;
    private mixed $systemBuilder = null;
    private mixed $coreGet = null;
    private mixed $phelToPhp = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly Connection $conn,
    ) {}

    public function handle(Request $request): Response
    {
        $handler = $this->handler();

        $reqMap = \Phel::map(
            \Phel::keyword('method'),         strtoupper($request->getMethod()),
            \Phel::keyword('uri'),            $request->getRequestUri(),
            \Phel::keyword('headers'),        $request->headers->all(),
            \Phel::keyword('parsed-body'),    $this->parsedBody($request),
            \Phel::keyword('query-params'),   $request->query->all(),
            \Phel::keyword('cookie-params'),  $request->cookies->all(),
            \Phel::keyword('server-params'),  $request->server->all(),
            \Phel::keyword('uploaded-files'), [],
            \Phel::keyword('version'),        '1.1',
            \Phel::keyword('attributes'),     $this->systemMap(),
        );

        $resp = $handler($reqMap);

        $get       = $this->coreGet();
        $phelToPhp = $this->phelToPhp();

        $status  = $get($resp, \Phel::keyword('status'));
        $body    = $get($resp, \Phel::keyword('body'));
        $headers = $get($resp, \Phel::keyword('headers'));

        return new JsonResponse(
            $body === null ? null : $phelToPhp($body),
            is_int($status) ? $status : Response::HTTP_OK,
            $this->headersToPhp($headers, $phelToPhp),
        );
    }

    private function handler(): callable
    {
        if ($this->rootHandler !== null) {
            return $this->rootHandler;
        }
        if (!self::$booted) {
            Phel::bootstrap($this->projectRoot);
            Phel::run($this->projectRoot, 'app.app');
            self::$booted = true;
        }

        $registry   = Registry::getInstance();
        $reqFromMap = $registry->getDefinition('phel.http', 'request-from-map');
        $rootApp    = $registry->getDefinition('app.app', 'app');

        if (!is_callable($reqFromMap) || !is_callable($rootApp)) {
            throw new RuntimeException('Phel root handler or request-from-map not found');
        }

        return $this->rootHandler = static fn (mixed $reqMap) => $rootApp($reqFromMap($reqMap));
    }

    private function systemMap(): mixed
    {
        if ($this->systemBuilder === null) {
            $build = Registry::getInstance()->getDefinition('app.system', 'build');
            if (!is_callable($build)) {
                throw new RuntimeException('app.system/build not found');
            }
            $this->systemBuilder = $build;
        }
        return ($this->systemBuilder)($this->conn);
    }

    private function coreGet(): callable
    {
        return $this->coreGet ??= Registry::getInstance()->getDefinition('phel.core', 'get');
    }

    private function phelToPhp(): callable
    {
        return $this->phelToPhp ??= Registry::getInstance()->getDefinition('phel.core', 'phel->php');
    }

    private function parsedBody(Request $request): mixed
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $request->request->all() ?: null;
        }
    }

    /** @return array<string, string> */
    private function headersToPhp(mixed $headers, callable $phelToPhp): array
    {
        if ($headers === null) {
            return [];
        }
        $php = $phelToPhp($headers);
        if (!is_array($php) && !$php instanceof \Traversable) {
            return [];
        }
        $out = [];
        foreach ($php as $name => $value) {
            $out[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        return $out;
    }
}
