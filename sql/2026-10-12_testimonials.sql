-- Testimonials shown on the home page (heycatch D3.2, 2026-10-12). Admin-managed: added, approved and removed in /admin/testimonials.
-- The row renders only when at least Proof::TESTIMONIALS_MIN approved rows exist. Never seeded: every row is a real person's words.
CREATE TABLE IF NOT EXISTS testimonials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(60) NOT NULL,
    niche VARCHAR(80) NOT NULL DEFAULT '',
    quote VARCHAR(400) NOT NULL,
    photo_url VARCHAR(500) NULL,
    status ENUM('pending', 'approved') NOT NULL DEFAULT 'pending',
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    KEY idx_status_sort (status, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
