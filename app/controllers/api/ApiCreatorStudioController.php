<?php
/** Creator Studio: profile/brand, links, automations and the post editor. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiCreatorStudioController extends BaseApiController {

    /** Presence heartbeat — pinged by the Studio so an open-but-idle creator stays "online". */
    public function heartbeatAction(){
        $this->require_creator('view', false);
        $this->jsonSuccess();
    }

    /** Persist the creator's real timezone (auto-detected from their browser). */
    public function set_timezoneAction(){
        $user = $this->require_creator('content', false);
        $tz   = $this->valid_tz($this->post['timezone'] ?? '');
        if ($tz === '') { $this->jsonError('Invalid timezone'); }
        (new UsersModel())->set_content_timezone((int) $user['user_id'], $tz);
        $this->jsonSuccess(['timezone' => $tz]);
    }

    public function save_creator_profileAction(){
        $this->require_creator('content', false);

        // POST text arrives HTML-encoded (clean_post_data); store it plain, the pages escape it once when shown.
        $plain = function ($k) { return trim(html_entity_decode((string) ($this->post[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
        $display_name = $plain('display_name');
        if ($display_name === '') {
            $this->jsonError('Display name is required');
        }

        (new CreatorProfileModel())->save(Permissions::creator_id(), [
            'display_name' => $display_name,
            'bio'          => $plain('bio'),
            'location'     => $plain('location'),
        ]);
        if ((string) ($this->post['similar_on'] ?? '') !== '') { (new CreatorProfileModel())->set_similar_off(Permissions::creator_id(), empty($this->post['similar_on'])); }   // "More Creators Like This" switch

        try { IndexNow::creator((int) Permissions::creator_id()); } catch (\Throwable $e) { error_log('[indexnow] profile: ' . $e->getMessage()); }
        $this->jsonSuccess(['message' => 'Profile saved']);
    }

    /** Creator Directory switch + category (Settings -> Creator Profile). Turning it on checks the photos first. */
    public function save_directory_listingAction(){
        $this->require_creator('content', false);
        $r = DirectoryService::save(Permissions::creator_id(), !empty($this->post['listed']), (string) ($this->post['category'] ?? ''));
        if (empty($r['ok'])) { $this->jsonError((string) $r['message']); }
        $m = new CreatorProfileModel(); $cid = Permissions::creator_id();
        $on = !empty($this->post['listed']);
        $this->jsonSuccess(['message' => $r['message'], 'listed' => $on && $m->directory_eligible($cid), 'missing' => $on ? $m->directory_missing($cid) : '']);   // the Settings label: Listed, or what's missing
    }

    /** Generate brand details from a website URL (Claude). Does not persist. */
    public function generate_brand_identityAction(){
        $this->require_creator('content', 'ai');
        $url = html_entity_decode(trim((string) ($this->post['url'] ?? '')), ENT_QUOTES);
        if ($url === '') {
            $this->jsonError('Enter your website URL first.');
        }
        $result = BrandService::generate_from_url($url);
        if (empty($result['ok'])) {
            $this->jsonError((string) ($result['error'] ?? 'Generation failed. Try again.'));
        }
        $this->jsonSuccess(['brand' => $result['data']]);
    }

    /** Persist the reviewed/edited brand identity for this creator. */
    public function save_brand_identityAction(){
        $this->require_creator('content', false);
        $split = function ($v) {
            if (is_array($v)) { return array_values(array_filter(array_map('trim', $v), 'strlen')); }
            $v = html_entity_decode((string) $v, ENT_QUOTES);
            return array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
        };
        $dec = function ($k) { return html_entity_decode(trim((string) ($this->post[$k] ?? '')), ENT_QUOTES); };

        (new CreatorBrandModel())->save(Permissions::creator_id(), [
            'source_url'  => $dec('source_url'),
            'brand_name'  => $dec('brand_name'),
            'tagline'     => $dec('tagline'),
            'description' => $dec('description'),
            'voice'       => $dec('voice'),
            'colors'      => $split($this->post['colors'] ?? ''),
            'keywords'    => $split($this->post['keywords'] ?? ''),
        ]);
        $this->jsonSuccess(['message' => 'Brand identity saved']);
    }

    public function upload_creator_imageAction(){
        $this->require_creator('content', false);

        $kind = (string) ($this->post['kind'] ?? '');
        if (!in_array($kind, ['avatar', 'cover'], true)) {
            $this->jsonError('Invalid image type');
        }

        $file = $_FILES['image'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->jsonError('No image was uploaded');
        }
        if ((int) $file['size'] > 5 * 1024 * 1024) {
            $this->jsonError('Image must be 5MB or smaller');
        }

        // Trust the actual bytes, not the client-supplied name/type.
        $info = @getimagesize($file['tmp_name']);
        $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if ($info === false || !isset($ext_map[$info['mime']])) {
            $this->jsonError('Unsupported image type (use JPG, PNG, WebP, or GIF)');
        }

        if (!S3Service::configured()) {
            $this->jsonError('Image uploads are not available right now');
        }

        $user_id = Permissions::creator_id();
        $key     = 'creator/u' . $user_id . '_' . $kind . '_' . bin2hex(random_bytes(8)) . '.' . $ext_map[$info['mime']];

        $img = ProfileImage::prepare($file['tmp_name'], $info['mime']);   // no EXIF/GPS, and moderated
        if (!$img['ok']) { $this->jsonError($img['error']); }
        $url = S3Service::upload_file($key, $img['path'], $info['mime']);
        if ($url === '') {
            $this->jsonError('Could not save the image');
        }

        $column = ($kind === 'avatar') ? 'avatar_url' : 'cover_url';
        $profiles = new CreatorProfileModel();
        $old_webp = (string) ($profiles->get_for_user($user_id)[$kind . '_webp_url'] ?? '');
        $profiles->set_image($user_id, $column, $url);
        PublicThumbService::profile_image($user_id, $kind, $url, $old_webp);   // webp copies for the public page

        $this->jsonSuccess(['url' => $url, 'message' => ucfirst($kind) . ' updated']);
    }

    public function remove_creator_imageAction(){
        $this->require_creator('content', false);

        $kind = (string) ($this->post['kind'] ?? '');
        if (!in_array($kind, ['avatar', 'cover'], true)) {
            $this->jsonError('Invalid image type');
        }

        $user_id = Permissions::creator_id();
        $column  = ($kind === 'avatar') ? 'avatar_url' : 'cover_url';

        $model   = new CreatorProfileModel();
        $current = $model->get_for_user($user_id);
        $old_url = $current[$column] ?? '';

        $model->set_image($user_id, $column, '');
        if ($old_url !== '') {
            S3Service::delete_by_url($old_url);
        }
        PublicThumbService::profile_image($user_id, $kind, '', (string) ($current[$kind . '_webp_url'] ?? ''));   // drops the webp copies

        $this->jsonSuccess(['message' => ucfirst($kind) . ' removed']);
    }

    /* ---------- Creator external links ---------- */

    public function save_creator_linkAction(){
        $this->require_creator('content', false);
        $user_id = Permissions::creator_id();

        // POST text arrives HTML-encoded (clean_post_data); store it plain so /go redirects to the real URL.
        $dec   = function ($k) { return trim(html_entity_decode((string) ($this->post[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
        $title = $dec('title');
        $url   = $dec('url');
        $id    = (int) ($this->post['id'] ?? 0);

        if ($title === '') {
            $this->jsonError('A label is required');
        }
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->jsonError('Enter a valid URL (including https://)');
        }

        $model = new CreatorLinksModel();
        if ($id > 0) {
            if (!$model->get_one($user_id, $id)) {
                $this->jsonError('Link not found');
            }
            $model->update_link($user_id, $id, $title, $url);
        } else {
            $id = (int) $model->add($user_id, $title, $url);
        }

        $this->jsonSuccess(['message' => 'Link saved', 'id' => $id]);
    }

    /** Import Your Page, step 1: read a Linktree/Beacons/Stan (or any) page, or links pasted one per line. Saves nothing. */
    public function import_page_readAction(){
        $this->require_creator('content', false);
        $pasted = (string) ($this->post['pasted'] ?? '');
        if (trim($pasted) !== '') {
            $links = ProfileImport::pasted_links($pasted);
            if (empty($links)) { $this->jsonError('No links found. Put one address on each line.'); }
            $this->jsonSuccess(['page' => ['source' => 'pasted', 'name' => '', 'bio' => '', 'avatar' => '', 'links' => $links], 'existing' => $this->import_existing_urls()]);
        }
        $r = ProfileImport::read((string) ($this->post['url'] ?? ''));
        if (empty($r['ok'])) {
            $this->jsonError((string) $r['error'], ['blocked' => !empty($r['blocked'])]);
        }
        $this->jsonSuccess(['page' => $r, 'existing' => $this->import_existing_urls()]);
    }

    /** Import Your Page, step 2: save the pieces the creator ticked (name, bio, photo, links). Links already on the page are skipped. */
    public function import_page_applyAction(){
        $this->require_creator('content', false);
        $user_id = Permissions::creator_id();
        $plain = function ($k) { return trim(html_entity_decode((string) ($this->post[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
        $done = [];

        $profiles = new CreatorProfileModel();
        $cp = $profiles->get_for_user($user_id);
        $name = mb_substr($plain('name'), 0, 190);
        $bio  = mb_substr($plain('bio'), 0, 1000);
        if ($name !== '' || $bio !== '') {
            $profiles->save($user_id, [
                'display_name' => $name !== '' ? $name : html_entity_decode((string) ($cp['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'bio'          => $bio !== '' ? $bio : html_entity_decode((string) ($cp['bio'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'location'     => html_entity_decode((string) ($cp['location'] ?? ''), ENT_QUOTES, 'UTF-8'),
            ]);
            if ($name !== '') { $done[] = 'name'; }
            if ($bio !== '') { $done[] = 'bio'; }
        }

        $photo_error = '';
        $avatar = $plain('avatar');
        if ($avatar !== '') {
            $tmp = '';
            try {
                if (!S3Service::configured()) { throw new RuntimeException('Image uploads are not available right now'); }
                $img = MediaIngestService::fetch_image($avatar, 5 * 1024 * 1024, 20);
                $tmp = tempnam(sys_get_temp_dir(), 'imp');
                file_put_contents($tmp, $img['bytes']);
                $prep = ProfileImage::prepare($tmp, $img['mime']);   // no EXIF/GPS, and moderated
                if (!$prep['ok']) { throw new RuntimeException((string) $prep['error']); }
                $url = S3Service::upload_file('creator/u' . $user_id . '_avatar_' . bin2hex(random_bytes(8)) . '.' . $img['ext'], $prep['path'], $img['mime']);
                if ($url === '') { throw new RuntimeException('Could not save the photo'); }
                $old_webp = (string) ($cp['avatar_webp_url'] ?? '');
                $profiles->set_image($user_id, 'avatar_url', $url);
                PublicThumbService::profile_image($user_id, 'avatar', $url, $old_webp);
                $done[] = 'photo';
            } catch (\Throwable $e) {
                $photo_error = $e->getMessage();
            } finally {
                if ($tmp !== '' && is_file($tmp)) { @unlink($tmp); }
            }
        }

        $links = json_decode(html_entity_decode((string) ($this->post['links_json'] ?? ''), ENT_QUOTES, 'UTF-8'), true);
        $added = 0;
        if (is_array($links)) {
            $have = $this->import_existing_urls();
            $model = new CreatorLinksModel();
            foreach (array_slice($links, 0, ProfileImport::MAX_LINKS) as $l) {
                $title = mb_substr(trim((string) ($l['title'] ?? '')), 0, 120);
                $url = trim((string) ($l['url'] ?? ''));
                if ($title === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) { continue; }
                $k = self::import_url_key($url);
                if (in_array($k, $have, true)) { continue; }
                $model->add($user_id, $title, $url);
                $have[] = $k;
                $added++;
            }
        }
        if ($added > 0) { $done[] = $added . ($added === 1 ? ' link' : ' links'); }

        if (!empty($done)) { try { IndexNow::creator((int) $user_id); } catch (\Throwable $e) { error_log('[indexnow] import: ' . $e->getMessage()); } }
        if (empty($done)) { $this->jsonError($photo_error !== '' ? 'The photo could not be imported: ' . $photo_error : 'Nothing new to import.'); }
        $this->jsonSuccess(['message' => 'Imported ' . implode(', ', $done) . ($photo_error !== '' ? '. The photo could not be imported: ' . $photo_error : ''), 'photo_error' => $photo_error]);
    }

    /** Addresses already on this creator's page, normalized for the import duplicate check. */
    private function import_existing_urls(){
        $keys = [];
        foreach ((array) (new CreatorLinksModel())->get_for_user(Permissions::creator_id()) as $l) { $keys[] = self::import_url_key((string) $l['url']); }
        return $keys;
    }

    private static function import_url_key($url){
        return strtolower(rtrim(preg_replace('#^https?://(www\.)?#i', '', trim($url)), '/'));
    }

    public function delete_creator_linkAction(){
        $this->require_creator('content', false);
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Link is required');
        }
        (new CreatorLinksModel())->delete_link(Permissions::creator_id(), $id);
        $this->jsonSuccess(['message' => 'Link removed']);
    }

    /** New inbound tracking link (Dashboard > Audience): {label, code?, target_path?}. The code is generated when left empty. */
    public function tracking_link_saveAction(){
        $this->require_creator('content', false);
        $dec   = function ($k) { return trim(html_entity_decode((string) ($this->post[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
        $label = mb_substr($dec('label'), 0, 120);
        $code  = strtolower($dec('code'));
        $path  = $dec('target_path');
        if ($label === '') { $this->jsonError('A label is required'); }
        $clean = TrackingLinks::clean_path($path);
        if ($path !== '' && $path !== '/' && $clean === '') { $this->jsonError('The page path can only use letters, numbers, dashes and slashes, like /links'); }
        $model = new TrackingLinksModel();
        if ($code === '') {
            do { $code = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8); } while (!preg_match('/[a-z]/', $code) || strpos($code, 'cls') === 0 || $model->code_exists($code));
        } elseif (ctype_digit($code)) {
            $this->jsonError('Codes need at least one letter');
        } elseif (!TrackingLinks::valid_code($code) || strpos($code, 'cls') === 0) {
            $this->jsonError('The code must be 6 to 32 letters or numbers and can\'t start with "cls"');
        } elseif ($model->code_exists($code)) {
            $this->jsonError('That code is taken. Try another.');
        }
        $id = $model->add(Permissions::creator_id(), $code, $label, $clean);
        if ($id <= 0) { $this->jsonError('Could not create the link'); }
        $this->jsonSuccess(['message' => 'Tracking link created', 'id' => $id, 'url' => rtrim(Main::get_base_domain(), '/') . '/go/' . $code]);
    }

    public function tracking_link_deleteAction(){
        $this->require_creator('content', false);
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Link is required'); }
        (new TrackingLinksModel())->delete_link(Permissions::creator_id(), $id);
        $this->jsonSuccess(['message' => 'Tracking link deleted']);
    }

    public function toggle_creator_linkAction(){
        $this->require_creator('content', false);
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Link is required');
        }
        (new CreatorLinksModel())->set_enabled(Permissions::creator_id(), $id, !empty($this->post['enabled']));
        $this->jsonSuccess(['message' => 'Link updated']);
    }

    public function reorder_creator_linksAction(){
        $this->require_creator('content', false);
        $ids = $this->post['ids'] ?? [];
        if (!is_array($ids)) {
            $this->jsonError('Invalid order');
        }
        (new CreatorLinksModel())->reorder(Permissions::creator_id(), $ids);
        $this->jsonSuccess(['message' => 'Order saved']);
    }

    public function scheduler_listAction(){
        $user       = $this->require_creator('view', false);
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $out        = [];
        $locked     = Plan::locked_ids($user, 'automations');
        foreach ((new SchedulerRulesModel())->list_for_creator($creator_id) as $r) {
            $out[] = $this->scheduler_rule_json($r, $tz) + ['locked' => in_array((int) $r['id'], $locked, true)];
        }
        $cap = Plan::check_count($user, 'automations', count($out));
        $lim = Plan::limit($user, 'automations');
        $up  = PlanTiers::lowest_including('automations');
        $this->jsonSuccess(['rules' => $out, 'can_social' => Plan::can_social_post($user),
            'can_create' => !empty($cap['ok']), 'included' => $lim !== null && (int) $lim >= 0,
            'limit_message' => (string) ($cap['message'] ?? ''), 'upgrade_name' => $up ? (string) $up['name'] : '']);
    }

    public function scheduler_saveAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $name       = trim(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES));
        $topic      = trim(html_entity_decode((string) ($this->post['topic'] ?? ''), ENT_QUOTES));
        $kind       = (($this->post['kind'] ?? 'post') === 'message') ? 'message' : 'post';
        $msg_text   = trim(html_entity_decode((string) ($this->post['message_text'] ?? ''), ENT_QUOTES));
        $msg_ai     = !empty($this->post['message_ai']) ? 1 : 0;
        $targets    = $this->post['message_targets'] ?? [];
        if (is_string($targets)) { $targets = json_decode(html_entity_decode($targets, ENT_QUOTES, 'UTF-8'), true) ?: []; }
        if ($name === '')  { $this->jsonError('Give your automation a name.'); }
        if ($kind === 'message') {
            $t = SchedulerRulesModel::targets(['message_targets' => json_encode((array) $targets)]);
            if (empty($t['cls'])) { $this->jsonError('Pick who receives the message.'); }
            if ($msg_ai && $topic === '')   { $this->jsonError('Tell the AI what the message is about (the topic).'); }
            if (!$msg_ai && $msg_text === '') { $this->jsonError('Write the message, or let AI write it from a topic.'); }
        } elseif ($topic === '') { $this->jsonError('Describe what to post (the topic).'); }

        $src           = (string) ($this->post['image_source'] ?? 'brand');
        $image_source  = ($src === 'influencer') ? 'influencer' : 'brand';   // anything else (incl. the removed Eromify 'character') is Brand
        $influencer_id = (int) ($this->post['influencer_id'] ?? 0);
        if ($kind === 'post' && $image_source === 'influencer') {
            $infl = $influencer_id > 0 ? (new InfluencersModel())->get_one($creator_id, $influencer_id) : null;
            if (!$infl) { $this->jsonError('Pick an influencer.'); }
            if ((string) $infl['status'] !== 'ready' || empty($infl['active_model_id'])) { $this->jsonError($infl['name'] . ' is not trained yet.'); }
        }
        $fields = [
            'image_source'     => $image_source,
            'influencer_id'    => $influencer_id,
            'influencer_model_key' => (string) ($this->post['influencer_model_key'] ?? ''),
            'media_type'       => (($this->post['media_type'] ?? 'image') === 'video') ? 'video' : 'image',
            'video_prompt'     => trim(html_entity_decode((string) ($this->post['video_prompt'] ?? ''), ENT_QUOTES)),
            'video_model_key'  => (string) ($this->post['video_model_key'] ?? ''),
            'video_duration'   => (string) ($this->post['video_duration'] ?? ''),
            'content_level'    => 'safe',
            'kind'             => $kind,
            'message_text'     => $msg_text,
            'message_ai'       => $msg_ai,
            'message_targets'  => (array) $targets,
            'name'             => $name,
            'topic'            => $topic,
            'active'           => ((string) ($this->post['active'] ?? '1')) !== '0',
            'size'             => (string) ($this->post['size'] ?? 'portrait'),
            'comments_enabled' => ((string) ($this->post['comments_enabled'] ?? '1')) !== '0',
            'on_cls'           => ((string) ($this->post['on_cls'] ?? '1')) !== '0' ? 1 : 0,
            'use_brand'        => ((string) ($this->post['use_brand'] ?? '1')) !== '0',
            'ai_assist'        => ((string) ($this->post['ai_assist'] ?? '1')) !== '0' ? 1 : 0,
            'caption_mode'     => (string) ($this->post['caption_mode'] ?? 'standard'),
            'caption_text'     => trim(html_entity_decode((string) ($this->post['caption_text'] ?? ''), ENT_QUOTES)),
            'social_accounts'  => $this->post['social_accounts'] ?? [],
            'cadence'          => (string) ($this->post['cadence'] ?? 'daily'),
            'days_of_week'     => $this->post['days_of_week'] ?? [],
            'run_time'         => (string) ($this->post['run_time'] ?? '09:00'),
            'timezone'         => $this->valid_tz($this->post['timezone'] ?? '') ?: (string) ($user['content_timezone'] ?? 'UTC'),
        ];
        if (SchedulerRulesModel::weekly_without_days($fields)) { $this->jsonError('Pick at least one day for a weekly automation.'); }
        if ($kind !== 'message' && empty($fields['on_cls']) && empty(array_filter((array) $fields['social_accounts']))) { $this->jsonError('Pick at least one place to publish: Creator Link Studio or a social account.'); }
        $model = new SchedulerRulesModel();
        if ($id > 0 && $model->get_one($creator_id, $id)) {
            $model->update_rule($creator_id, $id, $fields);
        } else {
            $cap = Plan::check_count($user, 'automations', $model->count_for_creator($creator_id));
            if (empty($cap['ok'])) { $this->limitError($cap); }
            $id = (int) $model->create($creator_id, $fields);
        }
        $this->jsonSuccess(['message' => 'Automation saved', 'id' => $id]);
    }

    public function scheduler_toggleAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $active     = ((string) ($this->post['active'] ?? '0')) === '1';
        $this->plan_to_turn_on($user, $active);
        if ($active && Plan::is_locked($user, 'automations', (int) ($this->post['id'] ?? 0))) {
            $this->jsonError(Plan::locked_message($user, 'automations'), ['need_upgrade' => true]);
        }
        (new SchedulerRulesModel())->set_active($creator_id, (int) ($this->post['id'] ?? 0), $active);
        $this->jsonSuccess(['active' => $active ? 1 : 0]);
    }

    public function scheduler_deleteAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        (new SchedulerRulesModel())->delete_rule($creator_id, (int) ($this->post['id'] ?? 0));
        $this->jsonSuccess(['message' => 'Automation removed']);
    }

    /** Run an automation immediately (test path) — same pipeline the worker uses. */
    public function scheduler_run_nowAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $rule       = (new SchedulerRulesModel())->get_one($creator_id, (int) ($this->post['id'] ?? 0));
        if (!$rule) { $this->jsonError('Automation not found'); }
        if (Plan::is_locked($user, 'automations', (int) $rule['id'])) {
            $this->jsonError(Plan::locked_message($user, 'automations'), ['need_upgrade' => true]);
        }
        $prev  = (new SchedulerRunsModel())->recent_for_rule((int) $rule['id'], 1);
        $since = (int) ($prev[0]['id'] ?? 0);
        // Runs take up to a minute (image generation, cross-posting): hand it to the queue worker and
        // answer now. The page polls scheduler_run_status until a run row newer than `since` appears.
        $job_id = (new DatabaseJobQueue())->dispatch('scheduler_run',
            ['rule_id' => (int) $rule['id'], 'creator_id' => $creator_id],
            'scheduler_run:' . (int) $rule['id']);
        if ($job_id <= 0) { $this->jsonError('This automation is already running. Give it a minute.'); }
        $this->jsonSuccess(['queued' => true, 'since' => $since, 'job_id' => $job_id, 'message' => 'Running…']);
    }

    /** Poll for the outcome of a "Run now": the newest run created after $since. */
    public function scheduler_run_statusAction(){
        $user       = $this->require_creator('view');
        $creator_id = (int) $user['user_id'];
        $rule       = (new SchedulerRulesModel())->get_one($creator_id, (int) ($this->post['id'] ?? 0));
        if (!$rule) { $this->jsonError('Automation not found'); }
        $since = (int) ($this->post['since'] ?? 0);
        $latest = (new SchedulerRunsModel())->recent_for_rule((int) $rule['id'], 1);
        $run = (is_array($latest) && !empty($latest) && (int) $latest[0]['id'] > $since) ? $latest[0] : null;
        // A video automation's run ends 'rendering' (the post publishes when the video lands): that's a successful start.
        $this->jsonSuccess(['done' => $run !== null, 'ok' => $run ? in_array($run['status'], ['success', 'rendering'], true) : null,
            'rendering' => $run !== null && $run['status'] === 'rendering', 'message' => $run ? (string) $run['message'] : '', 'post_id' => $run ? ($run['post_id'] !== null ? (int) $run['post_id'] : null) : null]);
    }

    /** Create or update a draft (also powers autosave). */
    /**
     * Write a caption for the post being composed. The subject comes from what the post shows:
     * the influencer scene prompt behind each generated asset, asset descriptions and tags, or
     * the creator's own words in the box. Returns text only; nothing is saved.
     */
    public function post_caption_autoAction(){
        $user       = $this->require_creator('content', 'ai');
        $creator_id = (int) $user['user_id'];
        if (!ClaudeService::configured()) { $this->jsonError('Caption writing is not available right now.'); }
        $ids  = array_map('intval', (array) ($this->post['asset_ids'] ?? []));
        $hint = mb_substr(trim(html_entity_decode((string) ($this->post['hint'] ?? ''), ENT_QUOTES, 'UTF-8')), 0, 500);

        $parts = array(); $who = ''; $kinds = array(); $infl_id = 0;
        $media = new MediaAssetsModel(); $links = new InfluencerImagesModel(); $jobs = new InfluencerJobsModel();
        foreach (array_slice($ids, 0, 4) as $aid) {
            $a = $media->get_one($creator_id, $aid);
            if (!$a) { continue; }
            $kinds[(string) $a['type']] = true;
            $link = $links->get_by_asset($creator_id, $aid);
            if ($link) {
                $who = (string) $link['influencer_name']; $infl_id = (int) ($link['influencer_id'] ?? 0);
                $job = !empty($link['job_id']) ? $jobs->get_by_id((int) $link['job_id']) : null;
                $p   = $job ? InfluencerJobsModel::params($job) : array();
                $scene = trim((string) ($p['scene'] ?? ($p['user_prompt'] ?? '')));
                if ($scene === '' && $job) { $scene = trim(preg_replace('/\binfl_\d+_\w+\b/', '', (string) $job['prompt'])); }
                if ($scene !== '') { $parts[] = $scene; }
            }
            if (trim((string) ($a['description'] ?? '')) !== '') { $parts[] = trim((string) $a['description']); }
            elseif (trim((string) ($a['tags'] ?? '')) !== '' && strpos((string) $a['tags'], 'influencer:') !== 0) { $parts[] = str_replace(',', ', ', (string) $a['tags']); }
        }
        $parts = array_values(array_unique(array_filter($parts)));
        $topic = $hint !== '' ? $hint : implode('; ', array_slice($parts, 0, 3));
        if ($topic === '') { $topic = !empty($kinds['video']) ? 'a new video for my followers' : 'a new photo for my followers'; }
        // An influencer's posts read as their own words, not a caption about them.
        if ($who !== '') { $topic .= '. Write it in the first person as ' . $who . ' talking to their followers, never describing ' . $who . ' in the third person'; }
        if ($hint !== '' && !empty($parts)) { $topic .= ' (the post shows: ' . implode('; ', array_slice($parts, 0, 2)) . ')'; }

        $cb = (new CreatorBrandModel())->get_for_user($creator_id);
        $style = '';
        // Her persona (when the media is an influencer's) and the kind of caption asked for.
        $infl    = $infl_id > 0 ? (new InfluencersModel())->get_one($creator_id, $infl_id) : null;
        $mode    = BrandService::caption_mode((string) ($this->post['caption_mode'] ?? 'standard'));
        if ($mode === 'hook_overlay' && empty($kinds['video'])) { $mode = 'standard'; }   // the on-video line needs a video to sit on
        $caption = BrandService::caption_for($topic, (array) $cb, $style, array(), array(
            'mode' => $mode, 'persona' => InfluencerService::persona_block($infl)));
        if ($caption === '') {
            $why = (string) BrandService::$last_error;
            $busy = stripos($why, 'Overloaded') !== false || strpos($why, 'HTTP 529') !== false || strpos($why, 'HTTP 429') !== false;
            $this->jsonError($busy ? 'The AI is busy right now. Try again in a few seconds.' : 'Could not write a caption right now.');
        }
        $this->jsonSuccess(['caption' => mb_substr($caption, 0, 3000), 'hook' => (string) BrandService::$last_hook]);
    }

    public function post_saveAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $caption    = html_entity_decode((string) ($this->post['caption'] ?? ''), ENT_QUOTES, 'UTF-8');
        $comments   = (((string) ($this->post['comments_enabled'] ?? '1')) === '1') ? 1 : 0;
        $on_cls     = (((string) ($this->post['on_cls'] ?? '1')) === '0') ? 0 : 1;   // publish on Creator Link Studio itself
        $asset_ids  = $this->post['asset_ids'] ?? [];
        if (!is_array($asset_ids)) { $asset_ids = []; }
        $asset_ids  = array_values(array_map('intval', $asset_ids));
        $cover_id   = (int) ($this->post['cover_id'] ?? 0);

        // Don't create an empty draft: a brand-new post with no caption and no media
        // isn't worth saving yet (avoids junk drafts from just toggling options).
        if ($id === 0 && trim($caption) === '' && empty($asset_ids)) {
            $this->jsonSuccess(['id' => 0, 'post' => [
                'id' => 0, 'caption' => '', 'comments_enabled' => $comments, 'on_cls' => $on_cls, 'state' => 'draft',
                'scheduled_local' => '', 'timezone' => (string) ($user['content_timezone'] ?? 'UTC'),
                'assets' => [], 'cover_display_url' => '', 'cover_blurred_url' => '',
                'validation' => ['ok' => false, 'reason' => 'Add a photo, video, or caption before publishing.'],
            ]]);
        }

        $model  = new PostsModel();
        $fields = ['caption' => $caption, 'comments_enabled' => $comments, 'on_cls' => $on_cls];
        // If the post was removed elsewhere while the composer had it open, don't
        // hard-fail — fall back to creating a fresh draft so nothing is lost.
        if ($id > 0 && !$model->get_one($creator_id, $id)) { $id = 0; }
        $stored  = $id > 0 ? $model->get_one($creator_id, $id) : null;
        $is_live = $stored && in_array((string) $stored['state'], array('published', 'scheduled'), true);
        if ($id > 0) {
            $model->update_fields($creator_id, $id, $fields);
        } else {
            $id = (int) $model->create_draft($creator_id, $caption);
            $model->update_fields($creator_id, $id, $fields);
        }
        $model->set_assets($creator_id, $id, $asset_ids, $cover_id);
        // Editing a live post re-runs the content check: flagged media added to it sends it back to drafts.
        if ($is_live) { ModerationHold::hold($creator_id, $id, true); }
        $post = $model->get_one($creator_id, $id);
        $json = $this->studio_post_json($post, $user);
        $json['validation'] = $this->post_validation($post);
        $this->jsonSuccess(['id' => $id, 'post' => $json]);
    }

    /** Load a post (edit / reopen draft). */
    public function post_getAction(){
        $user       = $this->require_creator('view', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $post       = (new PostsModel())->get_one($creator_id, $id);
        if (!$post) { $this->jsonError('That post was not found.'); }
        $json = $this->studio_post_json($post, $user);
        $json['validation'] = $this->post_validation($post);
        $this->jsonSuccess(['post' => $json]);
    }

    /** The creator's most recent open draft (for the "resume draft?" offer). */
    public function post_open_draftAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $draft      = (new PostsModel())->get_open_draft($creator_id);
        if (!$draft) { $this->jsonSuccess(['draft' => null]); }
        // Only offer it if it actually has content worth resuming.
        $json = $this->studio_post_json($draft, $user);
        $has = trim((string) $draft['caption']) !== '' || !empty($json['assets']);
        $this->jsonSuccess(['draft' => $has ? $json : null]);
    }

    /**
     * The content check: a post with media the scanner flagged is not published or scheduled. It stays in drafts
     * (held, with a notice linking to it) until staff clear the image from the admin Moderation queue.
     */
    private function require_clear_media(int $creator_id, int $post_id): void{
        if (!ModerationHold::hold($creator_id, $post_id)) { return; }
        $blocked = (new PostsModel())->media_hold_state($post_id) === 'blocked';
        $this->jsonError($blocked ? 'This media was blocked by our content check and cannot be published. Remove it to continue.'
            : 'An image in this post was flagged by our content check, so it stays in your drafts. Remove the image, or wait for our team to review it.',
            ['held' => true, 'held_reason' => ModerationHold::HELD_REASON, 'state' => 'draft']);
    }

    public function post_publishAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $post       = $model->get_one($creator_id, $id);
        if (!$post) { $this->jsonError('That post was not found.'); }
        $this->require_clear_media($creator_id, $id);   // flagged media: stays in drafts
        $v = $this->post_validation($post);
        if (!$v['ok']) { $this->jsonError((string) ($v['reason'])); }
        $shares = $this->share_accounts_from_request();
        if (empty($post['on_cls']) && empty($shares)) { $this->jsonError('Pick at least one place to publish: Creator Link Studio or a social account.'); }
        $this->require_caption_for_shares($post, $shares);
        $post = $this->save_share_options($model, $creator_id, $post);
        if (!$model->publish_once($creator_id, $id)) {   // already published (double-click / retry): don't fan out again
            $this->jsonSuccess(['message' => 'Published', 'state' => 'published']);
        }
        if (!empty($post['on_cls'])) { PostNotifier::published($creator_id, $id); }   // socials-only posts don't notify followers
        $share_error = $this->share_post_to_social($user, $post, $shares, null);
        if (!Plan::can_use_creator_features($user)) {   // Free: saved, but the page is not public yet (studio.js shows it with the plan button)
            $this->jsonSuccess(['message' => 'Saved. Your page goes public when you start Creator.', 'state' => 'published', 'share_error' => $share_error, 'upgrade' => UpgradePrompt::payload($user, '/studio')]);
        }
        $this->jsonSuccess(['message' => 'Published', 'state' => 'published', 'share_error' => $share_error]);
    }

    public function post_scheduleAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $post       = $model->get_one($creator_id, $id);
        if (!$post) { $this->jsonError('That post was not found.'); }
        $this->require_clear_media($creator_id, $id);   // flagged media: stays in drafts
        $v = $this->post_validation($post);
        if (!$v['ok']) { $this->jsonError((string) ($v['reason'])); }
        $utc = $this->to_utc((string) ($this->post['scheduled_at'] ?? ''), (string) ($user['content_timezone'] ?? 'UTC'));
        if (!$utc || strtotime($utc) <= time()) {
            $this->jsonError('Pick a date and time in the future.');
        }
        $shares = $this->share_accounts_from_request();
        if (empty($post['on_cls']) && empty($shares)) { $this->jsonError('Pick at least one place to publish: Creator Link Studio or a social account.'); }
        $this->require_caption_for_shares($post, $shares);
        $post = $this->save_share_options($model, $creator_id, $post);
        $model->set_state($creator_id, $id, 'scheduled', $utc);
        // $utc is already 'Y-m-d H:i:s' in UTC — build the ISO directly (strtotime would
        // misread it in the server's America/New_York default zone and send a wrong time).
        $share_error = $this->share_post_to_social($user, $post, $shares, str_replace(' ', 'T', $utc) . 'Z');
        $this->jsonSuccess(['message' => 'Scheduled', 'state' => 'scheduled', 'share_error' => $share_error]);
    }

    public function post_save_draftAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        if (!$model->get_one($creator_id, $id)) { $this->jsonError('That post was not found.'); }
        $model->set_state($creator_id, $id, 'draft');
        $this->jsonSuccess(['message' => 'Saved as draft', 'state' => 'draft']);
    }

    /** Archive / unarchive a post. */
    public function post_archiveAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $unarchive  = ((string) ($this->post['unarchive'] ?? '0')) === '1';
        $model      = new PostsModel();
        if (!$model->get_one($creator_id, $id)) { $this->jsonError('That post was not found.'); }
        $model->set_state($creator_id, $id, $unarchive ? 'draft' : 'archived');
        $this->jsonSuccess(['message' => $unarchive ? 'Moved to drafts' : 'Archived']);
    }

    public function post_duplicateAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $new_id     = (int) (new PostsModel())->duplicate($creator_id, $id);
        if ($new_id <= 0) { $this->jsonError('That post was not found.'); }
        $this->jsonSuccess(['id' => $new_id, 'message' => 'Duplicated to a new draft']);
    }

    public function post_deleteAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $model->delete_post($creator_id, $id);
        $this->jsonSuccess(['message' => 'Post removed']);
    }

    /** Share an existing post to selected connected accounts (from the Posts list). */
    public function post_shareAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $post       = (new PostsModel())->get_one($creator_id, $id);
        if (!$post) { $this->jsonError('That post was not found.'); }
        if (!Plan::can_social_post($user)) { $this->jsonError('Sharing to social is not part of your current plan.'); }
        $accounts = $this->share_accounts_from_request();
        if (empty($accounts)) { $this->jsonError('Pick at least one account.'); }
        $req = array_map('strval', $accounts); $n = 0;
        foreach ((new SocialAccountsModel())->get_connected_for_user($creator_id) as $a) {
            if (in_array((string) $a['post_for_me_social_account_id'], $req, true)) { $n++; }
        }
        if ($n === 0) { $this->jsonError('Those accounts are not connected.'); }
        $this->require_caption_for_shares($post, $accounts);
        $post = $this->save_share_options(new PostsModel(), $creator_id, $post);
        $share_error = $this->share_post_to_social($user, $post, $accounts, null);
        if ($share_error !== '' && strpos($share_error, 'Not shared') === 0) { $this->jsonError($share_error); }
        $this->jsonSuccess(['message' => 'Shared to ' . $n . ' account' . ($n > 1 ? 's' : '') . '.', 'share_error' => $share_error]);
    }

    /* ---------- Platform media checks (MediaCompliance), run in the background as the composer changes ---------- */

    /**
     * Class the composer's media for each chosen destination and start any versions that are missing. Takes the composer's
     * current state (it may not be saved yet): asset_ids (cover first), share_accounts, story_accounts
     * ("<id>" Story, "both:<id>" Feed and Story). Returns per destination the state and, per image, the version.
     */
    public function post_media_checkAction(){
        $user = $this->require_creator('content', false);
        $cid  = (int) $user['user_id'];
        $ids  = array_values(array_unique(array_filter(array_map('intval', (array) ($this->post['asset_ids'] ?? [])))));
        $mm   = new MediaAssetsModel();
        $rows = [];
        foreach ($ids as $i => $id) {
            $a = $mm->get_one($cid, $id);
            if (!$a || (string) $a['status'] !== 'ready') { continue; }
            $rows[] = $a + ['asset_id' => (int) $a['id'], 'is_cover' => $i === 0 ? 1 : 0];
        }
        $post  = ['audience' => 'free'];
        $items = SocialShareService::items_for_post($post, $rows);
        $req   = array_map('strval', (array) ($this->post['share_accounts'] ?? []));
        $place = SocialShareService::placements(is_array($this->post['story_accounts'] ?? null) ? implode(',', $this->post['story_accounts']) : (string) ($this->post['story_accounts'] ?? ''));
        $dests = [];
        foreach ((new SocialAccountsModel())->get_connected_for_user($cid) as $a) {
            $id = (string) $a['post_for_me_social_account_id'];
            if (!in_array($id, $req, true)) { continue; }
            $p = strtolower((string) $a['platform']); $story_ok = in_array($p, AiDisclosure::STORY_PLATFORMS, true);
            $pls = ($story_ok && in_array($id, $place['story'], true)) ? ['story'] : (($story_ok && in_array($id, $place['both'], true)) ? ['feed', 'story'] : ['feed']);
            foreach ($pls as $pl) { $dests[] = ['id' => $id, 'platform' => $p, 'placement' => $pl, 'username' => (string) ($a['username'] ?? '')]; }
        }
        $r = empty($items) ? ['destinations' => [], 'busy' => false, 'review' => 0] : MediaCompliance::check($cid, $items, $dests);
        $this->jsonSuccess($r + ['asset_ids' => array_values(array_map(function ($i) { return (int) $i['asset']['id']; }, $items))]);
    }

    /** Approve a proposed version (its picture changes shape): it may now be published. */
    public function media_variant_approveAction(){
        $user = $this->require_creator('content', false);
        $r = MediaCompliance::approve((int) $user['user_id'], (int) ($this->post['id'] ?? 0));
        if (empty($r['ok'])) { $this->jsonError($r['error']); }
        $this->jsonSuccess(['variant' => $r['variant']]);
    }

    /** Make the version again another way: fit (whole picture on a blurred fill) or crop (top | center | bottom). */
    public function media_variant_redoAction(){
        $user = $this->require_creator('content', false);
        $cid  = (int) $user['user_id'];
        $a = (new MediaAssetsModel())->get_one($cid, (int) ($this->post['asset_id'] ?? 0));
        if (!$a || (string) $a['status'] !== 'ready') { $this->jsonError('That file is not ready.'); }
        $method = ((string) ($this->post['method'] ?? 'fit') === 'crop') ? 'crop' : 'fit';
        $focus  = in_array((string) ($this->post['focus'] ?? 'center'), ['top', 'center', 'bottom'], true) ? (string) $this->post['focus'] : 'center';
        $platform = strtolower((string) ($this->post['platform'] ?? '')); $placement = ((string) ($this->post['placement'] ?? 'feed') === 'story') ? 'story' : 'feed';
        $teaser = (string) ($this->post['teaser'] ?? '0') === '1';
        if ((string) $a['type'] === 'video') {   // a video takes a while: in the background, the composer keeps checking
            MediaCompliance::start_variant($cid, (int) $a['id'], $teaser, $platform, $placement, $method, $focus);
            $this->jsonSuccess(['variant' => ['id' => 0, 'state' => 'processing']]);
        }
        $v = MediaCompliance::make_variant($cid, $a, $teaser, $platform, $placement, $method, $focus);
        if (!$v) { $this->jsonError('That version could not be made. Try again.'); }
        $this->jsonSuccess(['variant' => MediaCompliance::variant_json($cid, $v)]);
    }

    /* ---------- Content Studio: posts list ---------- */

    public function posts_listAction(){
        $user       = $this->require_creator('view', false);
        $creator_id = (int) $user['user_id'];
        $model      = new PostsModel();
        foreach ($model->publish_due($creator_id) as $pid => $cid) { if (!ModerationHold::hold((int) $cid, (int) $pid)) { PostNotifier::published($cid, $pid); } }   // cron fallback: flip now-due scheduled posts
        $filters = ['state' => (string) ($this->post['state'] ?? ''), 'search' => (string) ($this->post['search'] ?? '')];
        $rows = $model->list_for_creator($creator_id, $filters);
        $posts = [];
        $mod_map   = $model->moderation_map(array_map(function ($p) { return (int) $p['id']; }, $rows));
        foreach ($rows as $p) { $posts[] = $this->studio_post_row($p, $user, $mod_map); }
        $this->jsonSuccess(['posts' => $posts, 'counts' => $model->counts_by_state($creator_id)]);
    }

    /** Scheduled + published posts placed on their local date, plus queue health. */
    public function posts_calendarAction(){
        $user       = $this->require_creator('view', false);
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $model      = new PostsModel();
        foreach ($model->publish_due($creator_id) as $pid => $cid) { if (!ModerationHold::hold((int) $cid, (int) $pid)) { PostNotifier::published($cid, $pid); } }

        $items = [];
        $furthest = null; $scheduled_count = 0;
        foreach ($model->list_for_creator($creator_id, []) as $p) {
            $when_utc = ($p['state'] === 'scheduled') ? ($p['scheduled_at'] ?? '') : (($p['state'] === 'published') ? ($p['published_at'] ?? '') : '');
            if ($when_utc === '' || $when_utc === null) { continue; }
            try {
                $d = new DateTime((string) $when_utc, new DateTimeZone('UTC'));
                $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            } catch (\Throwable $e) { continue; }
            $cover = '';
            if (!empty($p['cover_thumb_key'])) {
                $cover = MediaService::signed_url(['creator_id' => $creator_id, 'type' => $p['cover_type'], 'thumb_key' => $p['cover_thumb_key']], 'thumb', $creator_id);
            }
            $cap = trim((string) $p['caption']);
            $items[] = [
                'id'         => (int) $p['id'],
                'state'      => $p['state'],
                'caption'    => ($cap === '') ? '' : mb_substr($cap, 0, 60),
                'cover_url'  => $cover,
                'cover_type' => (string) ($p['cover_type'] ?? ''),
                'date'       => $d->format('Y-m-d'),
                'time'       => $d->format('g:i A'),
                'iso'        => $d->format('Y-m-d\TH:i'),
                'media_missing' => (int) $p['media_missing'],
            ];
            if ($p['state'] === 'scheduled') {
                $scheduled_count++;
                if ($furthest === null || $when_utc > $furthest) { $furthest = $when_utc; }
            }
        }

        // Upcoming automations — expand each active rule into its occurrences over the
        // next ~2 months so a daily rule shows on every day, weekly on each matching day.
        $rulesModel = new SchedulerRulesModel();
        $horizon    = (new DateTime('now', new DateTimeZone('UTC')))->modify('+62 days');
        foreach ($rulesModel->list_for_creator($creator_id) as $rule) {
            if ((int) $rule['active'] !== 1 || empty($rule['next_run_at'])) { continue; }
            try { $cursor = new DateTime((string) $rule['next_run_at'], new DateTimeZone('UTC')); }
            catch (\Throwable $e) { continue; }
            for ($guard = 0; $cursor <= $horizon && $guard < 90; $guard++) {
                $local = (clone $cursor)->setTimezone(new DateTimeZone($tz ?: 'UTC'));
                $items[] = [
                    'id'            => 0,
                    'rule_id'       => (int) $rule['id'],
                    'state'         => 'automation',
                    'caption'       => (string) $rule['name'],
                    'cover_url'     => '',
                    'cover_type'    => '',
                    'date'          => $local->format('Y-m-d'),
                    'time'          => $local->format('g:i A'),
                    'iso'           => $local->format('Y-m-d\TH:i'),
                    'media_missing' => 0,
                ];
                try { $cursor = new DateTime($rulesModel->compute_next_run($rule, $cursor), new DateTimeZone('UTC')); }
                catch (\Throwable $e) { break; }
            }
        }

        $queue = ['scheduled_count' => $scheduled_count, 'days_ahead' => 0, 'reaches' => ''];
        if ($furthest !== null) {
            try {
                $f = new DateTime((string) $furthest, new DateTimeZone('UTC'));
                $f->setTimezone(new DateTimeZone($tz ?: 'UTC'));
                $today = new DateTime('now', new DateTimeZone($tz ?: 'UTC'));
                $queue['reaches']    = $f->format('M j, Y');
                $queue['days_ahead'] = (int) $today->diff($f)->days;
            } catch (\Throwable $e) {}
        }
        $this->jsonSuccess(['items' => $items, 'queue' => $queue, 'timezone' => $tz]);
    }

    public function posts_bulkAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $action     = (string) ($this->post['bulk_action'] ?? '');
        $ids        = $this->post['ids'] ?? [];
        if (!is_array($ids)) { $ids = []; }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) { $this->jsonError('No posts were selected.'); }
        $model = new PostsModel();
        $done = 0;
        foreach ($ids as $id) {
            if (!$model->get_one($creator_id, $id)) { continue; }
            if ($action === 'archive') { $model->set_state($creator_id, $id, 'archived'); $done++; }
            elseif ($action === 'delete') { $model->delete_post($creator_id, $id); $done++; }
        }
        if ($done === 0) { $this->jsonError('Nothing to update.'); }
        $msg = $done . ' post' . ($done > 1 ? 's' : '') . ($action === 'delete' ? ' removed' : ' archived');
        $this->jsonSuccess(['message' => $msg]);
    }

    /* ---------- Payouts (Stripe Connect) ---------- */

    // ---------- Scheduler (automations) ----------

    private function cadence_summary(array $r): string{
        $t = date('g:i A', strtotime('2000-01-01 ' . (string) $r['run_time']));
        if (($r['cadence'] ?? 'daily') === 'weekly') {
            $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $days  = array_filter(array_map('intval', explode(',', (string) $r['days_of_week'])), function ($d) { return $d >= 0 && $d <= 6; });
            $lbl   = empty($days) ? 'Weekly' : implode(', ', array_map(function ($d) use ($names) { return $names[$d]; }, $days));
            return $lbl . ' · ' . $t;
        }
        return 'Daily · ' . $t;
    }

    private function scheduler_rule_json(array $r, string $tz): array{
        $accounts = json_decode((string) ($r['social_accounts'] ?? '[]'), true) ?: [];
        $days     = array_values(array_filter(array_map('intval', explode(',', (string) $r['days_of_week'])), function ($d) { return $d >= 0 && $d <= 6; }));
        $cost = Plan::automation_credits($r);
        return [
            'id'               => (int) $r['id'],
            'credits_per_run'  => $cost['per_run'],     // AI credits each run uses
            'credits_per_month'=> $cost['per_month'],   // at this cadence
            'kind'             => (($r['kind'] ?? 'post') === 'message') ? 'message' : 'post',
            'image_source'     => (($r['image_source'] ?? 'brand') === 'influencer') ? 'influencer' : 'brand',
            'influencer_id'    => (int) ($r['influencer_id'] ?? 0),
            'influencer_name'  => $this->scheduler_influencer_name($r),
            'influencer_model_key' => (string) ($r['influencer_model_key'] ?? ''),
            'media_type'       => ((string) ($r['media_type'] ?? 'image') === 'video') ? 'video' : 'image',
            'video_prompt'     => (string) ($r['video_prompt'] ?? ''),
            'video_model_key'  => (string) ($r['video_model_key'] ?? ''),
            'video_duration'   => (string) ($r['video_duration'] ?? ''),
            'content_level'    => 'safe',
            'message_text'     => (string) ($r['message_text'] ?? ''),
            'message_ai'       => (int) ($r['message_ai'] ?? 0),
            'message_targets'  => SchedulerRulesModel::targets($r),
            'name'             => (string) $r['name'],
            'active'           => (int) $r['active'],
            'topic'            => (string) $r['topic'],
            'size'             => (string) $r['size'],
            'comments_enabled' => (int) $r['comments_enabled'],
            'on_cls'           => isset($r['on_cls']) ? (int) $r['on_cls'] : 1,
            'use_brand'        => (int) $r['use_brand'],
            'ai_assist'        => isset($r['ai_assist']) ? (int) $r['ai_assist'] : 1,
            'caption_mode'     => BrandService::caption_mode($r['caption_mode'] ?? 'standard'),
            'caption_text'     => (string) ($r['caption_text'] ?? ''),
            'social_accounts'  => array_map('strval', (array) $accounts),
            'cadence'          => (string) $r['cadence'],
            'days_of_week'     => $days,
            'run_time'         => substr((string) $r['run_time'], 0, 5),
            'cadence_summary'  => $this->cadence_summary($r),
            'next_run'         => $this->scheduler_next_human($r['next_run_at'] ?? '', $tz),
            'last_status'      => (string) $r['last_status'],
            'last_run'         => $this->scheduler_next_human($r['last_run_at'] ?? '', $tz),
            'last_message'     => $this->scheduler_last_message((int) $r['id'], (string) $r['last_status']),
        ];
    }

    /** Name of the influencer an automation renders (cached per request). */
    private function scheduler_influencer_name(array $r): string{
        static $names = [];
        $id = (int) ($r['influencer_id'] ?? 0);
        if ($id <= 0 || ($r['image_source'] ?? '') !== 'influencer') { return ''; }
        if (!array_key_exists($id, $names)) {
            $infl = (new InfluencersModel())->get_one((int) $r['creator_id'], $id);
            $names[$id] = $infl ? (string) $infl['name'] : '';
        }
        return $names[$id];
    }

    /** The newest run's message for a failed rule, so the card can say why. '' otherwise. */
    private function scheduler_last_message(int $rule_id, ?string $last_status): ?string{
        if ($last_status !== 'failed') { return ''; }
        $runs = (new SchedulerRunsModel())->recent_for_rule($rule_id, 1);
        return (is_array($runs) && !empty($runs)) ? (string) ($runs[0]['message'] ?? '') : '';
    }


    /** Convert a stored UTC datetime to a creator-local 'YYYY-MM-DDTHH:MM' for pickers. */
    private function from_utc(?string $utc, string $tz): string{
        if ((string) $utc === '' || $utc === null) { return ''; }
        try {
            $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            return $d->format('Y-m-d\TH:i');
        } catch (\Throwable $e) { return ''; }
    }

    /** Shape a post + its media (with signed preview URLs) for the composer/preview. */
    private function studio_post_json(array $post, array $user): array{
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $model      = new PostsModel();
        $assets     = $model->get_assets((int) $post['id']);

        $out = []; $cover = null;
        foreach ($assets as $a) {
            $signable = ['creator_id' => $creator_id, 'type' => $a['type'],
                'thumb_key' => $a['thumb_key'], 'blurred_key' => $a['blurred_key'],
                'display_key' => $a['display_key'], 'poster_key' => $a['poster_key'],
                'original_key' => $a['original_key'] ?? ''];
            $ready = ($a['status'] === 'ready' && empty($a['deleted_at']));
            $out[] = [
                'id'       => (int) $a['asset_id'],
                'type'     => $a['type'],
                'status'   => $a['status'],
                'duration' => $a['duration_sec'] !== null ? (int) $a['duration_sec'] : null,
                'is_cover' => (int) $a['is_cover'],
                'missing'  => !empty($a['deleted_at']),
                'provenance' => (string) ($a['provenance'] ?? 'uploaded'),
                'thumb_url'=> $ready ? MediaService::signed_url($signable, 'thumb', $creator_id) : '',
                'video_url'=> ($ready && $a['type'] === 'video') ? MediaService::signed_url($signable, 'original', $creator_id) : '',
            ];
            if ((int) $a['is_cover'] === 1 && $cover === null) { $cover = $signable; }
        }
        if ($cover === null && !empty($assets)) {
            $f = $assets[0];
            $cover = ['creator_id' => $creator_id, 'type' => $f['type'],
                'thumb_key' => $f['thumb_key'], 'blurred_key' => $f['blurred_key'],
                'display_key' => $f['display_key'], 'poster_key' => $f['poster_key']];
        }
        $cover_display = ''; $cover_blurred = '';
        if ($cover) {
            $cover_display = MediaService::signed_url($cover, $cover['type'] === 'video' ? 'poster' : 'display', $creator_id);
            $cover_blurred = MediaService::signed_url($cover, 'blurred', $creator_id);
        }
        return [
            'id'                => (int) $post['id'],
            'caption'           => (string) $post['caption'],
            'moderation'        => (string) ((new PostsModel())->studio_moderation_map([(int) $post['id']])[(int) $post['id']] ?? 'ok'),
            'held_reason'       => (string) ($post['held_reason'] ?? ''),
            'comments_enabled'  => (int) ($post['comments_enabled'] ?? 1),
            'on_cls'            => (int) ($post['on_cls'] ?? 1),
            'ai_disclosure'     => (($post['ai_disclosure'] ?? null) === null) ? null : (int) $post['ai_disclosure'],
            'story_accounts'    => array_values(array_filter(explode(',', (string) ($post['story_accounts'] ?? '')), 'strlen')),
            'state'             => $post['state'],
            'scheduled_local'   => $this->from_utc($post['scheduled_at'] ?? '', $tz),
            'timezone'          => $tz,
            'assets'            => $out,
            'cover_display_url' => $cover_display,
            'cover_blurred_url' => $cover_blurred,
            'shared_accounts'   => array_values(array_unique((new SocialPostsModel())->account_ids_for_post((int) $post['id']))),
        ];
    }

    /** Why a post can't publish yet (plain words), or ok. */
    private function post_validation(array $post): array{
        $model  = new PostsModel();
        $assets = $model->get_assets((int) $post['id']);
        $has_caption = trim((string) $post['caption']) !== '';
        if (empty($assets) && !$has_caption) {
            return ['ok' => false, 'reason' => 'Add a photo, video, or caption before publishing.'];
        }
        foreach ($assets as $a) {
            if (empty($a['deleted_at']) && $a['status'] !== 'ready') {
                return ['ok' => false, 'reason' => "Some media is still processing. It'll be ready in a moment."];
            }
            // Hard stop: content the moderator blocked (suspected sexual/minors) can never publish.
            if (empty($a['deleted_at']) && ($a['moderation_status'] ?? '') === 'blocked') {
                return ['ok' => false, 'reason' => 'This media was blocked by our content check and cannot be published. Remove it to continue.'];
            }
            if (empty($a['deleted_at']) && ($a['moderation_status'] ?? '') === 'flagged') {
                return ['ok' => false, 'reason' => 'An image in this post was flagged by our content check. Remove it, or wait for our team to review it.'];
            }
        }
        if ($model->count_missing_assets((int) $post['id']) > 0) {
            return ['ok' => false, 'reason' => 'Some media was removed from your library. Take it off the post to publish.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * The cross-post choices sent with publish / schedule / share: the AI disclosure switch
     * ('' = default, on for AI media) and the accounts that get the post as a Story. Saved on the post.
     */
    private function save_share_options(PostsModel $model, int $creator_id, array $post): array{
        $f = [];
        if (array_key_exists('ai_disclosure', $this->post)) { $f['ai_disclosure'] = (string) $this->post['ai_disclosure']; }
        if (array_key_exists('story_accounts', $this->post)) { $f['story_accounts'] = is_array($this->post['story_accounts']) ? $this->post['story_accounts'] : []; }
        if (empty($f)) { return $post; }
        $model->update_fields($creator_id, (int) $post['id'], $f);
        return $model->get_one($creator_id, (int) $post['id']) ?: $post;
    }

    private function share_accounts_from_request(): array{
        $a = $this->post['share_accounts'] ?? [];
        if (!is_array($a)) { $a = []; }
        return array_values(array_filter(array_map('strval', $a)));
    }

    /**
     * Cross-post the PROMOTIONAL version of a post to the selected connected
     * social accounts. Best-effort — never fails the publish/schedule if sharing
     * errors. Always sends the public caption + SAFE media (the blurred variant for
     * subscriber posts) — never the subscriber media, and no link back (outbound
     * links suppress reach; the profile URL lives in the bio).
     */
    private function share_post_to_social(array $user, array $post, array $account_ids, ?string $scheduled_iso = null): string{
        if (empty($account_ids)) { return ''; }
        $r = SocialShareService::share($user, $post, $account_ids, $scheduled_iso);
        // What did not go out, in words the creator can act on ('' when everything was sent).
        return (string) ($r['error'] ?? '') === '' ? '' : (empty($r['ok']) ? 'Not shared to your social accounts: ' : '') . (string) $r['error'];
    }

    /** Social platforms need words with the post: a post with no caption cannot be cross-posted. */
    private function require_caption_for_shares(array $post, array $shares): void{
        if (!empty($shares) && trim((string) ($post['caption'] ?? '')) === '') { $this->jsonError('Add a caption to share this post to your social accounts.'); }
    }

    /** Human display of a UTC datetime in the creator's timezone. */
    private function fmt_local(?string $utc, string $tz): string{
        if ((string) $utc === '' || $utc === null) { return ''; }
        try {
            $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            return $d->format('M j, Y · g:i A');
        } catch (\Throwable $e) { return ''; }
    }

    /** Shape a post row for the Posts list. */
    private function studio_post_row(array $p, array $user, array $mod_map = []): array{
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $cover = '';
        if (!empty($p['cover_thumb_key'])) {
            $cover = MediaService::signed_url([
                'creator_id' => $creator_id, 'type' => $p['cover_type'], 'thumb_key' => $p['cover_thumb_key'],
            ], 'thumb', $creator_id);
        }
        $cap = trim((string) $p['caption']);
        if ($p['state'] === 'published') { $when_label = 'Published'; $when = $this->fmt_local($p['published_at'] ?? '', $tz); }
        elseif ($p['state'] === 'scheduled') { $when_label = 'Publishes'; $when = $this->fmt_local($p['scheduled_at'] ?? '', $tz); }
        elseif ($p['state'] === 'archived') { $when_label = 'Archived'; $when = $this->fmt_local($p['updated_at'] ?? '', $tz); }
        else { $when_label = 'Edited'; $when = $this->fmt_local($p['updated_at'] ?? $p['created_at'], $tz); }

        return [
            'id'             => (int) $p['id'],
            'state'          => $p['state'],
            'caption'        => ($cap === '') ? '' : mb_substr($cap, 0, 140),
            'cover_url'      => $cover,
            'cover_type'     => (string) ($p['cover_type'] ?? ''),
            'asset_count'    => (int) $p['asset_count'],
            'media_missing'  => (int) $p['media_missing'],
            'held'           => (string) ($p['held_reason'] ?? '') !== '' && $p['state'] === 'draft',   // held: flagged by the content check ('moderation') or the plan
            'held_reason'    => (string) ($p['held_reason'] ?? ''),
            'when_label'     => $when_label,
            'when'           => $when,
            'views'          => (int) $p['views'],
            'likes'          => (int) $p['likes'],
            'comments'       => (int) $p['comments'],
            'moderation'     => (string) ($mod_map[(int) $p['id']] ?? 'ok'),   // 'blocked'|'flagged'|'pending'|'ok'
            'shared_count'   => (new SocialPostsModel())->count_for_post((int) $p['id']),
        ];
    }

    /** Validate an IANA timezone id (rejects the 'UTC' default sentinel). Returns '' if invalid. */
    private function valid_tz(string $tz): string{
        $tz = trim((string) $tz);
        if ($tz === '' || strtoupper($tz) === 'UTC') { return ''; }
        try { new DateTimeZone($tz); return $tz; } catch (\Throwable $e) { return ''; }
    }

}
