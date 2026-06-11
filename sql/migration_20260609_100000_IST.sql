-- MagDyn — migration_20260609_100000_IST
-- Staging tables for old-inventory transaction / shipment / receipt / PO import.
-- Idempotent: CREATE TABLE IF NOT EXISTS + re-running is a no-op.

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- old_inv_txns
-- One row per inventory_transaction record from the old server.
-- Keyed on old_id (inventory_transaction_id) so import is idempotent.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `old_inv_txns` (
    `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `old_id`              INT UNSIGNED     NOT NULL COMMENT 'inventory_transaction_id on old server',
    `old_transaction_id`  INT UNSIGNED     NOT NULL,
    `item_code`           VARCHAR(100)     NOT NULL DEFAULT '',
    `item_name`           VARCHAR(255)     NOT NULL DEFAULT '',
    `quantity`            FLOAT            NOT NULL DEFAULT 0,
    `txn_type`            VARCHAR(50)      NOT NULL DEFAULT '',
    `txn_date`            DATETIME         NULL     DEFAULT NULL,
    `source_location`     VARCHAR(255)     NOT NULL DEFAULT '',
    `dest_location`       VARCHAR(255)     NOT NULL DEFAULT '',
    `note`                TEXT             NULL,
    `created_by_name`     VARCHAR(120)     NOT NULL DEFAULT '',
    `file_url`            VARCHAR(100)     NOT NULL DEFAULT '',
    `imported_at`         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_old_inv_txns_old_id` (`old_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- old_inv_shipments
-- One row per shipment record from the old server.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `old_inv_shipments` (
    `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `old_shipment_id`     INT UNSIGNED     NOT NULL COMMENT 'shipment_id on old server',
    `old_transaction_id`  INT UNSIGNED     NOT NULL,
    `shipment_number`     BIGINT           NOT NULL,
    `from_company`        VARCHAR(255)     NOT NULL DEFAULT '',
    `to_company`          VARCHAR(255)     NOT NULL DEFAULT '',
    `ship_date`           DATE             NULL     DEFAULT NULL,
    `courier_name`        VARCHAR(255)     NOT NULL DEFAULT '',
    `tracking_number`     VARCHAR(50)      NOT NULL DEFAULT '',
    `shipped`             TINYINT(1)       NOT NULL DEFAULT 0,
    `txn_note`            TEXT             NULL,
    `txn_date`            DATETIME         NULL     DEFAULT NULL,
    `imported_at`         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_old_inv_shipments_id` (`old_shipment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- old_inv_receipts
-- One row per receipt record from the old server.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `old_inv_receipts` (
    `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `old_receipt_id`      INT UNSIGNED     NOT NULL COMMENT 'receipt_id on old server',
    `old_transaction_id`  INT UNSIGNED     NOT NULL,
    `receipt_number`      BIGINT           NOT NULL,
    `from_company`        VARCHAR(255)     NOT NULL DEFAULT '',
    `receipt_date`        DATE             NULL     DEFAULT NULL,
    `due_date`            DATE             NULL     DEFAULT NULL,
    `received_flag`       TINYINT(1)       NOT NULL DEFAULT 0,
    `txn_note`            TEXT             NULL,
    `txn_date`            DATETIME         NULL     DEFAULT NULL,
    `imported_at`         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_old_inv_receipts_id` (`old_receipt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- old_inv_po
-- One row per purchase order record from the old server.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `old_inv_po` (
    `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `old_po_id`           INT UNSIGNED     NOT NULL COMMENT 'po PK on old server',
    `po_ref_no`           INT              NOT NULL DEFAULT 0,
    `po_type`             INT              NOT NULL DEFAULT 0,
    `customer`            VARCHAR(255)     NOT NULL DEFAULT '',
    `customer_contact`    VARCHAR(255)     NOT NULL DEFAULT '',
    `address`             VARCHAR(500)     NOT NULL DEFAULT '',
    `shipping_courier`    VARCHAR(100)     NOT NULL DEFAULT '',
    `shipment_type`       VARCHAR(60)      NOT NULL DEFAULT '',
    `product`             VARCHAR(300)     NOT NULL DEFAULT '',
    `quantity`            FLOAT            NOT NULL DEFAULT 0,
    `price`               FLOAT            NOT NULL DEFAULT 0,
    `gst`                 FLOAT            NOT NULL DEFAULT 0,
    `uom`                 VARCHAR(30)      NOT NULL DEFAULT '',
    `gst_per`             FLOAT            NOT NULL DEFAULT 0,
    `due_date`            DATE             NULL     DEFAULT NULL,
    `po_create_date`      DATE             NULL     DEFAULT NULL,
    `payment_terms`       VARCHAR(255)     NULL,
    `notes`               TEXT             NULL,
    `internal_notes`      VARCHAR(255)     NULL,
    `special_instruction` VARCHAR(255)     NULL,
    `reference`           VARCHAR(255)     NULL,
    `long_description`    VARCHAR(255)     NULL,
    `imported_at`         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_old_inv_po_id` (`old_po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
