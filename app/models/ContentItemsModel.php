<?php
/**
 * On-platform creator content (PRD §10). A generic post: title + description +
 * body + tags + media assets (ContentAssetsModel), gated by access level, with
 * draft/scheduled/published status and optional pin.
 */
class ContentItemsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** All of a creator's items (any status), for the Studio manager. */
    public function get_for_creator($creator_id){
        return parent::select(
            "SELECT id, title, description, body, tags, access, required_plan_id, price_credits,
                    preview_url, status, scheduled_at, pinned, comments_enabled, sort_order
             FROM content_items
             WHERE creator_id = :c
             ORDER BY sort_order ASC, id ASC",
            array('c' => (int) $creator_id)
        );
    }

    /**
     * Publicly visible items: published, plus scheduled items whose time has
     * passed (a lazy fallback in case publish_due() hasn't run). Pinned first.
     */
    public function get_published_for_creator($creator_id){
        return parent::select(
            "SELECT ci.*, rp.price_cents AS required_plan_price, rp.name AS required_plan_name
             FROM content_items ci
             LEFT JOIN creator_plans rp ON rp.id = ci.required_plan_id
             WHERE ci.creator_id = :c
               AND (ci.status = 'published' OR (ci.status = 'scheduled' AND ci.scheduled_at <= :now))
             ORDER BY ci.pinned DESC, ci.sort_order ASC, ci.id ASC",
            array('c' => (int) $creator_id, 'now' => date('Y-m-d H:i:s'))
        );
    }

    /** Flip any scheduled items whose time has arrived to published (no cron needed). */
    public function publish_due($creator_id){
        $now = date('Y-m-d H:i:s');
        // Distinct placeholders: this PDO connection uses native prepares, which
        // don't allow one named parameter to appear more than once.
        return parent::sql(
            "UPDATE content_items
             SET status = 'published',
                 published_at = COALESCE(published_at, :now1),
                 scheduled_at = NULL,
                 updated_at = :now2
             WHERE creator_id = :c AND status = 'scheduled' AND scheduled_at <= :now3",
            array('c' => (int) $creator_id, 'now1' => $now, 'now2' => $now, 'now3' => $now)
        );
    }

    public function get_one($creator_id, $id){
        $rows = parent::select(
            "SELECT * FROM content_items WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** A visible item by id (published or due-scheduled), any owner — for unlock. */
    public function get_public_by_id($id){
        $rows = parent::select(
            "SELECT * FROM content_items
             WHERE id = :id AND (status = 'published' OR (status = 'scheduled' AND scheduled_at <= :now))",
            array('id' => (int) $id, 'now' => date('Y-m-d H:i:s'))
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function add($creator_id, $fields){
        $now = date('Y-m-d H:i:s');
        $max = parent::select(
            "SELECT COALESCE(MAX(sort_order), -1) AS m FROM content_items WHERE creator_id = :c",
            array('c' => (int) $creator_id)
        );
        $next = (is_array($max) && count($max) === 1) ? ((int) $max[0]['m'] + 1) : 0;

        return parent::insert('content_items', array(
            'creator_id'       => (int) $creator_id,
            'title'            => (string) $fields['title'],
            'description'      => (string) ($fields['description'] ?? ''),
            'body'             => (string) ($fields['body'] ?? ''),
            'tags'             => (string) ($fields['tags'] ?? ''),
            'access'           => (string) $fields['access'],
            'required_plan_id' => isset($fields['required_plan_id']) && $fields['required_plan_id'] ? (int) $fields['required_plan_id'] : null,
            'price_credits'    => (int) ($fields['price_credits'] ?? 0),
            'comments_enabled' => !empty($fields['comments_enabled']) ? 1 : 0,
            'status'           => 'draft',
            'sort_order'       => $next,
            'created_at'       => $now,
            'updated_at'       => $now,
        ));
    }

    public function update_item($creator_id, $id, $fields){
        return parent::update(
            'content_items',
            array(
                'title'            => (string) $fields['title'],
                'description'      => (string) ($fields['description'] ?? ''),
                'body'             => (string) ($fields['body'] ?? ''),
                'tags'             => (string) ($fields['tags'] ?? ''),
                'access'           => (string) $fields['access'],
                'required_plan_id' => isset($fields['required_plan_id']) && $fields['required_plan_id'] ? (int) $fields['required_plan_id'] : null,
                'price_credits'    => (int) ($fields['price_credits'] ?? 0),
                'comments_enabled' => !empty($fields['comments_enabled']) ? 1 : 0,
                'updated_at'       => date('Y-m-d H:i:s'),
            ),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
    }

    /**
     * Set status. 'published' stamps published_at (first time) and clears any
     * schedule; 'scheduled' stores the future time; 'draft' clears the schedule.
     */
    public function set_status($creator_id, $id, $status, $scheduled_at = null){
        $now  = date('Y-m-d H:i:s');
        $data = array('status' => $status, 'updated_at' => $now);

        if ($status === 'published') {
            $item = $this->get_one($creator_id, $id);
            if ($item && empty($item['published_at'])) { $data['published_at'] = $now; }
            $data['scheduled_at'] = null;
        } elseif ($status === 'scheduled') {
            $data['scheduled_at'] = $scheduled_at;
        } else { // draft
            $data['scheduled_at'] = null;
        }

        return parent::update('content_items', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_pinned($creator_id, $id, $pinned){
        return parent::update('content_items',
            array('pinned' => $pinned ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_preview($creator_id, $id, $url){
        return parent::update('content_items',
            array('preview_url' => $url, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function delete_item($creator_id, $id){
        return parent::delete('content_items', 'id = :id AND creator_id = :c', 1,
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function reorder($creator_id, $ids){
        $now = date('Y-m-d H:i:s');
        $order = 0;
        foreach ($ids as $id) {
            parent::update('content_items',
                array('sort_order' => $order, 'updated_at' => $now),
                'id = :id AND creator_id = :c',
                array('id' => (int) $id, 'c' => (int) $creator_id));
            $order++;
        }
        return true;
    }
}
