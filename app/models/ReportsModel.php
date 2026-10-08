<?php
/**
 * User reports & trust-safety queue (PRD §35–37). Anyone can report a post or a creator;
 * reports land in the /admin queue where staff dismiss, remove the content, or suspend the
 * account. DMCA is the 'copyright' reason. Content removal blocks the post's image assets,
 * which the existing moderation gate ('blocked') already hides everywhere.
 */
class ReportsModel extends Model {

    public static function reasons(){
        return array('spam', 'harassment', 'nudity', 'illegal', 'impersonation', 'copyright', 'other');
    }
    public static function reason_label($r){
        $map = array(
            'spam' => 'Spam or scam', 'harassment' => 'Harassment or hate',
            'nudity' => 'Unlabeled adult content', 'illegal' => 'Illegal or dangerous',
            'impersonation' => 'Impersonation', 'copyright' => 'Copyright / DMCA', 'other' => 'Other',
        );
        return $map[$r] ?? 'Other';
    }

    /** File a report. Returns ['ok'=>bool,'message'=>?]. Idempotent per reporter+target while open. */
    public function submit($reporter_id, $target_type, $target_id, $reason, $details = ''){
        $reporter_id = (int) $reporter_id; $target_id = (int) $target_id;
        if (!in_array($target_type, array('post', 'creator'), true)) { return array('ok' => false, 'message' => 'Invalid target'); }
        if (!in_array($reason, self::reasons(), true)) { return array('ok' => false, 'message' => 'Choose a reason'); }

        // Resolve the owning creator (for grouping) and validate the target exists.
        $creator_id = null;
        if ($target_type === 'post') {
            $r = parent::select("SELECT creator_id FROM posts WHERE id = :id", array('id' => $target_id));
            if (!is_array($r) || count($r) !== 1) { return array('ok' => false, 'message' => 'Content not found'); }
            $creator_id = (int) $r[0]['creator_id'];
        } else {
            $r = parent::select("SELECT user_id FROM user_accounts WHERE user_id = :id AND deleted = 0", array('id' => $target_id));
            if (!is_array($r) || count($r) !== 1) { return array('ok' => false, 'message' => 'Account not found'); }
            $creator_id = $target_id;
        }
        if ($creator_id === $reporter_id) { return array('ok' => false, 'message' => 'You can\'t report your own content'); }

        $dup = parent::select(
            "SELECT id FROM reports WHERE reporter_id = :r AND target_type = :t AND target_id = :id AND status = 'open' LIMIT 1",
            array('r' => $reporter_id, 't' => $target_type, 'id' => $target_id));
        if (is_array($dup) && count($dup)) { return array('ok' => true, 'message' => 'Already reported'); }

        parent::insert('reports', array(
            'reporter_id' => $reporter_id, 'target_type' => $target_type, 'target_id' => $target_id,
            'creator_id' => $creator_id, 'reason' => $reason,
            'details' => mb_substr((string) $details, 0, 2000),
            'status' => 'open', 'created_at' => date('Y-m-d H:i:s'),
        ));
        return array('ok' => true, 'message' => 'Report received');
    }

    /** Open reports for the admin queue, with target + reporter identity. */
    public function open_for_admin($limit = 40){
        $limit = max(1, min(100, (int) $limit));
        $rows = parent::select(
            "SELECT r.*, u.u_name AS reporter_handle,
                    c.u_name AS creator_handle,
                    p.caption AS post_caption
             FROM reports r
             LEFT JOIN user_accounts u ON u.user_id = r.reporter_id
             LEFT JOIN user_accounts c ON c.user_id = r.creator_id
             LEFT JOIN posts p ON (r.target_type = 'post' AND p.id = r.target_id)
             WHERE r.status = 'open'
             ORDER BY r.created_at ASC
             LIMIT $limit");
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array(
                'id'             => (int) $r['id'],
                'target_type'    => (string) $r['target_type'],
                'target_id'      => (int) $r['target_id'],
                'creator_handle' => (string) ($r['creator_handle'] ?? ''),
                'creator_id'     => (int) ($r['creator_id'] ?? 0),
                'reporter_handle'=> (string) ($r['reporter_handle'] ?? ''),
                'reason'         => (string) $r['reason'],
                'reason_label'   => self::reason_label($r['reason']),
                'details'        => (string) ($r['details'] ?? ''),
                'post_caption'   => html_entity_decode((string) ($r['post_caption'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'created_at'     => (string) $r['created_at'],
            );
        }
        return $out;
    }

    public function open_count(){
        $r = parent::select("SELECT COUNT(*) AS n FROM reports WHERE status = 'open'");
        return is_array($r) && count($r) ? (int) $r[0]['n'] : 0;
    }

    public function get($id){
        $r = parent::select("SELECT * FROM reports WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** Mark a report resolved. Also resolves sibling open reports on the same target. */
    public function resolve($id, $admin_id, $status, $resolution){
        $rep = $this->get($id);
        if (!$rep) { return false; }
        $status = in_array($status, array('dismissed', 'actioned'), true) ? $status : 'dismissed';
        parent::update('reports',
            array('status' => $status, 'resolution' => mb_substr((string) $resolution, 0, 255),
                  'resolved_by' => (int) $admin_id, 'resolved_at' => date('Y-m-d H:i:s')),
            'target_type = :t AND target_id = :id AND status = \'open\'',
            array('t' => $rep['target_type'], 'id' => (int) $rep['target_id']));
        return true;
    }

    /** Take a post's content down by blocking its image assets (hidden by the moderation gate). */
    public function takedown_post($post_id){
        $res = parent::update('media_assets',
            array('moderation_status' => 'blocked'),
            'id IN (SELECT asset_id FROM post_assets WHERE post_id = :p)',
            array('p' => (int) $post_id));
        PublicThumbService::queue_purge(array('post' => (int) $post_id));   // removed content loses its public copies
        return $res;
    }
}
