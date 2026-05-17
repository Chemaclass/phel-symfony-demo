# Migrating a Symfony controller to a Phel handler

The seam between PHP and Phel is the `PhelApp` adapter. A migration is never a big-bang rewrite — you move one endpoint at a time, keeping the PHP controller class as a thin delegation until you're ready to delete it.

## Mental model

```
Before:                                 After:
                                        
[Route]   PHP class                     [Route]  PHP class (1 line)
   |         |                             |        |
   |         v                             |        v
   |     business logic                    |    PhelApp::handle
   |         |                             |        |
   |         v                             |        v
   |     Doctrine / services               |    Phel handler  (pure)
   |                                       |        |
   |                                       |        v
   |                                       |    app.persistence (PHP boundary)
   |                                       |        |
   |                                       |        v
   |                                       |    Doctrine / services
```

The PHP class survives because Symfony's DI, attribute-based routing, and tests already reference it. You don't change call sites; you only change what the class does internally.

## Step-by-step

Say you have:

```php
#[Route('/orders/{id}', methods: ['GET'])]
final class ShowOrderController
{
    public function __construct(
        private readonly OrderRepository $repo,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(int $id): JsonResponse
    {
        $this->logger->info('show order', ['id' => $id]);
        $order = $this->repo->find($id);
        if (!$order) {
            return new JsonResponse(['error' => 'not found'], 404);
        }
        return new JsonResponse($order->toArray());
    }
}
```

### 1. Expose deps under `:attributes`

Add what the handler needs to `app.system/build`:

```clojure
;; src/Phel/system.phel
(defn build [conn order-repo logger]
  {:conn       conn
   :clock      (fn [] (php/time))
   :order-repo order-repo
   :logger     logger})
```

Update `PhelApp.php` to pass the new deps, and `config/services.yaml` to inject them into `PhelApp`. (One change per dep — Symfony's autowiring usually picks them up.)

### 2. Write the handler — pure, branching on data

```clojure
;; src/Phel/handlers.phel
(defn show-order [req]
  (let [repo   (get-in req [:attributes :order-repo])
        logger (get-in req [:attributes :logger])
        id     (php/intval (get-in req [:attributes :match :path-params :id]))
        _      (php/-> logger (info "show order" (php-associative-array "id" id)))
        order  (php/-> repo (find id))]
    (if order
      {:status 200 :body (php/-> order (toArray))}
      {:status 404 :body {:error "not found"}})))
```

Better — push the PHP interop into a boundary namespace:

```clojure
;; src/Phel/orders.phel
(ns app.orders)

(defn find-by-id [repo id]
  (if-let [order (php/-> repo (find id))]
    {:tag :ok :order (php/-> order (toArray))}
    {:tag :not-found}))
```

```clojure
;; src/Phel/handlers.phel
(defn show-order [req]
  (let [repo (get-in req [:attributes :order-repo])
        id   (php/intval (get-in req [:attributes :match :path-params :id]))
        r    (orders/find-by-id repo id)]
    (case (get r :tag)
      :ok        {:status 200 :body (get r :order)}
      :not-found {:status 404 :body {:error "not found"}})))
```

The handler is now testable with a literal `{:tag :ok :order ...}`.

### 3. Add the route to `app.phel`

```clojure
["/orders/{id}" {:get {:handler app.handlers/show-order}}]
```

### 4. Shrink the PHP controller to a one-line delegation

```php
#[Route('/orders/{id}', methods: ['GET'], priority: -50)]
final class ShowOrderController
{
    public function __construct(private readonly PhelApp $app) {}
    public function __invoke(Request $request): Response { return $this->app->handle($request); }
}
```

Or — if the catch-all `PhelController` already covers the path — delete the specific class entirely. The catch-all is registered with `priority: -100`, so any explicit route wins.

### 5. Test at the cheapest level that proves the change

```clojure
(deftest show-order-returns-404-when-missing
  (with-mocks [orders/find-by-id (mock {:tag :not-found})]
    (let [resp (hdl/show-order
                 {:attributes {:order-repo :stub
                               :match {:path-params {:id "42"}}}})]
      (is (= 404 (get resp :status))))))
```

Keep the existing PHPUnit feature test — it now exercises the Phel path end-to-end without changes.

## What to migrate first

| Endpoint shape | Migration priority |
|---|---|
| Pure transformation, no side effects | 🟢 first — easy win, biggest readability gain |
| CRUD with one repository | 🟢 second — `app.persistence` pattern fits |
| Multi-service orchestration | 🟡 only after `app.system` knows all the deps |
| File uploads, streaming, SSE | 🔴 stay in PHP — Phel buys you nothing here |
| Auth filters, security firewalls | 🔴 stay in Symfony's `security.yaml` |

## What you keep from Symfony

- DI container, autowiring, service tags
- Routing attributes (when you want explicit per-controller routes)
- Console commands, Messenger workers, profiler, debug toolbar
- `WebTestCase` feature tests (they keep passing)
- `composer require` ecosystem (every Composer package is callable from Phel)

## What you stop writing

- Form types and DTO classes (use schema-as-data validation)
- Entity classes and ORM mappings (return plain maps from a boundary ns)
- Event subscribers for cross-cutting concerns (use middleware)
- Custom exception hierarchies for control flow (return result-tagged maps)

## Rollback

Each step is one commit. If a migrated endpoint regresses: revert that commit; the original PHP class returns. No global lock-in.
