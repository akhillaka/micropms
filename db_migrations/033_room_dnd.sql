-- Room DND flag for stayover-clean skip. Idempotent.

ALTER TABLE `rooms`
  ADD COLUMN IF NOT EXISTS `dnd` TINYINT(1) NOT NULL DEFAULT 0 AFTER `state`;
