<?php
/**
 * The signed-in user's Purchases (/purchases) — content they've bought one-time:
 * pay-per-view unlocks, content bundles and paid message unlocks. Distinct from subscriptions (recurring).
 * Available to any logged-in user (fans, not just creators).
 */
class PurchasesController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $user_id = (int) Session::get('user_id');
        $rows = (new UsersModel())->get_user_by_id($user_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user) { header('Location: /'); exit; }

        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $this->view->display_name = $name !== '' ? $name : ('@' . ($user['u_name'] ?? ''));

        // One-time buys, newest first: PPV unlocks + content bundles.
        $purchases  = array();
        $postsModel = new PostsModel();
        foreach ((array) (new PpvUnlocksModel())->get_for_fan($user_id) as $u) {
            $media = array();
            foreach ((array) $postsModel->get_assets((int) $u['post_id']) as $a) {
                if (!empty($a['deleted_at']) || ($a['status'] ?? '') !== 'ready') { continue; }
                $media[] = $this->media_item($a);
            }
            $cap = trim((string) $u['caption']);
            $purchases[] = array(
                'type'         => 'ppv',
                'title'        => ($cap !== '' ? mb_substr($cap, 0, 80) : 'Pay-per-view content'),
                'creator'      => (string) $u['creator_name'],
                'handle'       => (string) $u['creator_handle'],
                'price'        => (int) $u['price_credits'],
                'purchased_at' => (string) $u['purchased_at'],
                'media'        => $media,
            );
        }
        $bundlesModel = new ContentBundlesModel();
        foreach ((array) $bundlesModel->get_purchased_for_fan($user_id) as $b) {
            $media = array();
            foreach ((array) $bundlesModel->get_media_for_bundle((int) $b['id']) as $a) {
                $media[] = $this->media_item($a);
            }
            $purchases[] = array(
                'type'         => 'bundle',
                'title'        => (string) $b['name'],
                'creator'      => (string) $b['creator_name'],
                'handle'       => (string) $b['creator_handle'],
                'price'        => (int) $b['paid_credits'],
                'purchased_at' => (string) $b['purchased_at'],
                'media'        => $media,
            );
        }
        $messagesModel = new MessagesModel();
        foreach ((array) (new MessageUnlocksModel())->get_for_fan($user_id) as $u) {
            $media = array();
            foreach ((array) ($messagesModel->assets_for_messages(array((int) $u['message_id']))[(int) $u['message_id']] ?? array()) as $a) {
                $media[] = $this->media_item($a);
            }
            $body = trim((string) $u['body']);
            $purchases[] = array(
                'type'         => 'message',
                'title'        => ($body !== '' ? mb_substr($body, 0, 80) : 'Message from ' . (string) $u['creator_name']),
                'creator'      => (string) $u['creator_name'],
                'handle'       => (string) $u['creator_handle'],
                'price'        => (int) $u['price_credits'],
                'purchased_at' => (string) $u['purchased_at'],
                'media'        => $media,
            );
        }
        usort($purchases, function ($a, $b) { return strcmp((string) $b['purchased_at'], (string) $a['purchased_at']); });

        $this->view->purchases = $purchases;
        $this->view->render();
    }

    /** Shape one purchased media asset (signed for the buyer) for the gallery.
     *  Purchased content is served UNWATERMARKED ('original') — the buyer paid for it. */
    private function media_item($a){
        $is_video = (($a['type'] ?? '') === 'video');
        return array(
            'type'  => (string) ($a['type'] ?? 'image'),
            'url'   => MediaService::signed_variant($a, 'original', 900),
            'thumb' => MediaService::signed_variant($a, $is_video ? 'poster' : 'thumb', 900),
        );
    }

}
