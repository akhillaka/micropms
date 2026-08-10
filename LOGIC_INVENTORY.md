# Business Logic Inventory (MicroPMS)

## 1. Reservation State Transitions
* **Booking Status (`booking_status`):** `booked` -> `checked_in` -> `checked_out` -> `cancelled`.
* **Payment Status (`payment_status`):** `pending_hold` -> `completed_paid` -> `cancelled`.
* **Room States:** `clean` -> `dirty` -> `out_of_order`.

## 2. Billing & Pricing Engine
* **Pricing Model:** Relies on a sliding 24-hour scale (`sliding_rates` table).
* **Calculations:** 
  * Total duration is calculated in hours.
  * Base rate applies to full 24-hour chunks.
  * Residual hours (< 24) are priced dynamically using sliding rate data (e.g., 2H rate, 4H rate).
* **Taxes & Tax Preferences:** Bookings support `exclusive`, `inclusive`, or `exempt` tax settings.
* **Folio Ledger:** Transactions are split into buckets (`main`, `incidentals`). Supported types: `online`, `cash`, `card`, `upi`, `bank_transfer`, `payment`, `ROOM_CHARGE`, `INCIDENTAL`, `pos_order`, `pos_refund`.

## 3. Date, Time & 24-Hour Stay Mechanics
* **Storage:** Check-in and check-out dates are stored as `DATETIME` in MySQL.
* **UI Behavior:** In the Booking Wizard, default stays are computed (e.g., 3-hour minimum stay) and time selectors round to 30-minute intervals.
* **Overtime / Residual:** Stays exceeding 24-hour boundaries roll over into hourly residual charges rather than enforcing strict daily cut-offs.

## 4. Housekeeping & Maintenance
* **Housekeeping Log:** Tracks `in_progress`, `cleaned`, `inspected_ready`.
* **Checklist:** Enforces mandatory checks (e.g., Replace Linen, Sanitize Bathroom).
* **Maintenance Blocking:** `room_maintenance` table supports date-range out-of-order blocking.

## 5. Night Audit Routine
* **Execution:** System runs a cron job (typically 02:00 AM).
* **Actions:**
  * Computes total, occupied, arrivals, departures.
  * Optionally triggers `auto_checkout` for overdue guests.
  * Marks departed rooms as `dirty`.
  * Logs revenue metrics (collected vs. pending).
  * Sends automated reports via Telegram/WhatsApp.

## 6. Automations & Webhooks (WhatsApp/Telegram)
* **Events Supported:** `booking_confirmed`, `guest_check_in`, `guest_check_out`, `booking_cancelled`, `payment_link`, `guest_invoice`.
* **Queueing:** Messages are pushed to `jobs_queue` with a JSON payload and processed asynchronously. Idempotency is verified via `wa_delivery_logs`.
