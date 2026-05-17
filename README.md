# phel-symfony-demo

A minimal but real Symfony app whose application layer is written in [Phel](https://phel-lang.org). Ring-style: handlers are pure functions over plain request/response maps; routing is data; Symfony stays at the edge as the HTTP container.

Companion to the gist: <https://gist.github.com/Chemaclass/ceeed2eb4562186e0c968d5c70cb727b>.

## Architecture

```
Symfony FrameworkBundle (kernel, DI, one catch-all route)
   |
   v
App\Phel\PhelApp (adapter, ~90 LOC)
   |   - Symfony Request -> Phel map (keyword keys)
   |   - Phel response  -> Symfony JsonResponse
   v
phel.router/handler  ::  (fn [request] response)
   |
   +- middleware (wrap-errors, wrap-json-response)
   +- route table (data)
   +- handler fns (app.handlers)
   +- persistence (app.persistence, wraps DBAL)
   v
SQLite via Doctrine DBAL (no ORM)
```

## Layout

```
phel-config.php                Phel project config (src dirs, main ns)
config/services.yaml           Symfony DI (DBAL Connection, PhelApp)
src/
  Controller/PhelController.php   Catch-all route, delegates to PhelApp
  Phel/
    PhelApp.php                   Adapter: Request <-> Phel map / response
    app.phel                      Root: router + middleware = handler
    handlers.phel                 Pure request -> response fns
    persistence.phel              DBAL wrapper, returns plain maps
bin/db-setup.php                  SQLite seed script
```

## Run

```bash
composer install
php bin/db-setup.php
php -S 127.0.0.1:8765 -t public public/index.php
```

Endpoints:

```bash
curl http://127.0.0.1:8765/users
curl http://127.0.0.1:8765/users/1
curl -X POST -H 'Content-Type: application/json' \
     -d '{"email":"grace@example.com","name":"Grace Hopper"}' \
     http://127.0.0.1:8765/users
curl -X DELETE http://127.0.0.1:8765/users   # 405 method not allowed
curl http://127.0.0.1:8765/nope              # 404 not found
```

Expected results:

| Verb | Path | Status | Body |
|------|------|--------|------|
| GET  | /users      | 200 | list of users |
| GET  | /users/1    | 200 | single user |
| GET  | /users/999  | 404 | `{"error":"not found"}` |
| POST | /users      | 201 | created user |
| POST | /users (invalid body) | 422 | `{"error":"email and name required"}` |
| DELETE | /users    | 405 | `"Method not allowed"` |
| GET  | /nope       | 404 | `"Not found"` |

## DX gotchas worth knowing

These tripped me up while building this demo. They are now documented inline in the adapter and in the gist.

1. **Two `Phel` classes.** `\Phel` (root namespace, `vendor/phel-lang/phel-lang/src/Phel.php`) exposes static helpers like `\Phel::map(...)`, `\Phel::keyword(...)`. `\Phel\Phel` (`src/php/Phel.php`) is the bootstrap entry point (`Phel::bootstrap`, `Phel::run`). Easy to confuse.
2. **Phel maps are not `JsonSerializable`.** Call `(phel->php data)` before handing to `JsonResponse`, otherwise `json_encode` returns `{}` or throws. The adapter resolves `phel.core/phel->php` once at boot and applies it to every response body.
3. **PHP assoc array != Phel keyword-keyed map.** `phel.http/request-from-map` destructures by `Keyword` keys, so building the request envelope with `['method' => ...]` silently breaks (the destructure returns `nil` for every field). Build with `\Phel::map(\Phel::keyword('method'), ..., ...)`.
4. **`(php/array ...)` is positional, not associative.** For DBAL `insert(table, data)` use `(php-associative-array "email" v "name" v)`. `(php/array "email" v ...)` produces `[0=>"email", 1=>v, ...]` and Doctrine generates broken SQL like `near "0": syntax error`.
5. **Cache after edits.** Phel caches compiled PHP under `.phel/cache/`. After editing a `.phel` file, run `./vendor/bin/phel cache:clear` (or symlink it to a Symfony cache:clear hook).
6. **Don't lint the whole `src/Phel/` dir.** It loads each file in isolation; transitive `:require` then triggers duplicate-symbol errors. Lint the entry namespace (`./vendor/bin/phel lint src/Phel/app.phel`) and rely on its requires.

## Why this architecture?

- Handlers are pure `(fn [req] resp)`. Test by calling with a map literal, no HTTP needed.
- Routes are a data table. Diff-friendly. No annotation hunting.
- DB returns plain maps. No ORM, no proxies. Tests use plain maps.
- Symfony's profiler still works for the adapter call. Phel side use `phel profile` or REPL.
- One adapter. ~90 lines. Migrate routes from PHP to Phel incrementally.

## Thinking in Phel from a Symfony POV

If you live in Symfony, you write controllers (classes), inject services, return `Response`. Phel flips the mental model:

| Symfony idea | Phel equivalent |
|---|---|
| Controller class + method | A function `(fn [req] resp)` |
| `Request` object | A map with keyword keys (`:method`, `:uri`, `:parsed-body`, ...) |
| `Response` object | A map `{:status 200 :body ... :headers {...}}` |
| Routing attributes (`#[Route]`) | A vector of `[path opts]` pairs (data) |
| Middleware (`EventSubscriber`) | A function `(fn [handler req] ...)` wrapping the next handler |
| Service container injection | Values placed under `:attributes` in the request map |
| DTO / entity | A plain map. No class. No proxy. |
| ORM repository | A namespace of functions over `conn` (DBAL stays underneath) |

### The four rules of thumb

1. **Stay in data.** Pass maps, not objects. Routes are data. Responses are data. Tests are data literals.
2. **Handlers are pure.** Side effects (DB, clock, HTTP) come in as dependencies under `:attributes`. Stub at that boundary with `phel.mock/with-mocks` (see `tests/Phel/handlers_test.phel`).
3. **Compose with functions.** Need cross-cutting behavior? Wrap the handler (`wrap-json-response`, `wrap-errors` in `app.phel`). No annotations, no listeners.
4. **Symfony stays at the edge.** Symfony owns HTTP, DI, config. Phel owns routing, handlers, business logic. The adapter (`PhelApp.php`) translates once at each end.

### Where to look first

| You want to ... | Read |
|---|---|
| see the HTTP entry point | `src/Controller/PhelController.php` (5 lines) |
| see the adapter (Symfony → Phel → Symfony) | `src/Phel/PhelApp.php` |
| see how routes are declared | `src/Phel/app.phel` |
| see a handler | `src/Phel/handlers.phel` |
| see the DB layer | `src/Phel/persistence.phel` |
| see pure handler tests (no HTTP) | `tests/Phel/handlers_test.phel` |
| see full feature tests (HTTP → DB) | `tests/Controller/PhelControllerTest.php` |

### Test pyramid

Three levels — pick the cheapest one that proves what you care about:

1. **Phel unit** (`phel test`) — call the handler with a literal map, mock `db/*`. Sub-second feedback. Best for branching logic.
2. **PHP integration** — instantiate `PhelApp`, hand it a `Request`. Catches adapter bugs.
3. **HTTP feature** (`phpunit` + `WebTestCase`) — boot the kernel, hit the URL, assert on JSON. Catches routing, middleware, DI wiring.

### When to write PHP vs Phel

- New endpoint, business logic, validation, transformation → **Phel**.
- DI wiring, infra adapters, third-party SDK integration, framework extension points → **PHP**.
- Migrating an existing controller? Move the *body* into a Phel handler; keep the PHP class as a one-line delegation until you delete the catch-all you don't need anymore.

## Running tests

```bash
composer test          # phel cache:clear + phel test + phpunit
vendor/bin/phel test   # Phel unit tests only
php bin/phpunit        # HTTP feature tests only
```

## License

MIT.
