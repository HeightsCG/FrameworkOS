<?php
/**
 * Outbound click tracking for creator profile links (PRD §7.4). One row per click,
 * recorded by the /go/<id> redirect; analytics aggregates counts + top links.
 */
class LinkClicksModel extends Model {

    public function record($link_id, $creator_id){
        return (int) parent::insert('link_clicks', array(
            'link_id'    => (int) $link_id,
            'creator_id' => (int) $creator_id,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}
