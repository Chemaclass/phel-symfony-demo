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

## License

MIT.
