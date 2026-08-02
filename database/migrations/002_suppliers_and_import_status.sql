-- ============================================================
-- Suppliers + product import workflow
-- ============================================================

CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    list_products_url VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE products
    ADD COLUMN supplier_id INT UNSIGNED NULL AFTER category_id,
    ADD COLUMN supplier_external_id VARCHAR(120) NULL AFTER supplier_id,
    ADD COLUMN import_status ENUM('created', 'imported', 'invalid', 'update', 'approved')
        NOT NULL DEFAULT 'created' AFTER image_path,
    ADD CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id) ON DELETE SET NULL,
    ADD UNIQUE KEY uniq_products_supplier_external (supplier_id, supplier_external_id),
    ADD INDEX idx_products_import_status (import_status);

-- Products that already existed before this workflow was introduced were
-- effectively live on the storefront; keep them visible under the new
-- approved-only rule instead of silently hiding them.
UPDATE products SET import_status = 'approved' WHERE import_status = 'created';
