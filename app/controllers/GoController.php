<?php
/**
 * Outbound link click-through: /go/<link_id>. Records a click for the creator's
 * link, then 302-redirects to the destination the creator configured. Public (no
 * auth) — profile-link clicks come from anyone. The destination is never taken from
 * user input; it's the stored URL for that link id, so this is not an open redirect.
 */
class GoController extends Controller {

    public $protected = 0;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $url = Main::get_url();
        $id  = (int) ($url[1] ?? 0);   // /go/<id> (dispatched from Bootstrap)

        $link = $id > 0 ? (new CreatorLinksModel())->get_public($id) : null;
        if (!$link || (int) $link['is_enabled'] !== 1) {
            header('Location: /', true, 302);
            exit;
        }

        // Only follow http/https destinations (defence-in-depth against a bad stored value).
        $dest = trim((string) $link['url']);
        if (!preg_match('#^https?://#i', $dest)) {
            header('Location: /', true, 302);
            exit;
        }

        (new LinkClicksModel())->record((int) $link['id'], (int) $link['user_id']);
        header('Location: ' . $dest, true, 302);
        exit;
    }
}
