<?php
/**
 * SQL for the one-off cron/retire_selling_once.php (creator-to-fan selling and adult content removed 2026-11-09).
 * Reads the legacy selling tables directly: the classes that used to own them are gone, and the
 * tables are dropped later (sql/2026-11-09_drop_selling.sql). Every reader tolerates a missing
 * table/column by letting the PDOException reach the script, which prints "n/a".
 */
class RetireSellingModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** Fan memberships still running (paid and free), with the creator's Connect account for the Stripe cancel. */
    public function open_memberships(): array {
        return (array) parent::select(
            "SELECT s.id, s.subscriber_id, s.creator_id, s.status, s.is_free, s.price_cents, s.stripe_subscription_id,
                    c.stripe_connect_account_id, c.u_name AS creator_handle
             FROM creator_subscriptions s
             LEFT JOIN user_accounts c ON c.user_id = s.creator_id
             WHERE s.status IN ('active', 'trialing', 'past_due', 'incomplete', 'paused')
             ORDER BY s.creator_id, s.id");
    }

    /** Close one membership row (idempotent: only rows still open change). */
    public function close_membership($id): bool {
        $now = gmdate('Y-m-d H:i:s');
        return (bool) parent::sql(
            "UPDATE creator_subscriptions SET status = 'canceled', cancel_at_period_end = 0, canceled_at = COALESCE(canceled_at, :n1), updated_at = :n2
             WHERE id = :id AND status IN ('active', 'trialing', 'past_due', 'incomplete', 'paused')",
            array(':n1' => $now, ':n2' => $now, ':id' => (int) $id));
    }

    /** Posts that were Subscribers-only or Pay-per-view and are not archived yet, per creator. */
    public function paid_posts(): array {
        return (array) parent::select(
            "SELECT p.id, p.creator_id, p.audience, p.state, u.u_name AS creator_handle
             FROM posts p LEFT JOIN user_accounts u ON u.user_id = p.creator_id
             WHERE p.audience IN ('subscribers', 'ppv') AND p.state <> 'archived'
             ORDER BY p.creator_id, p.id");
    }

    /** Same move as PostsModel::set_state(..., 'archived'): never made public, the creator keeps it in Archived. */
    public function archive_post($id): bool {
        return (bool) parent::sql(
            "UPDATE posts SET state = 'archived', scheduled_at = NULL, held_reason = NULL, updated_at = :n
             WHERE id = :id AND state <> 'archived'",
            array(':n' => gmdate('Y-m-d H:i:s'), ':id' => (int) $id));
    }

    /** Active automations that posted Subscribers-only / Pay-per-view: after the deploy they would post publicly. */
    public function paid_automations(): array {
        return (array) parent::select(
            "SELECT r.id, r.creator_id, r.name, r.audience, u.u_name AS creator_handle
             FROM scheduler_rules r LEFT JOIN user_accounts u ON u.user_id = r.creator_id
             WHERE r.audience IN ('subscribers', 'ppv') AND r.active = 1 ORDER BY r.creator_id, r.id");
    }

    /** Pause one (the creator turns it back on as an ordinary public automation if they want). */
    public function pause_automation($id): bool {
        return (bool) parent::sql("UPDATE scheduler_rules SET active = 0, next_run_at = NULL, updated_at = :n WHERE id = :id AND active = 1",
            array(':n' => gmdate('Y-m-d H:i:s'), ':id' => (int) $id));
    }

    /** Published articles whose slug is in $slugs, or whose title/slug matches the selling pattern. */
    public function selling_articles(array $slugs, string $pattern): array {
        $in = array(); $params = array();
        foreach (array_values($slugs) as $i => $s) { $in[] = ':s' . $i; $params[':s' . $i] = (string) $s; }
        $params[':p1'] = $pattern; $params[':p2'] = $pattern;
        return (array) parent::select(
            "SELECT id, slug, title, status FROM seo_articles
             WHERE status = 'published'
               AND (slug IN (" . implode(',', $in) . ") OR LOWER(slug) REGEXP :p1 OR LOWER(title) REGEXP :p2)
             ORDER BY slug", $params);
    }

    /** Back to review, the status the admin "Unpublish" action uses (ApiSeoContentController::seo_article_unpublishAction). */
    public function unpublish_article($id): bool {
        return (bool) parent::sql("UPDATE seo_articles SET status = 'review' WHERE id = :id AND status = 'published'", array(':id' => (int) $id));
    }

    // ---- e. adult content (removed 2026-11-09) ----

    /** Media a moderator approved as adult (approved + is_adult = 1): turned back to 'flagged' so it stays held. */
    public function approved_adult_media(): array {
        return (array) parent::select("SELECT id, creator_id FROM media_assets WHERE deleted_at IS NULL AND moderation_status = 'approved' AND is_adult = 1 ORDER BY id");
    }

    public function flag_media($id): bool {
        return (bool) parent::sql("UPDATE media_assets SET moderation_status = 'flagged', updated_at = :n WHERE id = :id AND moderation_status = 'approved' AND is_adult = 1",
            array(':n' => gmdate('Y-m-d H:i:s'), ':id' => (int) $id));
    }

    /** Published or scheduled posts with media flagged adult (flagged, or marked is_adult while that column exists). */
    public function adult_posts(): array {
        return (array) parent::select(
            "SELECT DISTINCT p.id, p.creator_id, p.state, u.u_name AS creator_handle
             FROM posts p JOIN post_assets pa ON pa.post_id = p.id JOIN media_assets ma ON ma.id = pa.asset_id
             LEFT JOIN user_accounts u ON u.user_id = p.creator_id
             WHERE p.state IN ('published', 'scheduled') AND ma.deleted_at IS NULL
               AND (ma.moderation_status = 'flagged' OR ma.is_adult = 1)
             ORDER BY p.creator_id, p.id");
    }

    /** Drafts held by the old age check: same hold, now the content check's reason (ModerationHold::HELD_REASON). */
    public function age_held_posts(): array {
        return (array) parent::select("SELECT id, creator_id FROM posts WHERE held_reason = 'age_verification' ORDER BY id");
    }

    public function reheld_post($id): bool {
        return (bool) parent::sql("UPDATE posts SET held_reason = 'moderation', updated_at = :n WHERE id = :id AND held_reason = 'age_verification'",
            array(':n' => gmdate('Y-m-d H:i:s'), ':id' => (int) $id));
    }

    /** Scene templates marked adult (starters and creators' own copies) that are not deleted. */
    public function adult_scenes(): array {
        return (array) parent::select("SELECT id, creator_id, title FROM scene_templates WHERE is_adult = 1 AND deleted_at IS NULL ORDER BY id");
    }

    public function delete_scene($id): bool {
        return (bool) parent::sql("UPDATE scene_templates SET deleted_at = :n, is_active = 0 WHERE id = :id AND deleted_at IS NULL",
            array(':n' => gmdate('Y-m-d H:i:s'), ':id' => (int) $id));
    }

    public function adult_toggle_on_count(): int {
        $r = parent::select("SELECT COUNT(*) AS n FROM user_accounts WHERE adult_content_enabled = 1");
        return (int) ($r[0]['n'] ?? 0);
    }

    /** The adult toggle off for everyone (idempotent). */
    public function adult_toggle_off_all(): int {
        return (int) parent::update('user_accounts', array('adult_content_enabled' => 0), 'adult_content_enabled = 1', array());
    }

    // ---- report only ----

    public function wallet_balances(bool $creators): array {
        return (array) parent::select(
            "SELECT u.user_id, u.u_name, u.credit_balance FROM user_accounts u
             WHERE u.credit_balance > 0 AND " . ($creators ? '' : 'NOT ') . "(" . UsersModel::creator_sql('u') . ")
             ORDER BY u.credit_balance DESC");
    }

    public function negative_balances(): array {
        return (array) parent::select("SELECT user_id, u_name, credit_balance FROM user_accounts WHERE credit_balance < 0 ORDER BY credit_balance");
    }

    public function open_payouts(): array {
        return (array) parent::select(
            "SELECT p.id, p.creator_id, u.u_name, p.amount_cents, p.status, p.created_at FROM payouts p
             LEFT JOIN user_accounts u ON u.user_id = p.creator_id
             WHERE p.status NOT IN ('paid', 'failed', 'canceled') ORDER BY p.created_at");
    }

    public function unreleased_event_earnings(): array {
        return (array) parent::select(
            "SELECT e.creator_id, COUNT(*) AS n, SUM(r.net_credits) AS credits FROM event_registrations r JOIN events e ON e.id = r.event_id
             WHERE r.status <> 'refunded' AND r.net_credits > 0 AND r.earning_released_at IS NULL GROUP BY e.creator_id");
    }

    public function upcoming_paid_tickets(): array {
        return (array) parent::select(
            "SELECT e.id AS event_id, e.creator_id, e.title, COUNT(*) AS n, SUM(r.price_credits) AS credits FROM event_registrations r JOIN events e ON e.id = r.event_id
             WHERE r.status <> 'refunded' AND r.price_credits > 0 AND e.status <> 'canceled' AND COALESCE(e.end_at, e.start_at) > UTC_TIMESTAMP()
             GROUP BY e.id, e.creator_id, e.title");
    }

    public function unreleased_service_earnings(): array {
        return (array) parent::select(
            "SELECT s.creator_id, COUNT(*) AS n, SUM(p.net_credits) AS credits FROM service_purchases p JOIN services s ON s.id = p.service_id
             WHERE p.status = 'paid' AND p.net_credits > 0 AND p.earning_released_at IS NULL GROUP BY s.creator_id");
    }

    public function open_paid_bookings(): array {
        return (array) parent::select(
            "SELECT p.id, s.creator_id, p.buyer_id, p.price_credits, p.created_at FROM service_purchases p JOIN services s ON s.id = p.service_id
             WHERE p.status = 'paid' AND p.price_credits > 0 AND p.delivered_at IS NULL ORDER BY p.created_at");
    }
}
