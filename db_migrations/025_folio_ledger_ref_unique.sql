-- Prevent duplicate payment posts for the same booking + gateway/payment ref.
-- Empty refs are stored as NULL so cash lines without a ref do not collide.

UPDATE `folio_ledger`
   SET `transaction_ref` = NULL
 WHERE `transaction_ref` = '';

ALTER TABLE `folio_ledger`
  ADD UNIQUE KEY `uq_folio_booking_ref` (`booking_id`, `transaction_ref`);
