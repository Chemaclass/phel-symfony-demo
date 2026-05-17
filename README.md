# phel-symfony-demo

Minimal real Symfony app whose application layer is written in [Phel](https://phel-lang.org). Symfony stays at the edge (HTTP, DI, config). Phel owns routing, handlers, business logic. Adapter ~130 LOC.

> Companion gist: <https://gist.github.com/Chemaclass/ceeed2eb4562186e0c968d5c70cb727b>

## Contents

- [Stack](#stack)
- [Quickstart (60 seconds)](#quickstart-60-seconds)
- [Make targets](#make-targets)
- [Architecture](#architecture)
- [Request lifecycle](#request-lifecycle) — trace one HTTP call top to bottom
- [Layout](#layout)
- [Where to look first](#where-to-look-first)
- [Symfony POV → Phel cheatsheet](#symfony-pov--phel-cheatsheet)
- [FP practices (Clojure-inspired, PHP-friendly)](#fp-practices-clojure-inspired-php-friendly)
- [REPL-driven workflow](#repl-driven-workflow-the-biggest-mindset-shift-from-php)
- [Use full PHP ecosystem from Phel](#use-full-php-ecosystem-from-phel)
- [Add a new endpoint in 4 steps](#add-a-new-endpoint-in-4-steps)
- [Test pyramid](#test-pyramid)
- [DX gotchas](#dx-gotchas)
- [FAQ](#faq)
- [Further reading](#further-reading) — `docs/` deep dives

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
| POST   | /users (invalid) | 422 | `{"errors":{"name":"name is invalid"}}` |
| DELETE | /users      | 405 | method not allowed |
| GET    | /nope       | 404 | not found |

## Make targets

```
make install      composer install + seed DB
make serve        PHP dev server on 127.0.0.1:8765
make repl         Phel REPL (require namespaces, redefine, retest)
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
App\Phel\PhelApp  (adapter, ~130 LOC)
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

## Request lifecycle

Concrete trace of `GET /users/1`:

```
1. nginx/php-fpm/dev-server hits public/index.php
2. Symfony Kernel boots, matches catch-all route in PhelController
3. PhelController::__invoke($request) calls PhelApp::handle($request)
4. PhelApp builds the Phel request map:
     {:method "GET"
      :uri "/users/1"
      :headers {...}
      :parsed-body nil
      :attributes {:conn <DBAL\Connection> :clock <fn>}}    <-- from app.system/build
5. Adapter calls (phel.http/request-from-map req-map) to coerce to Phel http request
6. Adapter calls (app.main/app request)
     -> wrap-errors          (try/catch \Throwable)
       -> wrap-json-response (puts content-type header)
         -> phel.router      (matches "/users/{id}", stamps :match under :attributes)
           -> app.handlers/show-user
                let conn = (get-in req [:attributes :conn])
                let id   = 1
                let r    = (db/find-user conn 1)
                  -> (php/-> conn (fetchAssociative ...))   <-- only PHP boundary
                  -> {:tag :ok :user {:id 1 :email "..." :name "..."}}
                case :tag = :ok
                  -> {:status 200 :body {:id 1 ...}}
7. wrap-json-response merges {"content-type" "application/json"} into headers
8. wrap-errors passes through (no exception)
9. Adapter reads :status, :body, :headers; runs (phel->php body)
10. Returns Symfony JsonResponse(body, status, headers)
11. Symfony serializes to HTTP response
```

Same path for `POST /users` with validation; the only branch is in `create-user`:

```
parsed-body -> v/validate create-user-schema
  :tag :error -> {:status 422 :body {:errors {...}}}
  :tag :ok    -> db/insert-user! -> db/find-user -> {:status 201 :body ...}
```

## Layout

```
phel-config.php                Phel project config (src dirs, main ns)
config/services.yaml           Symfony DI (DBAL Connection, PhelApp)
src/
  Controller/PhelController.php   Catch-all route, delegates to PhelApp
  Phel/
    PhelApp.php                   Adapter: Request <-> Phel map / response
    main.phel                     Entry ns app.main: router + middleware = handler
    system.phel                   Builds the system map (conn, clock, ...)
    handlers.phel                 Pure request -> response fns
    validation.phel               Schema-as-data validation
    persistence.phel              DBAL wrapper, returns result-tagged maps
bin/db-setup.php                  SQLite seed script (idempotent; --reset)
```

## Where to look first

| You want to ... | Read |
|---|---|
| HTTP entry point          | `src/Controller/PhelController.php` (5 lines) |
| Adapter (Symfony↔Phel)    | `src/Phel/PhelApp.php` |
| Routes                    | `src/Phel/main.phel` |
| System wiring             | `src/Phel/system.phel` |
| A handler                 | `src/Phel/handlers.phel` |
| Validation (schema=data)  | `src/Phel/validation.phel` |
| DB layer (result-tagged)  | `src/Phel/persistence.phel` |
| Pure handler tests        | `tests/Phel/handlers_test.phel` |
| Pure validation tests     | `tests/Phel/validation_test.phel` |
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

## FP practices (Clojure-inspired, PHP-friendly)

This demo aims to give PHP devs Clojure-style code without giving up the PHP ecosystem.

| Practice | Where in demo | Why it matters |
|---|---|---|
| **Functional core, imperative shell** | `handlers.phel` pure; `PhelApp.php` shell | edge does I/O; core is testable in isolation |
| **Data > functions > macros** | routes, schema, system map are data | diffable, inspectable, no DSL to learn |
| **Persistent maps over DTOs/entities** | `db/all-users` returns maps | no proxy, no hydration, no class explosion |
| **Repository = namespace of fns** | `app.persistence` over `conn` | no `interface SomethingRepository` ceremony |
| **Result-tagged returns over exceptions** | `find-user` → `{:tag :ok :user m}` / `{:tag :not-found}` | branch on data with `case`; exceptions only at edge |
| **Side-effecting fns suffixed `!`** | `insert-user!` | reading the call site tells you what mutates |
| **System map (component-lite)** | `app.system/build` | one place wires `conn`, `clock`, future deps; stub with literal map |
| **Schema-as-data validation** | `app.validation` + `create-user-schema` | rules are data; composable; testable as literals |
| **PHP interop walled off** | only `*.persistence`, `*.system`, `PhelApp.php` touch PHP objects | core stays portable; grep `php/` in `handlers.phel` should return 0 |
| **Threading macros** | `wrap-json-response` uses `->` | linear pipelines beat nested calls |

### REPL-driven workflow (the biggest mindset shift from PHP)

PHP devs reach for re-run. Clojure devs reach for the REPL.

```bash
make repl
```

```clojure
(require 'app.handlers :as hdl)
(require 'app.validation :as v)

;; call a handler with literal data — no HTTP, no Symfony
(hdl/list-users {:attributes {:conn :stub}})

;; iterate on validation rules live
(v/validate {"email" [:required :email]} {"email" "x"})
;; => {:tag :error :errors {"email" "email is invalid"}}
```

Edit a fn, `(require ... :reload)`, retry in the same session. No boot, no curl, no kernel cache clear.

### Use full PHP ecosystem from Phel

Phel ↔ PHP interop is two operators:

- `(php/-> obj (method args...))` — instance method call
- `(php/:: Class staticMethod ...)`, `(php/new Class ...)` — class access

So any Composer package works:

```clojure
;; Doctrine DBAL
(php/-> conn (fetchAssociative "SELECT ..." (php/array id)))

;; Symfony Messenger (assume injected under :attributes)
(php/-> (get-in req [:attributes :bus]) (dispatch (php/new App\Message\SendEmail to subject)))

;; any PSR-15 handler, Symfony EventDispatcher, Doctrine ORM, Twig — all callable
```

Rule: keep these calls in the **boundary namespace** (`*.persistence`, `*.io.mailer`, etc.). Handlers stay pure and stub-friendly.

## Add a new endpoint in 4 steps

1. **Handler** in `src/Phel/handlers.phel` (read `clock` from system map, not direct PHP):

   ```clojure
   (defn ping [req]
     (let [now ((get-in req [:attributes :clock]))]
       {:status 200 :body {:pong now}}))
   ```

2. **Route** in `src/Phel/main.phel`:

   ```clojure
   ["/ping" {:get {:handler app.handlers/ping}}]
   ```

3. **Test** in `tests/Phel/handlers_test.phel` (stub `:clock` with a literal fn):

   ```clojure
   (deftest ping-returns-200
     (let [resp (hdl/ping {:attributes {:clock (fn [] 1234567)}})]
       (is (= 200 (get resp :status)))
       (is (= 1234567 (get-in resp [:body :pong])))))
   ```

4. `make cache-clear && make test`. Done.

> Need input validation? Define a schema map in the handler ns and call `(v/validate schema (get req :parsed-body))`. See `create-user` for the pattern.

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

## FAQ

**Q: Why not just use Symfony controllers?**
A: You can. This demo exists because routes-as-data, persistent maps, pure handlers, and REPL-driven feedback are easier to reason about than annotated controllers + entity proxies + listener chains. You also keep Symfony underneath — DI, console, profiler, framework upgrades all work.

**Q: Why not adopt Phel for everything?**
A: Phel is great for pure logic and data shaping. PHP is great for DI wiring, ORM mappings, third-party SDKs that lean on classes/attributes, and operational tooling. Keep each at its strength; the adapter is the seam.

**Q: Performance overhead?**
A: Phel compiles to PHP at build time (cached under `.phel/cache/`). At request time it is plain PHP. The adapter cost is one map allocation per request plus a Registry lookup memoized on first call. Negligible vs DBAL/Twig.

**Q: How do I migrate an existing controller incrementally?**
A: See [`docs/MIGRATION.md`](docs/MIGRATION.md). Short version: PHP class stays as a one-line delegation; move the body into a Phel handler; add the route to `main.phel`; delete the PHP class only when no callers depend on its DI bindings.

**Q: Editor / IDE support?**
A: PHPStorm: install the [Phel plugin](https://plugins.jetbrains.com/plugin/19710-phel). VS Code: the [Phel extension](https://marketplace.visualstudio.com/items?itemName=phel-lang.phel) gives syntax + paren matching. REPL is your real "language server" — it answers questions PHPStan can't.

**Q: Can I use Doctrine ORM, Twig, Messenger, etc.?**
A: Yes — call them via `(php/-> ...)` from a boundary namespace (see [Use full PHP ecosystem from Phel](#use-full-php-ecosystem-from-phel)). Keep handlers pure; pass the services in under `:attributes` via `app.system/build`.

**Q: What about types / static analysis?**
A: Phel has runtime type predicates (`number?`, `string?`, `map?`, ...). For static analysis the demo relies on PHPStan/Psalm only on the PHP side. The Phel side is covered by REPL + tests as data.

## Further reading

| Doc | What's in it |
|---|---|
| [`docs/MIGRATION.md`](docs/MIGRATION.md) | Port a Symfony controller to a Phel handler in 5 steps, without big-bang rewrites |
| [`docs/REPL.md`](docs/REPL.md) | REPL cookbook: inspect routes, reload ns, drive a handler with a stub system |

## License

MIT.
