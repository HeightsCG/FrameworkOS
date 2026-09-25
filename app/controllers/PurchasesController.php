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

        $purchases = array();
        foreach ($this->purchases_for($user_id) as $p) {
            $p['media'] = array_map(array($this, 'media_item'), $p['assets']);
            unset($p['assets']);
            $purchases[] = $p;
        }
        $this->view->purchases = $purchases;
        $this->view->render();
    }

    /** Download everything in one purchase as a zip: /purchases/download/<ppv|bundle|message>/<id>. Only the buyer's own purchases. */
    public function downloadAction(){
        $user_id = (int) Session::get('user_id');
        $url  = Main::get_url();
        $want = (string) ($url[2] ?? '') . ':' . (int) ($url[3] ?? 0);
        foreach ($this->purchases_for($user_id) as $p) {
            if ($p['key'] !== $want) { continue; }
            if (empty($p['assets'])) { break; }
            @set_time_limit(0);
            $zip = tempnam(sys_get_temp_dir(), 'zip');
            if (MediaService::build_zip($zip, $p['assets'], 'original') === 0) { @unlink($zip); break; }
            MediaService::send_zip($zip, trim(mb_substr($p['creator'] . ' - ' . $p['title'], 0, 80)) . '.zip');
        }
        header('Location: /purchases'); exit;
    }

    /**
     * Everything this user bought one-time, newest first: PPV unlocks, content bundles and paid
     * message unlocks. Each has 'key' (type:id, for downloads) and 'assets' (ready media rows).
     */
    private function purchases_for($user_id){
        $purchases  = array();
        $ready = function ($rows) { return array_values(array_filter((array) $rows, function ($a) { return empty($a['deleted_at']) && ($a['status'] ?? '') === 'ready'; })); };
        $postsModel = new PostsModel();
        foreach ((array) (new PpvUnlocksModel())->get_for_fan($user_id) as $u) {
            $cap = trim((string) $u['caption']);
            $purchases[] = array(
                'key'          => 'ppv:' . (int) $u['post_id'],
                'type'         => 'ppv',
                'title'        => ($cap !== '' ? mb_substr($cap, 0, 80) : 'Pay-per-view content'),
                'creator'      => (string) $u['creator_name'],
                'handle'       => (string) $u['creator_handle'],
                'price'        => (int) $u['price_credits'],
                'purchased_at' => (string) $u['purchased_at'],
                'assets'       => $ready($postsModel->get_assets((int) $u['post_id'])),
            );
        }
        $bundlesModel = new ContentBundlesModel();
        foreach ((array) $bundlesModel->get_purchased_for_fan($user_id) as $b) {
            $purchases[] = array(
                'key'          => 'bundle:' . (int) $b['id'],
                'type'         => 'bundle',
                'title'        => (string) $b['name'],
                'creator'      => (string) $b['creator_name'],
                'handle'       => (string) $b['creator_handle'],
                'price'        => (int) $b['paid_credits'],
                'purchased_at' => (string) $b['purchased_at'],
                'assets'       => $ready($bundlesModel->get_media_for_bundle((int) $b['id'])),
            );
        }
        $messagesModel = new MessagesModel();
        foreach ((array) (new MessageUnlocksModel())->get_for_fan($user_id) as $u) {
            $body = trim((string) $u['body']);
            $purchases[] = array(
                'key'          => 'message:' . (int) $u['message_id'],
                'type'         => 'message',
                'title'        => ($body !== '' ? mb_substr($body, 0, 80) : 'Message from ' . (string) $u['creator_name']),
                'creator'      => (string) $u['creator_name'],
                'handle'       => (string) $u['creator_handle'],
                'price'        => (int) $u['price_credits'],
                'purchased_at' => (string) $u['purchased_at'],
                'assets'       => $ready($messagesModel->assets_for_messages(array((int) $u['message_id']))[(int) $u['message_id']] ?? array()),
            );
        }
        usort($purchases, function ($a, $b) { return strcmp((string) $b['purchased_at'], (string) $a['purchased_at']); });
        return $purchases;
    }

    /** Shape one purchased media asset (signed for the buyer) for the gallery.
     *  Purchased content is served UNWATERMARKED ('original') — the buyer paid for it. */
    private function media_item($a){
        $is_video = (($a['type'] ?? '') === 'video');
        return array(
            'type'  => (string) ($a['type'] ?? 'image'),
            'url'   => MediaService::signed_variant($a, 'original', 900),
            'download' => MediaService::download_url($a, 'original', 3600),
            'thumb' => MediaService::signed_variant($a, $is_video ? 'poster' : 'thumb', 900),
        );
    }

}
