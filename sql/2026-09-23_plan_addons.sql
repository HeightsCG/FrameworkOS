-- Plan add-ons (app/config/plans.php '_addons'): quantity-based line items on a creator's
-- platform Stripe subscription, e.g. extra AI influencer slots on Creator.
--   quantity       slots the account is entitled to right now
--   quantity_next  scheduled quantity from next_at on (a removal takes effect at period end); NULL = none
--   stripe_item_id the subscription item that bills this add-on
CREATE TABLE IF NOT EXISTS account_addons (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT NOT NULL,
    addon_key       VARCHAR(40) NOT NULL,
    quantity        INT NOT NULL DEFAULT 0,
    quantity_next   INT NULL DEFAULT NULL,
    next_at         DATETIME NULL DEFAULT NULL,
    stripe_item_id  VARCHAR(64) NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_addon (user_id, addon_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
