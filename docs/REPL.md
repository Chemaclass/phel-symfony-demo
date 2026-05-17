# REPL cookbook

PHP devs reach for re-run. Clojure devs reach for the REPL. The REPL is your live workspace — definitions stay loaded, you redefine and retry without restart.

## Start

```bash
make repl
```

You land in `user>` ns. From here, require any project namespace:

```clojure
(require 'app.handlers :as hdl)
(require 'app.validation :as v)
(require 'app.persistence :as db)
(require 'app.system :as sys)
```

## Drive a handler with a stub system

No HTTP, no Symfony, no Doctrine — call the handler with a literal request map:

```clojure
(hdl/list-users {:attributes {:conn :fake}})
;; => Will fail at db/all-users — :fake isn't a real Connection.

;; Stub the persistence boundary too:
(require 'phel.mock :refer [with-mocks mock])

(with-mocks [db/all-users (mock [{:id 1 :email "a@x" :name "A"}])]
  (hdl/list-users {:attributes {:conn :fake}}))
;; => {:status 200 :body [{:id 1 :email "a@x" :name "A"}]}
```

## Iterate on validation rules live

```clojure
(def schema {"email" [:required :email] "name" [:required]})

(v/validate schema {})
;; => {:tag :error :errors {"email" "..." "name" "..."}}

(v/validate schema {"email" "x" "name" "ok"})
;; => {:tag :error :errors {"email" "email is invalid"}}

(v/validate schema {"email" "a@b" "name" "Ada"})
;; => {:tag :ok :value {...}}
```

Edit `app.validation`, then reload:

```clojure
(require 'app.validation :reload)
```

Retry the same expression. No process restart.

## Inspect the route table

```clojure
(require 'app.app)
app.app/app
;; => the compiled handler fn

;; The routes are a literal — read them as data:
(require 'phel.router :as r)
```

## Hit the real database

```clojure
(use 'doctrine\\DBAL\\DriverManager)
(def conn (php/:: Doctrine\DBAL\DriverManager getConnection
            (php-associative-array
              "driver" "pdo_sqlite"
              "path"   "var/data.sqlite")))

(db/all-users conn)
;; => [{:id 1 :email "ada@example.com" :name "Ada Lovelace"} ...]

(db/find-user conn 1)
;; => {:tag :ok :user {...}}

(db/find-user conn 999)
;; => {:tag :not-found}
```

## Tips

- **Reload is cheap.** Whenever you save a `.phel` file, `(require 'app.handlers :reload)` picks it up. No `cache:clear`.
- **`pp` for pretty-print.** `(pp (db/all-users conn))` for readable map output.
- **`doc` for help.** `(doc get-in)` shows usage and arity.
- **History is local.** REPL writes `~/.phel_repl_history`. Up-arrow recalls last expression.
- **Multi-line expressions.** Open paren counter at the prompt; press enter when balanced.

## Common pitfalls

- After editing a `.phel` file, **reload the namespace**, not just the symbol. `(require 'app.handlers :reload)` — not `(use ...)` again.
- If a redefinition references a private symbol from another ns, reload that ns too. Order matters when defs span files.
- Stale `def`s live in the REPL until you redefine or restart. If a fn behaves like a previous version, `:reload` first.
- `(require 'app.handlers :reload-all)` reloads transitive requires too — heavier hammer when single-file reload isn't enough.
