<?php
/** Home page testimonials (heycatch D3.2): admin-managed rows, shown only once Proof::TESTIMONIALS_MIN are approved. */
class TestimonialsModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** Approved rows in display order, for the home page. */
    public function approved($limit = 3){
        return (array) parent::select("SELECT id, first_name, niche, quote, photo_url FROM testimonials WHERE status = 'approved' ORDER BY sort_order ASC, approved_at DESC, id DESC LIMIT " . max(1, (int) $limit));
    }

    public function approved_count(): int {
        $r = parent::select("SELECT COUNT(*) AS n FROM testimonials WHERE status = 'approved'");
        return (int) ($r[0]['n'] ?? 0);
    }

    /** Every row, newest first, for /admin/testimonials. */
    public function admin_list($limit = 300){
        return (array) parent::select("SELECT t.*, u.u_name AS created_by_name FROM testimonials t LEFT JOIN user_accounts u ON u.user_id = t.created_by ORDER BY t.id DESC LIMIT " . max(1, (int) $limit));
    }

    public function get($id){
        $r = parent::select("SELECT * FROM testimonials WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function add($first_name, $niche, $quote, $photo_url, $created_by): int {
        return (int) parent::insert('testimonials', array('first_name' => (string) $first_name, 'niche' => (string) $niche, 'quote' => (string) $quote, 'photo_url' => (string) $photo_url !== '' ? (string) $photo_url : null, 'status' => 'pending', 'created_by' => (int) $created_by, 'created_at' => gmdate('Y-m-d H:i:s')));   // Model::insert returns the new id
    }

    public function set_status($id, $approved): bool {
        return (bool) parent::update('testimonials', array('status' => $approved ? 'approved' : 'pending', 'approved_at' => $approved ? gmdate('Y-m-d H:i:s') : null), 'id = :id', array('id' => (int) $id));
    }

    public function remove($id): bool {
        return (bool) parent::delete('testimonials', 'id = :id', 1, array('id' => (int) $id));
    }
}
