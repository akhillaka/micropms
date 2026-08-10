# Codebase Audit & Risk Report

## 1. Concurrency & Race Conditions
* **Double Booking Risks:** The database schema has an index `idx_collision_guard (room_id, check_in, check_out, payment_status)`, but standard MySQL `DATETIME` comparisons without strict row-level locking (`SELECT ... FOR UPDATE`) or serializable isolation levels make the system vulnerable to race conditions under high concurrency.
* **Inventory Issues:** POS/inventory stock updates (`pos_inventory`) lack explicit optimistic/pessimistic locking, risking overselling items.

## 2. Architectural Flaws
* **Tight Coupling (PHP + HTML):** Files like `booking_wizard.php` heavily mix backend database queries, business logic, and UI rendering (HTML/Tailwind). This makes the code hard to test, maintain, and scale.
* **Timezone Handling:** Check-in and check-out times are stored as `DATETIME` without timezone awareness (`TIMESTAMPTZ` equivalent). For a multi-tenant SaaS, varying property timezones will cause severe calculation bugs.
* **Global State & DB Instantiation:** The Database singleton is repeatedly instantiated (`Database::getInstance()->getConnection()`) throughout helper classes (`PricingEngine`, `NotificationRelay`), creating tight coupling to the database layer.

## 3. Memory & Error Handling Risks
* **Shutdown Functions:** `ApiHandler::run` registers a shutdown function (`register_shutdown_function`) to commit database transactions. If the PHP script dies unexpectedly (e.g., memory limit exceeded), this could lead to inconsistent transaction states or zombie locks.
* **cURL Resource Leaks:** In `NotificationRelay.php`, the Telegram/WhatsApp cURL handlers have inconsistent `curl_close()` implementations, particularly inside `catch` blocks, which can lead to resource leaks over time.

## 4. Security & Isolation Risks
* **SQL Injection Vulnerabilities:** While PDO prepared statements are mostly used, there are instances of inline variable interpolation (e.g., `booking_wizard.php` queries `system_settings` using `" . (int)$propertyId`). While the `(int)` cast mitigates immediate risk, it breaks security best practices.
* **Multi-Tenant Isolation:** `property_id` is appended as a foreign key to tables, and `SaaSMiddleware::resolveAndGuardTenant($db)` is used. However, tenant isolation relies entirely on application-level `WHERE property_id = ?` clauses. If a developer forgets the clause, data leaks across tenants. (PostgreSQL Row-Level Security is a strongly recommended upgrade).

## 5. State Management
* Idempotency implementation relies on storing raw JSON responses in the `idempotency_keys` table.
* Background jobs (`jobs_queue`) lack a robust dead-letter queue mechanism for failed webhooks (WhatsApp/Telegram), risking infinite retry loops or silent failures.
