<?php
/** Creator Studio: profile/brand, links, plans, promos, bundles, automations and the post editor. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiCreatorStudioController extends BaseApiController {

    /** Presence heartbeat — pinged by the Studio so an open-but-idle creator stays "online". */
    public function heartbeatAction(){
        $this->require_creator();
        $this->jsonSuccess();
    }

    /** Persist the creator's real timezone (auto-detected from their browser). */
    public function set_timezoneAction(){
        $user = $this->require_creator();
        $tz   = $this->valid_tz($this->post['timezone'] ?? '');
        if ($tz === '') { $this->jsonError('Invalid timezone'); }
        (new UsersModel())->set_content_timezone((int) $user['user_id'], $tz);
        $this->jsonSuccess(['timezone' => $tz]);
    }

    public function save_creator_profileAction(){
        $this->require_creator();

        $display_name = trim((string) ($this->post['display_name'] ?? ''));
        if ($display_name === '') {
            $this->jsonError('Display name is required');
        }

        (new CreatorProfileModel())->save(Permissions::creator_id(), [
            'display_name' => $display_name,
            'bio'          => trim((string) ($this->post['bio'] ?? '')),
            'location'     => trim((string) ($this->post['location'] ?? '')),
        ]);

        $this->jsonSuccess(['message' => 'Profile saved']);
    }

    /** Generate brand details from a website URL (Claude). Does not persist. */
    public function generate_brand_identityAction(){
        $this->require_creator();
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
        $this->require_creator();
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
        $this->require_creator();

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

        $url = S3Service::upload_file($key, $file['tmp_name'], $info['mime']);
        if ($url === '') {
            $this->jsonError('Could not save the image');
        }

        $column = ($kind === 'avatar') ? 'avatar_url' : 'cover_url';
        (new CreatorProfileModel())->set_image($user_id, $column, $url);

        $this->jsonSuccess(['url' => $url, 'message' => ucfirst($kind) . ' updated']);
    }

    public function remove_creator_imageAction(){
        $this->require_creator();

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

        $this->jsonSuccess(['message' => ucfirst($kind) . ' removed']);
    }

    /* ---------- Creator external links ---------- */

    public function save_creator_linkAction(){
        $this->require_creator();
        $user_id = Permissions::creator_id();

        $title = trim((string) ($this->post['title'] ?? ''));
        $url   = trim((string) ($this->post['url'] ?? ''));
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

    public function delete_creator_linkAction(){
        $this->require_creator();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Link is required');
        }
        (new CreatorLinksModel())->delete_link(Permissions::creator_id(), $id);
        $this->jsonSuccess(['message' => 'Link removed']);
    }

    public function toggle_creator_linkAction(){
        $this->require_creator();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Link is required');
        }
        (new CreatorLinksModel())->set_enabled(Permissions::creator_id(), $id, !empty($this->post['enabled']));
        $this->jsonSuccess(['message' => 'Link updated']);
    }

    public function reorder_creator_linksAction(){
        $this->require_creator();
        $ids = $this->post['ids'] ?? [];
        if (!is_array($ids)) {
            $this->jsonError('Invalid order');
        }
        (new CreatorLinksModel())->reorder(Permissions::creator_id(), $ids);
        $this->jsonSuccess(['message' => 'Order saved']);
    }

    /* ---------- Creator membership plans ---------- */

    public function save_creator_planAction(){
        $user    = $this->require_creator('manage');
        $user_id = (int) $user['user_id'];   // owner account (collaborator acts on it)

        $name             = trim((string) ($this->post['name'] ?? ''));
        $billing_interval = (string) ($this->post['billing_interval'] ?? 'month');
        if (!in_array($billing_interval, ['week', 'month', 'year'], true)) {
            $billing_interval = 'month';
        }
        $description      = trim((string) ($this->post['description'] ?? ''));
        $perks            = trim((string) ($this->post['perks'] ?? ''));
        $id               = (int) ($this->post['id'] ?? 0);

        if ($name === '') {
            $this->jsonError('A plan name is required');
        }

        // Price arrives as dollars; store integer cents. A free tier is 0; any
        // paid tier must be at least $1.00 (Stripe won't charge sub-dollar reliably).
        $is_free     = !empty($this->post['is_free']);
        $price       = (float) ($this->post['price'] ?? 0);
        $price_cents = $is_free ? 0 : (int) round($price * 100);
        if ($price_cents !== 0 && $price_cents < 100) {
            $this->jsonError('Enter a price of at least $1.00, or make it a free tier');
        }

        // Free trial — an explicit toggle plus the value & unit (day/week/month) the
        // creator actually picked, stored as-is; forced off on free tiers. The
        // day-count Stripe needs is derived only at checkout.
        $trial_enabled = !empty($this->post['trial_enabled']) && $price_cents > 0;
        $trial_unit    = (string) ($this->post['trial_unit'] ?? 'day');
        if (!in_array($trial_unit, ['day', 'week', 'month'], true)) { $trial_unit = 'day'; }
        $trial_value   = $trial_enabled ? max(1, min(365, (int) ($this->post['trial_value'] ?? 1))) : 0;

        $fields = [
            'name'             => $name,
            'price_cents'      => $price_cents,
            'billing_interval' => $billing_interval,
            'trial_enabled'    => $trial_enabled ? 1 : 0,
            'trial_value'      => $trial_value,
            'trial_unit'       => $trial_unit,
            'description'      => $description,
            'perks'            => $perks,
        ];

        $model = new CreatorPlansModel();
        if ($id > 0) {
            if (!$model->get_one($user_id, $id)) {
                $this->jsonError('Plan not found');
            }
            $model->update_plan($user_id, $id, $fields);
        } else {
            // Enforce the plan tier's membership-tier cap (0 = unlimited).
            $cap = Plan::limit($user, 'sub_tiers');
            if ($cap !== null && (int) $cap > 0 && count((array) $model->get_for_user($user_id)) >= (int) $cap) {
                $this->jsonError('Your plan includes ' . (int) $cap . ' membership tier' . ((int) $cap === 1 ? '' : 's') . '. Upgrade to add more.', ['need_upgrade' => true]);
            }
            $id = (int) $model->add($user_id, $fields);
        }

        $this->jsonSuccess(['message' => 'Plan saved', 'id' => $id]);
    }

    public function delete_creator_planAction(){
        $this->require_creator('manage');
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Plan is required');
        }
        (new CreatorPlansModel())->delete_plan(Permissions::creator_id(), $id);
        $this->jsonSuccess(['message' => 'Plan removed']);
    }

    public function toggle_creator_planAction(){
        $this->require_creator('manage');
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Plan is required');
        }
        (new CreatorPlansModel())->set_active(Permissions::creator_id(), $id, !empty($this->post['active']));
        $this->jsonSuccess(['message' => 'Plan updated']);
    }

    public function reorder_creator_plansAction(){
        $this->require_creator('manage');
        $ids = $this->post['ids'] ?? [];
        if (!is_array($ids)) {
            $this->jsonError('Invalid order');
        }
        (new CreatorPlansModel())->reorder(Permissions::creator_id(), $ids);
        $this->jsonSuccess(['message' => 'Order saved']);
    }

    /* ---------- Discount / promo codes (Pro+ monetization) ---------- */

    /** Create or edit a discount code. */
    public function save_promo_codeAction(){
        $user = $this->require_creator('manage');
        $user_id = (int) $user['user_id'];
        $id      = (int) ($this->post['id'] ?? 0);

        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($this->post['code'] ?? '')));
        if (strlen($code) < 3 || strlen($code) > 40) {
            $this->jsonError('Use a code of 3–40 letters or numbers.');
        }
        $percent = (int) ($this->post['percent_off'] ?? 0);
        if ($percent < 1 || $percent > 100) {
            $this->jsonError('Discount must be between 1% and 100%.');
        }
        $applies_to = (string) ($this->post['applies_to'] ?? 'all');
        if (!in_array($applies_to, ['all', 'subscription', 'ppv'], true)) { $applies_to = 'all'; }

        $max = trim((string) ($this->post['max_redemptions'] ?? ''));
        $max = ($max === '' || (int) $max <= 0) ? null : (int) $max;

        $expires = trim((string) ($this->post['expires_at'] ?? ''));
        $expires_at = ($expires !== '' && ($ts = strtotime($expires))) ? date('Y-m-d H:i:s', $ts) : null;

        $model = new CreatorPromoCodesModel();
        foreach ($model->get_for_user($user_id) as $row) {   // unique per creator
            if (strtoupper($row['code']) === $code && (int) $row['id'] !== $id) {
                $this->jsonError('You already have a code with that name.');
            }
        }

        $fields = ['code' => $code, 'percent_off' => $percent, 'applies_to' => $applies_to,
            'max_redemptions' => $max, 'expires_at' => $expires_at];

        if ($id > 0) {
            if (!$model->get_owned($user_id, $id)) { $this->jsonError('Code not found'); }
            $model->update_code($user_id, $id, $fields);
        } else {
            $id = (int) $model->add($user_id, $fields);
        }
        $this->jsonSuccess(['message' => 'Discount code saved', 'id' => $id, 'code' => $code]);
    }

    public function toggle_promo_codeAction(){
        $user = $this->require_creator('manage');
        $id   = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Code is required'); }
        (new CreatorPromoCodesModel())->set_active((int) $user['user_id'], $id, !empty($this->post['active']));
        $this->jsonSuccess(['message' => 'Code updated']);
    }

    public function delete_promo_codeAction(){
        $user = $this->require_creator('manage');
        $id   = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Code is required'); }
        (new CreatorPromoCodesModel())->delete_code((int) $user['user_id'], $id);
        $this->jsonSuccess(['message' => 'Code removed']);
    }

    /** Fan-side: preview a discount code against a PPV post — returns the discounted price. */
    public function promo_previewAction(){
        $post = (new PostsModel())->get_by_id((int) ($this->post['post_id'] ?? 0));
        if (!$post || ($post['audience'] ?? '') !== 'ppv') { $this->jsonError('Not a pay-per-view post.'); }
        $price = (int) $post['ppv_price_credits'];
        $promo = (new CreatorPromoCodesModel())->get_redeemable((int) $post['creator_id'], (string) ($this->post['code'] ?? ''), 'ppv');
        if (!$promo) { $this->jsonError("That code isn't valid."); }
        $new_price = (int) max(1, ceil($price * (100 - (int) $promo['percent_off']) / 100));
        $this->jsonSuccess(['percent_off' => (int) $promo['percent_off'], 'original_price' => $price, 'new_price' => $new_price]);
    }

    /* ---------- Content Studio: media vault ---------- */

    /** Create or update a content bundle. Only the creator's own published PPV posts may be grouped. */
    public function save_bundleAction(){
        $user = $this->require_creator('manage');
        $user_id = (int) $user['user_id'];
        $id      = (int) ($this->post['id'] ?? 0);

        $name = trim(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES));
        if ($name === '' || mb_strlen($name) > 120) {
            $this->jsonError('Give the bundle a name (up to 120 characters).');
        }
        $price = (int) ($this->post['price_credits'] ?? 0);
        if ($price < 1) {
            $this->jsonError('Set a bundle price of at least 1 credit.');
        }
        $description = trim(html_entity_decode((string) ($this->post['description'] ?? ''), ENT_QUOTES));
        if (mb_strlen($description) > 500) { $description = mb_substr($description, 0, 500); }

        // Only the creator's own ready Library media may be bundled.
        $valid = [];
        foreach ((array) (new MediaAssetsModel())->get_for_creator($user_id, []) as $a) {
            if (($a['status'] ?? '') === 'ready' && empty($a['deleted_at'])) { $valid[(int) $a['id']] = true; }
        }
        $asset_ids = [];
        foreach ((array) ($this->post['asset_ids'] ?? []) as $aid) {
            $aid = (int) $aid;
            if (isset($valid[$aid])) { $asset_ids[$aid] = $aid; }
        }
        $asset_ids = array_values($asset_ids);
        if (!$asset_ids) {
            $this->jsonError('Add at least one piece of content to the bundle.');
        }

        $model = new ContentBundlesModel();
        if ($id > 0) {
            if (!$model->get_owned($user_id, $id)) { $this->jsonError('Bundle not found'); }
            $model->update_bundle($user_id, $id, ['name' => $name, 'description' => $description, 'price_credits' => $price]);
        } else {
            $id = (int) $model->add($user_id, $name, $description, $price);
        }
        $model->set_items($id, $asset_ids);
        $this->jsonSuccess(['message' => 'Bundle saved', 'id' => $id]);
    }

    public function toggle_bundleAction(){
        $user = $this->require_creator('manage');
        $id   = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Bundle is required'); }
        (new ContentBundlesModel())->set_active((int) $user['user_id'], $id, !empty($this->post['active']));
        $this->jsonSuccess(['message' => 'Bundle updated']);
    }

    public function delete_bundleAction(){
        $user = $this->require_creator('manage');
        $id   = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Bundle is required'); }
        (new ContentBundlesModel())->delete_bundle((int) $user['user_id'], $id);
        $this->jsonSuccess(['message' => 'Bundle removed']);
    }

    public function scheduler_listAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $out        = [];
        foreach ((new SchedulerRulesModel())->list_for_creator($creator_id) as $r) {
            $out[] = $this->scheduler_rule_json($r, $tz);
        }
        $this->jsonSuccess(['rules' => $out, 'can_social' => Plan::can_social_post($user)]);
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
            if (empty($t['fanvue']) && $t['cls'] === '') { $this->jsonError('Pick who receives the message.'); }
            if ($msg_ai && $topic === '')   { $this->jsonError('Tell the AI what the message is about (the topic).'); }
            if (!$msg_ai && $msg_text === '') { $this->jsonError('Write the message, or let AI write it from a topic.'); }
            if (!empty($t['fanvue'])) {
                $fv = (new FanvueAccountsModel())->get_connected_for_user($creator_id);
                if (!$fv || !FanvueAccountsModel::has_chat_scope($fv)) {
                    $this->jsonError('Connect Fanvue with inbox access (Settings > Inbox Automation) to message Fanvue fans.');
                }
            }
        } elseif ($topic === '') { $this->jsonError('Describe what to post (the topic).'); }

        $image_source  = (($this->post['image_source'] ?? 'brand') === 'influencer') ? 'influencer' : 'brand';
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
            'content_level'    => 'safe',
            'kind'             => $kind,
            'message_text'     => $msg_text,
            'message_ai'       => $msg_ai,
            'message_targets'  => (array) $targets,
            'name'             => $name,
            'topic'            => $topic,
            'active'           => ((string) ($this->post['active'] ?? '1')) !== '0',
            'size'             => (string) ($this->post['size'] ?? 'square'),
            'audience'         => (string) ($this->post['audience'] ?? 'free'),
            'tier_id'          => (int) ($this->post['tier_id'] ?? 0),
            'comments_enabled' => ((string) ($this->post['comments_enabled'] ?? '1')) !== '0',
            'use_brand'        => ((string) ($this->post['use_brand'] ?? '1')) !== '0',
            'ai_assist'        => ((string) ($this->post['ai_assist'] ?? '1')) !== '0' ? 1 : 0,
            'caption_text'     => trim(html_entity_decode((string) ($this->post['caption_text'] ?? ''), ENT_QUOTES)),
            'social_accounts'  => $this->post['social_accounts'] ?? [],
            'cadence'          => (string) ($this->post['cadence'] ?? 'daily'),
            'days_of_week'     => $this->post['days_of_week'] ?? [],
            'run_time'         => (string) ($this->post['run_time'] ?? '09:00'),
            'timezone'         => $this->valid_tz($this->post['timezone'] ?? '') ?: (string) ($user['content_timezone'] ?? 'UTC'),
        ];
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
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $active     = ((string) ($this->post['active'] ?? '0')) === '1';
        (new SchedulerRulesModel())->set_active($creator_id, (int) ($this->post['id'] ?? 0), $active);
        $this->jsonSuccess(['active' => $active ? 1 : 0]);
    }

    public function scheduler_deleteAction(){
        $user       = $this->require_creator();
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
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $rule       = (new SchedulerRulesModel())->get_one($creator_id, (int) ($this->post['id'] ?? 0));
        if (!$rule) { $this->jsonError('Automation not found'); }
        $since = (int) ($this->post['since'] ?? 0);
        $latest = (new SchedulerRunsModel())->recent_for_rule((int) $rule['id'], 1);
        $run = (is_array($latest) && !empty($latest) && (int) $latest[0]['id'] > $since) ? $latest[0] : null;
        $this->jsonSuccess(['done' => $run !== null, 'ok' => $run ? ($run['status'] === 'success') : null, 'message' => $run ? (string) $run['message'] : '', 'post_id' => $run ? ($run['post_id'] !== null ? (int) $run['post_id'] : null) : null]);
    }

    /** Create or update a draft (also powers autosave). */
    public function post_saveAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $caption    = html_entity_decode((string) ($this->post['caption'] ?? ''), ENT_QUOTES, 'UTF-8');
        $audience   = $this->post_audience((string) ($this->post['audience'] ?? 'free'));
        $tier_id    = ($audience === 'subscribers') ? (int) ($this->post['tier_id'] ?? 0) : 0;
        $ppv_credits = ($audience === 'ppv') ? $this->ppv_credits_from_dollars($this->post['ppv_price'] ?? 0) : 0;
        $comments   = (((string) ($this->post['comments_enabled'] ?? '1')) === '1') ? 1 : 0;
        $asset_ids  = $this->post['asset_ids'] ?? [];
        if (!is_array($asset_ids)) { $asset_ids = []; }
        $asset_ids  = array_values(array_map('intval', $asset_ids));
        $cover_id   = (int) ($this->post['cover_id'] ?? 0);

        // Don't create an empty draft: a brand-new post with no caption and no media
        // isn't worth saving yet (avoids junk drafts from just toggling options).
        if ($id === 0 && trim($caption) === '' && empty($asset_ids)) {
            $this->jsonSuccess(['id' => 0, 'post' => [
                'id' => 0, 'caption' => '', 'audience' => $audience, 'tier_id' => $tier_id ?: null, 'comments_enabled' => $comments, 'state' => 'draft',
                'scheduled_local' => '', 'timezone' => (string) ($user['content_timezone'] ?? 'UTC'),
                'assets' => [], 'cover_display_url' => '', 'cover_blurred_url' => '',
                'validation' => ['ok' => false, 'reason' => 'Add a photo, video, or caption before publishing.'],
            ]]);
        }

        $model  = new PostsModel();
        $fields = ['caption' => $caption, 'audience' => $audience, 'tier_id' => $tier_id, 'comments_enabled' => $comments, 'ppv_price_credits' => $ppv_credits];
        // If the post was removed elsewhere while the composer had it open, don't
        // hard-fail — fall back to creating a fresh draft so nothing is lost.
        if ($id > 0 && !$model->get_one($creator_id, $id)) { $id = 0; }
        if ($id > 0) {
            $model->update_fields($creator_id, $id, $fields);
        } else {
            $id = (int) $model->create_draft($creator_id, $caption, $audience);
            $model->update_fields($creator_id, $id, $fields);
        }
        // PPV integrity: once a post has buyers you may ADD media but not remove what
        // they paid for. (Adding is fine; removals are blocked.)
        if ($audience === 'ppv') {
            $sold = (new PpvUnlocksModel())->stats_for_posts([$id]);
            if (!empty($sold[$id]['unlocks'])) {
                $current = array_map(function ($a) { return (int) $a['asset_id']; }, $model->get_assets($id));
                if (array_diff($current, $asset_ids)) {
                    $this->jsonError('This post has buyers — you can add media but not remove what they paid for.');
                }
            }
        }
        $model->set_assets($creator_id, $id, $asset_ids, $cover_id);
        $post = $model->get_one($creator_id, $id);
        $json = $this->studio_post_json($post, $user);
        $json['validation'] = $this->post_validation($post);
        $this->jsonSuccess(['id' => $id, 'post' => $json]);
    }

    /** Load a post (edit / reopen draft). */
    public function post_getAction(){
        $user       = $this->require_creator();
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
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $draft      = (new PostsModel())->get_open_draft($creator_id);
        if (!$draft) { $this->jsonSuccess(['draft' => null]); }
        // Only offer it if it actually has content worth resuming.
        $json = $this->studio_post_json($draft, $user);
        $has = trim((string) $draft['caption']) !== '' || !empty($json['assets']);
        $this->jsonSuccess(['draft' => $has ? $json : null]);
    }

    public function post_publishAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $post       = $model->get_one($creator_id, $id);
        if (!$post) { $this->jsonError('That post was not found.'); }
        $v = $this->post_validation($post);
        if (!$v['ok']) { $this->jsonError((string) ($v['reason'])); }
        $model->set_state($creator_id, $id, 'published');
        $this->share_post_to_social($user, $post, $this->share_accounts_from_request(), null);
        $this->jsonSuccess(['message' => 'Published', 'state' => 'published']);
    }

    public function post_scheduleAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $post       = $model->get_one($creator_id, $id);
        if (!$post) { $this->jsonError('That post was not found.'); }
        $v = $this->post_validation($post);
        if (!$v['ok']) { $this->jsonError((string) ($v['reason'])); }
        $utc = $this->to_utc((string) ($this->post['scheduled_at'] ?? ''), (string) ($user['content_timezone'] ?? 'UTC'));
        if (!$utc || strtotime($utc) <= time()) {
            $this->jsonError('Pick a date and time in the future.');
        }
        $model->set_state($creator_id, $id, 'scheduled', $utc);
        // $utc is already 'Y-m-d H:i:s' in UTC — build the ISO directly (strtotime would
        // misread it in the server's America/New_York default zone and send a wrong time).
        $this->share_post_to_social($user, $post, $this->share_accounts_from_request(), str_replace(' ', 'T', $utc) . 'Z');
        $this->jsonSuccess(['message' => 'Scheduled', 'state' => 'scheduled']);
    }

    public function post_save_draftAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        if (!$model->get_one($creator_id, $id)) { $this->jsonError('That post was not found.'); }
        $model->set_state($creator_id, $id, 'draft');
        $this->jsonSuccess(['message' => 'Saved as draft', 'state' => 'draft']);
    }

    /** Archive / unarchive a post. */
    public function post_archiveAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $unarchive  = ((string) ($this->post['unarchive'] ?? '0')) === '1';
        $model      = new PostsModel();
        if (!$model->get_one($creator_id, $id)) { $this->jsonError('That post was not found.'); }
        $model->set_state($creator_id, $id, $unarchive ? 'draft' : 'archived');
        $this->jsonSuccess(['message' => $unarchive ? 'Moved to drafts' : 'Archived']);
    }

    public function post_duplicateAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $new_id     = (int) (new PostsModel())->duplicate($creator_id, $id);
        if ($new_id <= 0) { $this->jsonError('That post was not found.'); }
        $this->jsonSuccess(['id' => $new_id, 'message' => 'Duplicated to a new draft']);
    }

    public function post_deleteAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        (new PostsModel())->delete_post($creator_id, $id);
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
        $this->share_post_to_social($user, $post, $accounts, null);
        $this->jsonSuccess(['message' => 'Shared to ' . $n . ' account' . ($n > 1 ? 's' : '') . '.']);
    }

    /* ---------- Content Studio: posts list ---------- */

    public function posts_listAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $model      = new PostsModel();
        $model->publish_due($creator_id);   // cron-less: flip any now-due scheduled posts
        $filters = ['state' => (string) ($this->post['state'] ?? ''), 'search' => (string) ($this->post['search'] ?? '')];
        $rows = $model->list_for_creator($creator_id, $filters);
        $posts = [];
        $ppv_ids = [];
        foreach ($rows as $p) { if ($p['audience'] === 'ppv') { $ppv_ids[] = (int) $p['id']; } }
        $ppv_stats = !empty($ppv_ids) ? (new PpvUnlocksModel())->stats_for_posts($ppv_ids) : [];
        $mod_map   = $model->moderation_map(array_map(function ($p) { return (int) $p['id']; }, $rows));
        foreach ($rows as $p) { $posts[] = $this->studio_post_row($p, $user, $ppv_stats, $mod_map); }
        $this->jsonSuccess(['posts' => $posts, 'counts' => $model->counts_by_state($creator_id)]);
    }

    /** Scheduled + published posts placed on their local date, plus queue health. */
    public function posts_calendarAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $model      = new PostsModel();
        $model->publish_due($creator_id);

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
        $user       = $this->require_creator();
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
        $this->jsonSuccess(['message' => $done . ' post' . ($done > 1 ? 's' : '') . ($action === 'delete' ? ' removed' : ' archived')]);
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
        return [
            'id'               => (int) $r['id'],
            'kind'             => (($r['kind'] ?? 'post') === 'message') ? 'message' : 'post',
            'image_source'     => (($r['image_source'] ?? 'brand') === 'influencer') ? 'influencer' : 'brand',
            'influencer_id'    => (int) ($r['influencer_id'] ?? 0),
            'influencer_name'  => $this->scheduler_influencer_name($r),
            'influencer_model_key' => (string) ($r['influencer_model_key'] ?? ''),
            'content_level'    => 'safe',
            'message_text'     => (string) ($r['message_text'] ?? ''),
            'message_ai'       => (int) ($r['message_ai'] ?? 0),
            'message_targets'  => SchedulerRulesModel::targets($r),
            'name'             => (string) $r['name'],
            'active'           => (int) $r['active'],
            'topic'            => (string) $r['topic'],
            'size'             => (string) $r['size'],
            'audience'         => (string) $r['audience'],
            'tier_id'          => $r['tier_id'] !== null ? (int) $r['tier_id'] : null,
            'comments_enabled' => (int) $r['comments_enabled'],
            'use_brand'        => (int) $r['use_brand'],
            'ai_assist'        => isset($r['ai_assist']) ? (int) $r['ai_assist'] : 1,
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

    private function post_audience($v): string{ return in_array($v, ['subscribers', 'ppv'], true) ? $v : 'free'; }

    /** Clamp a dollar PPV price ($3–$500) to credits ($1 = 10 credits). 0 if invalid. */
    private function ppv_credits_from_dollars($dollars): int{
        $d = (int) $dollars;
        if ($d < 3) { $d = 3; }
        if ($d > 500) { $d = 500; }
        return $d * 10;
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
            'audience'          => $post['audience'],
            'tier_id'           => (isset($post['tier_id']) && $post['tier_id'] !== null) ? (int) $post['tier_id'] : null,
            'moderation'        => (string) ((new PostsModel())->moderation_map([(int) $post['id']])[(int) $post['id']] ?? 'ok'),
            'ppv_price_credits' => ($post['ppv_price_credits'] ?? null) !== null ? (int) $post['ppv_price_credits'] : null,
            'ppv_price_dollars' => ($post['ppv_price_credits'] ?? null) !== null ? (int) round($post['ppv_price_credits'] / 10) : null,
            'comments_enabled'  => (int) ($post['comments_enabled'] ?? 1),
            'state'             => $post['state'],
            'scheduled_local'   => $this->from_utc($post['scheduled_at'] ?? '', $tz),
            'timezone'          => $tz,
            'assets'            => $out,
            'cover_display_url' => $cover_display,
            'cover_blurred_url' => $cover_blurred,
            // Post for Me targets live in social_posts; a Fanvue mirror is recorded on the post row
            // itself (fanvue_post_uuid), so add its pseudo account id or the composer forgets it on edit.
            'shared_accounts'   => array_values(array_unique(array_merge(
                (new SocialPostsModel())->account_ids_for_post((int) $post['id']),
                !empty($post['fanvue_post_uuid']) ? [FanvueShareService::ACCOUNT_ID] : []
            ))),
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
        }
        if ($model->count_missing_assets((int) $post['id']) > 0) {
            return ['ok' => false, 'reason' => 'Some media was removed from your library. Take it off the post to publish.'];
        }
        if (($post['audience'] ?? '') === 'ppv') {
            $live = array_filter($assets, function ($a) { return empty($a['deleted_at']); });
            if (empty($live)) { return ['ok' => false, 'reason' => 'Pay-per-view posts need at least one photo or video to sell.']; }
            if ((int) ($post['ppv_price_credits'] ?? 0) <= 0) { return ['ok' => false, 'reason' => 'Set a price for this pay-per-view post.']; }
        }
        return ['ok' => true, 'reason' => ''];
    }

    private function share_accounts_from_request(): array{
        $a = $this->post['share_accounts'] ?? [];
        if (!is_array($a)) { $a = []; }
        return array_values(array_filter(array_map('strval', $a)));
    }

    /**
     * Cross-post the PROMOTIONAL version of a post to the selected connected
     * social accounts. Best-effort — never fails the publish/schedule if sharing
     * errors. Always sends the public caption + a SAFE preview image (the blurred
     * variant for subscriber posts) + a link back — never the subscriber media.
     */
    private function share_post_to_social(array $user, array $post, array $account_ids, ?string $scheduled_iso = null): void{
        SocialShareService::share($user, $post, $account_ids, $scheduled_iso);
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
    private function studio_post_row(array $p, array $user, array $ppv_stats = [], array $mod_map = []): array{
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
            'audience'       => $p['audience'],
            'caption'        => ($cap === '') ? '' : mb_substr($cap, 0, 140),
            'cover_url'      => $cover,
            'cover_type'     => (string) ($p['cover_type'] ?? ''),
            'asset_count'    => (int) $p['asset_count'],
            'media_missing'  => (int) $p['media_missing'],
            'when_label'     => $when_label,
            'when'           => $when,
            'views'          => (int) $p['views'],
            'likes'          => (int) $p['likes'],
            'comments'       => (int) $p['comments'],
            'earnings_cents' => (int) $p['earnings_cents'],
            'ppv_price_dollars' => ($p['audience'] === 'ppv' && ($p['ppv_price_credits'] ?? null) !== null) ? (int) round($p['ppv_price_credits'] / 10) : null,
            'ppv_unlocks'    => ($p['audience'] === 'ppv') ? (int) ($ppv_stats[(int) $p['id']]['unlocks'] ?? 0) : 0,
            'moderation'     => (string) ($mod_map[(int) $p['id']] ?? 'ok'),   // 'flagged'|'pending'|'ok'
            'shared_count'   => (new SocialPostsModel())->count_for_post((int) $p['id']) + (!empty($p['fanvue_post_uuid']) ? 1 : 0),
        ];
    }

    /** Validate an IANA timezone id (rejects the 'UTC' default sentinel). Returns '' if invalid. */
    private function valid_tz(string $tz): string{
        $tz = trim((string) $tz);
        if ($tz === '' || strtoupper($tz) === 'UTC') { return ''; }
        try { new DateTimeZone($tz); return $tz; } catch (\Throwable $e) { return ''; }
    }

}
