-- Automations choose whether their posts go on the creator's Creator Link Studio page (Destinations step), like a post
-- in the post editor (posts.on_cls). Default 1 = every existing automation keeps publishing there, as before.
ALTER TABLE scheduler_rules ADD COLUMN on_cls TINYINT(1) NOT NULL DEFAULT 1 AFTER comments_enabled;
