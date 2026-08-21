# Schema naming — Phase 2 backlog

Phase 1 (done): `folio_ledger.category` → `payment_category`, `transaction_ref` → `transaction_id`. Keep `display_id` (`RCPT-…`) separate from `transaction_id` (gateway/idempotency). Finance keeps its own `display_id` (`TXN-…`).

Do **not** merge folio `display_id` with `transaction_id`. Folio amount is **signed**; finance/city amounts are **unsigned** + `type` — do not rename `amount` blindly.

## Follow-up P0 (structural) — done in code

| Item | Status |
|------|--------|
| `system_settings` PK | Done — migration `043` |
| Gateway credentials ×3 | Done — `payment_gateway_configs` canonical |
| WA automation dual-write | Done — `automation_rules` SoT |

## P1 — done in code

| Item | Status |
|------|--------|
| `finance_transactions.category` | **Leave name** |
| `folio_ledger.entry_kind` | Done — migration `044`; tender in `payment_method` |

## P2 — done in code

| Item | Status |
|------|--------|
| `phone` vs `phone_number` | **Leave** |
| `error_logs.category` | **Leave** |
| `city_ledger` / `wa_messages` `property_id` NOT NULL | Done — migration `045` |
| Drop WA / Razorpay settings fallbacks | Done |

## P3 — done in code

| Item | Status |
|------|--------|
| `automation_rules.deleted_at` | Done — migration `046`; soft-delete + `deleted_at IS NULL` filters |
| Drop `properties.razorpay_*` | Done — migration `046` + schema_master |
| Drop `folio_ledger.transaction_type` | Done — migration `046`; PHP uses `entry_kind` only |
| Archive `wa_automations` | Done — renamed to `wa_automations_archive` in `046` |

## Amount conventions (document only)

- `folio_ledger.amount` — signed (charges +, payments −, refunds + with `is_refund`)
- `finance_transactions.amount` — always ≥0; direction via `type` income/expense
- `city_ledger.amount` — always ≥0; direction via `type` charge/payment

## Ops flags

- `GUEST_TOKEN_ALLOW_LEGACY=0` — reject legacy portal HMAC tokens (v2 only)

## Deploy

- Run migrations **042–046** on Hostinger before/with this release
- Razorpay keys must live in **payment_gateway_configs** (045/046 promote from settings)
- After 046, `wa_automations` is archived as `wa_automations_archive` (read-only history; safe to drop later)
- Folio column `transaction_type` is removed — deploy app code with `046` together
