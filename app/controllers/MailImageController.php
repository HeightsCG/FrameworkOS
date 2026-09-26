<?php
/**
 * /mail-image/<post_id>: the picture in a "new post" email. Public (email clients fetch it with no session).
 * PostEmailImage decides what may be shown on every request; this only redirects to a short-lived signed URL
 * for that rendition, or answers 404.
 */
class MailImageController extends Controller {

    public $protected = 0;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $id   = (int) (Main::get_url()[1] ?? 0);
        $post = $id > 0 ? (new PostsModel())->get_by_id($id) : null;
        $pick = $post ? PostEmailImage::pick($post) : null;
        if (!$pick) { http_response_code(404); exit; }
        header('Cache-Control: public, max-age=300');
        header('Location: ' . MediaService::signed_variant($pick[0], $pick[1], 3600), true, 302);
        exit;
    }
}
