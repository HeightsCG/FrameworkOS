<?php
class SocialAccountsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get_for_user($user_id){
        return parent::select(
            "SELECT * FROM user_social_accounts WHERE user_id = :user_id ORDER BY platform",
            array('user_id' => (int) $user_id)
        );
    }

    public function get_connected_for_user($user_id){
        return parent::select(
            "SELECT * FROM user_social_accounts WHERE user_id = :user_id AND status = 'connected' ORDER BY platform",
            array('user_id' => (int) $user_id)
        );
    }

    public function get_by_pfm_id($pfm_id){
        $rows = parent::select(
            "SELECT * FROM user_social_accounts WHERE post_for_me_social_account_id = :pfm_id",
            array('pfm_id' => $pfm_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Insert or update a social account from a Post for Me account row, owned by $user_id. */
    public function upsert_from_pfm($user_id, $acct){
        $now      = date('Y-m-d H:i:s');
        $pfm_id   = $acct['id'];
        $platform = $acct['platform'] ?? '';
        $username = $acct['username'] ?? null;
        $avatar   = $acct['profile_photo_url'] ?? null;
        $status   = $acct['status'] ?? 'connected';

        $existing = $this->get_by_pfm_id($pfm_id);
        if ($existing) {
            return parent::update(
                'user_social_accounts',
                array(
                    'platform'        => $platform,
                    'username'        => $username,
                    'display_name'    => $username,
                    'avatar_url'      => $avatar,
                    'status'          => $status,
                    'connected_at'    => ($status === 'connected') ? $now : $existing['connected_at'],
                    'disconnected_at' => ($status === 'disconnected') ? $now : null,
                    'updated_at'      => $now,
                ),
                'post_for_me_social_account_id = :pfm_id',
                array('pfm_id' => $pfm_id)
            );
        }

        return parent::insert('user_social_accounts', array(
            'user_id'                       => (int) $user_id,
            'platform'                      => $platform,
            'post_for_me_social_account_id' => $pfm_id,
            'username'                      => $username,
            'display_name'                  => $username,
            'avatar_url'                    => $avatar,
            'status'                        => $status,
            'connected_at'                  => $now,
            'created_at'                    => $now,
            'updated_at'                    => $now,
        ));
    }

    /** The line added to this account's cross-posts of AI media ('' = the default line). */
    public function set_ai_disclosure_text($user_id, $pfm_id, $text){
        $t = trim(preg_replace('/\s+/', ' ', (string) $text));
        return parent::update('user_social_accounts', array('ai_disclosure_text' => ($t === '') ? null : mb_substr($t, 0, 200)),
            'user_id = :u AND post_for_me_social_account_id = :p', array('u' => (int) $user_id, 'p' => (string) $pfm_id));
    }

    /**
     * Bring the local rows in line with Post for Me's list for one user (PostForMeService::get_accounts($user_id), which only
     * returns accounts whose external_id is that user). Post for Me decides who owns an account: a row for one of these
     * accounts that sits under another user, or has a different status, is moved and updated; a second row for the same
     * account is removed. An account connected there and missing here is added; one disconnected there and unknown here is not.
     * Rows that already match are left alone, so connected_at keeps its date. Returns how many accounts changed.
     */
    public function sync_from_pfm($user_id, array $accounts): int {
        $n = 0;
        foreach ($accounts as $pa) {
            if (!is_array($pa) || empty($pa['id'])) { continue; }
            $status = (string) ($pa['status'] ?? 'connected');
            $rows = parent::select("SELECT * FROM user_social_accounts WHERE post_for_me_social_account_id = :p ORDER BY id", array('p' => (string) $pa['id']));
            $rows = is_array($rows) ? $rows : array();
            if (!$rows) {
                if ($status !== 'connected') { continue; }
                $this->upsert_from_pfm($user_id, $pa);
                $n++;
                continue;
            }
            foreach (array_slice($rows, 1) as $dup) { parent::delete('user_social_accounts', 'id = :id', 1, array('id' => (int) $dup['id'])); }
            $have = $rows[0];
            if ((int) $have['user_id'] === (int) $user_id && (string) $have['status'] === $status && count($rows) === 1) { continue; }
            if ((int) $have['user_id'] !== (int) $user_id) { $this->reassign($pa['id'], $user_id); }
            $this->upsert_from_pfm($user_id, $pa);
            $n++;
        }
        return $n;
    }

    /** The account now belongs to $user_id (it signed in to the network from that user's settings). */
    public function reassign($pfm_id, $user_id){
        return parent::update('user_social_accounts', array('user_id' => (int) $user_id, 'updated_at' => date('Y-m-d H:i:s')),
            'post_for_me_social_account_id = :pfm_id', array('pfm_id' => (string) $pfm_id));
    }

    public function mark_disconnected($user_id, $pfm_id){
        return parent::update(
            'user_social_accounts',
            array(
                'status'          => 'disconnected',
                'disconnected_at' => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id AND post_for_me_social_account_id = :pfm_id',
            array('user_id' => (int) $user_id, 'pfm_id' => $pfm_id)
        );
    }
}
