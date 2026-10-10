<?php
/**
 * Audit trail of staff actions (sql/2026-09-21_admin_audit_log.sql). Rows are written by the AuditTrail trait on
 * every successful admin API action; nothing here edits or deletes them.
 */
class AuditModel extends Model {

    /** Plain-English names for the audited actions (shown in /admin > Audit Log). */
    const LABELS = array(
        'admin_set_user_status'      => 'Changed account status',
        'admin_moderate'             => 'Moderated an image',
        'admin_refund'               => 'Refunded a purchase',
        'report_resolve'             => 'Resolved a report',
        'verification_resolve'       => 'Decided a verification request',
        'admin_adjust_credits'       => 'Adjusted a balance',
        'admin_send_password_reset'  => 'Sent a password reset',
        'admin_reset_mfa'            => 'Reset two-step sign-in',
        'admin_set_mfa_email'        => 'Changed email sign-in codes',
        'admin_verify_email'         => 'Marked an email verified',
        'admin_resend_verification'  => 'Resent the verification email',
        'admin_cancel_membership'    => 'Canceled a membership',
        'admin_set_plan_cancel'      => 'Changed a creator plan',
        'admin_billing_retry'        => 'Retried a plan payment',
        'admin_niche_save'           => 'Added or renamed a directory niche',
        'admin_niche_set_active'     => 'Turned a directory niche on or off',
        'admin_niche_move'           => 'Reordered the directory niches',
        'admin_leads_csv'            => 'Exported leads',
        'admin_affiliate_payout'      => 'Settled an affiliate payout',
        'admin_affiliate_set'         => 'Changed an affiliate status',
        'admin_affiliates_csv'        => 'Exported affiliate commissions',
        'admin_founding_refuse'       => 'Refused a founding claim',
        'admin_founding_testimonial'  => 'Asked for a testimonial',
        'admin_impersonate'           => 'Signed in as a user',
        'admin_reset_age_verification'=> 'Reset an age verification',
        'admin_scene_delete'          => 'Deleted a scene template',
        'admin_scene_save'            => 'Saved a scene template',
        'admin_scene_set_active'      => 'Turned a scene template on or off',
        'admin_scene_thumb'           => 'Changed a scene thumbnail',
        'admin_set_cross_promo_plans' => 'Changed the cross-promotion plans',
        'admin_set_demo'              => 'Changed the demo flag',
        'support_reply'              => 'Replied to a support request',
        'support_close'              => 'Closed or reopened a support request',
        'seo_keyword_add'            => 'Added a blog keyword',
        'seo_keyword_update'         => 'Updated a blog keyword',
        'seo_draft_now'              => 'Drafted a blog post',
        'seo_article_save'           => 'Edited a blog post',
        'seo_article_publish'        => 'Published a blog post',
        'seo_article_unpublish'      => 'Unpublished a blog post',
        'seo_article_rewrite'        => 'Rewrote a blog post',
        'seo_article_cover'          => 'Regenerated a blog cover',
        'seo_article_discard'        => 'Discarded a blog post',
    );

    /** Post fields that identify what was acted on: field => target type. */
    const TARGET_KEYS = array(
        'user_id' => 'user', 'fan_id' => 'user', 'membership_id' => 'membership', 'asset_id' => 'asset', 'report_id' => 'report',
        'verification_id' => 'verification', 'ticket_id' => 'ticket', 'keyword_id' => 'keyword', 'id' => 'article',
    );

    public function __construct(){ parent::__construct(); }

    /** Write one row. $post: the request's fields; $result: what the action returned (its message is kept). */
    public function record($admin_id, $action, array $post, array $result, $ip){
        $type = null; $tid = null;
        foreach (self::TARGET_KEYS as $k => $t) { if (isset($post[$k]) && (int) $post[$k] > 0) { $type = $t; $tid = (int) $post[$k]; break; } }
        $target_user = $this->resolve_user($type, $tid, $post);
        // Keep what was submitted, minus tokens; trim long text (article bodies) so the log stays readable.
        $details = array();
        foreach ($post as $k => $v) {
            if (in_array($k, array('csrf_token', '_token', 'p_word', 'password'), true)) { continue; }
            $v = html_entity_decode(is_scalar($v) ? (string) $v : json_encode($v), ENT_QUOTES, 'UTF-8');
            $details[$k] = mb_strlen($v) > 300 ? mb_substr($v, 0, 300) . '…' : $v;
        }
        if (isset($result['message'])) { $details['result'] = (string) $result['message']; }
        parent::insert('admin_audit_log', array(
            'admin_id' => (int) $admin_id, 'action' => mb_substr((string) $action, 0, 64),
            'target_user_id' => $target_user, 'target_type' => $type, 'target_id' => $tid,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => mb_substr((string) $ip, 0, 45), 'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    /** The account an action affected, looked up from the object it touched. */
    private function resolve_user($type, $id, array $post){
        if (!$type || !$id) { return null; }
        $q = array(
            'user'         => null,
            'membership'   => "SELECT subscriber_id AS u FROM creator_subscriptions WHERE id = :id",
            'asset'        => "SELECT creator_id AS u FROM media_assets WHERE id = :id",
            'report'       => "SELECT creator_id AS u FROM reports WHERE id = :id",
            'verification' => "SELECT user_id AS u FROM verifications WHERE id = :id",
            'ticket'       => "SELECT user_id AS u FROM support_tickets WHERE id = :id",
        );
        if ($type === 'user') { return (int) $id; }
        if (empty($q[$type])) { return null; }
        $rows = parent::select($q[$type], array('id' => (int) $id));
        return isset($rows[0]['u']) ? (int) $rows[0]['u'] : null;
    }

    /** Newest first, with the admin's and target's handles. */
    public function recent($limit = 300, $target_user_id = 0){
        $limit = max(1, min(1000, (int) $limit));
        $where = ''; $params = array();
        if ((int) $target_user_id > 0) { $where = 'WHERE a.target_user_id = :t'; $params['t'] = (int) $target_user_id; }
        return (array) parent::select(
            "SELECT a.*, ad.u_name AS admin_handle, TRIM(CONCAT(ad.first_name, ' ', ad.last_name)) AS admin_name,
                    tu.u_name AS target_handle, TRIM(CONCAT(tu.first_name, ' ', tu.last_name)) AS target_name
             FROM admin_audit_log a
             LEFT JOIN user_accounts ad ON ad.user_id = a.admin_id
             LEFT JOIN user_accounts tu ON tu.user_id = a.target_user_id
             $where ORDER BY a.id DESC LIMIT $limit", $params);
    }
}
