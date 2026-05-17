# phel-symfony-demo

Minimal real Symfony app whose application layer is written in [Phel](https://phel-lang.org). Symfony stays at the edge (HTTP, DI, config). Phel owns routing, handlers, business logic. Adapter ~120 LOC.

> Companion gist: <https://gist.github.com/Chemaclass/ceeed2eb4562186e0c968d5c70cb727b>

## Stack

| Component | Version | Why |
|---|---|---|
| PHP        | `>=8.4`        | matches Phel's minimum |
| Phel       | `^0.38`        | latest stable |
| Symfony    | `7.4.*` (LTS)  | 3-year support window |
| Doctrine DBAL | `^4`        | DB without an ORM |
| PHPUnit    | `^13`          | feature tests |

## Quickstart (60 seconds)

```bash
git clone https://github.com/Chemaclass/phel-symfony-demo && cd phel-symfony-demo
make install          # composer install + seeds SQLite (idempotent)
make serve            # http://127.0.0.1:8765
```

Hit it:

```bash
curl http://127.0.0.1:8765/users
curl http://127.0.0.1:8765/users/1
curl -X POST -H 'Content-Type: application/json' \
     -d '{"email":"grace@example.com","name":"Grace Hopper"}' \
     http://127.0.0.1:8765/users
```

| Verb | Path | Status | Body |
|---|---|---|---|
| GET    | /users      | 200 | list |
| GET    | /users/1    | 200 | user |
| GET    | /users/999  | 404 | `{"error":"not found"}` |
| POST   | /users      | 201 | created |
| POST   | /users (invalid) | 422 | `{"error":"email and name required"}` |
| DELETE | /users      | 405 | method not allowed |
| GET    | /nope       | 404 | not found |

## Make targets

```
make install      composer install + seed DB
make serve        PHP dev server on 127.0.0.1:8765
make test         phel test + phpunit
make phel-test    Phel unit tests
make phpunit      HTTP feature tests
make db-reset     drop and recreate SQLite
make cache-clear  clear Phel + Symfony caches
make lint         lint Phel entrypoint
```

## Architecture

```
Symfony FrameworkBundle (kernel, DI, one catch-all route)
   |
   v
App\Phel\PhelApp  (adapter, ~120 LOC)
   |   Symfony Request -> Phel map (keyword keys)
   |   Phel response   -> Symfony JsonResponse
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
bin/db-setup.php                  SQLite seed script (idempotent; --reset)
```

## Where to look first

| You want to ... | Read |
|---|---|
| HTTP entry point          | `src/Controller/PhelController.php` (5 lines) |
| Adapter (Symfony↔Phel)    | `src/Phel/PhelApp.php` |
| Routes                    | `src/Phel/app.phel` |
| A handler                 | `src/Phel/handlers.phel` |
| DB layer                  | `src/Phel/persistence.phel` |
| Pure handler tests        | `tests/Phel/handlers_test.phel` |
| HTTP feature tests        | `tests/Controller/PhelControllerTest.php` |

## Symfony POV → Phel cheatsheet

| Symfony idea | Phel equivalent |
|---|---|
| Controller class + method | `(fn [req] resp)` |
| `Request` object          | map with keyword keys (`:method`, `:uri`, `:parsed-body`, ...) |
| `Response` object         | map `{:status 200 :body ... :headers {...}}` |
| Routing attributes        | vector of `[path opts]` pairs (data) |
| Middleware / EventSubscriber | `(fn [handler req] ...)` wrapping next handler |
| Service container         | values under `:attributes` in request map |
| DTO / entity              | plain map. No class. No proxy. |
| ORM repository            | namespace of fns over `conn` (DBAL underneath) |

### Four rules of thumb

1. **Stay in data.** Maps, not objects. Routes are data. Responses are data. Tests are data literals.
2. **Handlers are pure.** Side effects come in as deps under `:attributes`. Stub at that boundary with `phel.mock/with-mocks`.
3. **Compose with functions.** Cross-cutting? Wrap the handler. No annotations, no listeners.
4. **Symfony at the edge.** Symfony owns HTTP, DI, config. Phel owns routing, handlers, business logic. Adapter translates once at each end.

### When to write PHP vs Phel

- New endpoint, business logic, validation, transformation → **Phel**
- DI wiring, infra adapters, third-party SDK, framework extension points → **PHP**
- Migrating an existing controller? Move the *body* into a Phel handler; keep the PHP class as a one-line delegation.

## Add a new endpoint in 4 steps

1. **Handler** in `src/Phel/handlers.phel`:

   ```clojure
   (defn ping [req]
     {:status 200 :body {:pong (php/time)}})
   ```

2. **Route** in `src/Phel/app.phel`:

   ```clojure
   ["/ping" {:get {:handler app.handlers/ping}}]
   ```

3. **Test** in `tests/Phel/handlers_test.phel`:

   ```clojure
   (deftest ping-returns-200
     (is (= 200 (get (hdl/ping {}) :status))))
   ```

4. `make cache-clear && make test`. Done.

## Test pyramid

Three levels — pick the cheapest one that proves what you care about:

1. **Phel unit** (`make phel-test`) — call handler with literal map, mock `db/*`. Sub-second feedback. Best for branching logic.
2. **PHP integration** — instantiate `PhelApp`, hand it a `Request`. Catches adapter bugs.
3. **HTTP feature** (`make phpunit`) — boot kernel, hit URL, assert JSON. Catches routing, middleware, DI wiring.

## DX gotchas

These bit during build. Documented inline in the adapter too.

1. **Two `Phel` classes.** `\Phel` (root ns, `vendor/.../src/Phel.php`) exposes helpers like `\Phel::map(...)`, `\Phel::keyword(...)`. `\Phel\Phel` (`src/php/Phel.php`) is the bootstrap entry (`Phel::bootstrap`, `Phel::run`).
2. **Phel maps are not `JsonSerializable`.** Call `(phel->php data)` before handing to `JsonResponse`, else `json_encode` returns `{}` or throws. Adapter resolves `phel.core/phel->php` once at boot.
3. **PHP assoc array != Phel keyword-keyed map.** `phel.http/request-from-map` destructures by `Keyword` keys — building the envelope with `['method' => ...]` silently breaks. Use `\Phel::map(\Phel::keyword('method'), ..., ...)`.
4. **`(php/array ...)` is positional, not associative.** For DBAL `insert(table, data)` use `(php-associative-array "email" v "name" v)`. `(php/array "email" v ...)` produces `[0=>"email", 1=>v, ...]` → broken SQL.
5. **Cache after edits.** Phel caches compiled PHP under `.phel/cache/`. After editing a `.phel` file: `make cache-clear`.
6. **Don't lint whole `src/Phel/` dir.** It loads each file in isolation; transitive `:require` triggers duplicate-symbol errors. Lint the entry namespace: `make lint`.

## License

MIT.
