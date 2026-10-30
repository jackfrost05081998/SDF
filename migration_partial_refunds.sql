-- ============================================================
-- Beanson Brew Cafe — Partial (line-item) refunds migration
-- Run this ONCE in phpMyAdmin (SQL tab) before uploading the
-- updated PHP/JS/CSS files. It is safe to run on the live data:
--   * It only ADDS a new table and RELAXES the status column type.
--   * Existing "completed" / "refunded" receipts keep their values.
-- ============================================================

-- 1) Allow a third receipt status, "partially_refunded".
--    Converting the column to VARCHAR removes any ENUM restriction
--    so the new status value can be stored. Existing values are kept.
ALTER TABLE `orders`
    MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'completed';

-- 2) Line-item refund ledger. One row per refunded line, per refund
--    event, so a receipt can be refunded a piece at a time over time.
--    Key columns are BIGINT UNSIGNED to match orders.id / order_items.id
--    / users.id.
CREATE TABLE IF NOT EXISTS `refund_items` (
    `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`       BIGINT(20) UNSIGNED NOT NULL,
    `order_item_id`  BIGINT(20) UNSIGNED NOT NULL,
    `quantity`       INT NOT NULL,
    `amount`         DECIMAL(10,2) NOT NULL,
    `reason_code`    VARCHAR(40) NOT NULL,
    `reason_note`    VARCHAR(500) NOT NULL,
    `authorized_by`  BIGINT(20) UNSIGNED NOT NULL,
    `authorized_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_refund_items_order` (`order_id`),
    KEY `idx_refund_items_item` (`order_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Beanson Brew Cafe — Show which receipt each sold line belongs to
-- ============================================================
-- Adds a receipt_no column directly onto order_items so every sold
-- line shows its parent receipt at a glance. The value repeats across
-- the lines of the same sale (that is expected and fine): all lines of
-- one receipt carry that receipt's number. The order_id foreign key is
-- still the authoritative link; this column is a readable convenience.

-- 3) Add the column (nullable so the ALTER succeeds on existing rows).
ALTER TABLE `order_items`
    ADD COLUMN `receipt_no` VARCHAR(40) NULL AFTER `order_id`;

-- 4) Backfill every existing line with its parent receipt's number.
UPDATE `order_items` oi
    INNER JOIN `orders` o ON o.id = oi.order_id
    SET oi.receipt_no = o.receipt_no;

-- 5) Index it so lookups / grouping by receipt_no stay fast.
ALTER TABLE `order_items`
    ADD KEY `idx_order_items_receipt_no` (`receipt_no`);
