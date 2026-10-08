-- Creator directory niches (/creators/<slug>), managed from /admin > Niches. Seeded from DirectoryService::CATEGORIES.
-- The slug is the URL and never changes; the name is the label on chips, cards and the Settings select.
CREATE TABLE IF NOT EXISTS niches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(60) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_niche_slug (slug),
    KEY idx_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO niches (slug, name, sort_order, active, created_at, updated_at) VALUES
    ('fitness',   'Fitness',          10,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('music',     'Music',            20,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('cooking',   'Food & Cooking',   30,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('gaming',    'Gaming',           40,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('beauty',    'Beauty & Fashion', 50,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('art',       'Art & Design',     60,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('education', 'Education',        70,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('lifestyle', 'Lifestyle',        80,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('travel',    'Travel',           90,  1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('business',  'Business',         100, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('other',     'Other',            110, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP());
