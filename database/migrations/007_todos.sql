-- ============================================================
-- Simple internal todo/backlog board: bugs, nice-to-haves, and
-- new feature ideas, managed from the admin panel.
-- ============================================================

CREATE TABLE IF NOT EXISTS todos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category ENUM('bug', 'nice_to_have', 'feature') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
