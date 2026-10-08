<?php
/** Creator Studio: profile/brand, links, plans, promos, bundles, automations and the post editor. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiCreatorStudioController extends BaseApiController {

    /** Presence heartbeat — pinged by the Studio so an open-but-idle creator stays "online". */
    public function heartbeatAction(){
        $this->require_creator('content', false);
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
        $this->jsonSuccess(['message' => $r['message']]);
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
        if ($path !== '' && $path !== '/' && $clean === '') { $this->jsonError('The page path can only use letters, numbers, dashes and slashes, like /events/3'); }
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

    /* ---- custom domain (Studio plan): lexivaughn.com serves the creator's profile ---- */

    /** Add the creator's domain. A bare domain comes with its www twin; the one they typed is primary. */
    public function domain_addAction(){
        $owner = $this->require_creator('manage');
        if (!CustomDomains::allowed($owner)) { $this->jsonError(Plan::feature_message('custom_domain'), ['need_plan' => true]); }
        $host = CustomDomains::normalize($this->post['hostname'] ?? '');
        if (!CustomDomains::valid($host)) { $this->jsonError('Enter a domain like yourname.com'); }
        if (CustomDomains::is_reserved($host)) { $this->jsonError('That domain can\'t be used'); }

        $model = new CreatorDomainsModel();
        if (count($model->list_for_user((int) $owner['user_id'])) > 0) { $this->jsonError('Remove your current domain before adding another'); }

        $root  = CustomDomains::root($host);
        $pair  = ($host === $root || $host === 'www.' . $root);
        $hosts = $pair ? array($root, 'www.' . $root) : array($host);
        foreach ($hosts as $h) {
            if ($model->hostname_taken($h)) { $this->jsonError($h . ' is already in use'); }
        }
        $token = 'cls-' . bin2hex(random_bytes(16));
        foreach ($hosts as $h) {
            $model->add((int) $owner['user_id'], $h, $h === $root ? 'apex' : 'subdomain', $token, $h === $host);
        }
        $this->jsonSuccess(['message' => 'Domain added. Add the DNS records below, then check.']);
    }

    /** Check DNS for the creator's domains now. */
    public function domain_verifyAction(){
        $owner = $this->require_creator('manage');
        if (!CustomDomains::allowed($owner)) { $this->jsonError(Plan::feature_message('custom_domain'), ['need_plan' => true]); }
        $model = new CreatorDomainsModel();
        $rows  = $model->list_for_user((int) $owner['user_id']);
        if (!$rows) { $this->jsonError('Add a domain first'); }
        $live = false;
        $errors = array();
        foreach ($rows as $row) {
            $r = CustomDomains::verify($row);
            $model->set_status((int) $row['id'], $r['status'], $r['error']);
            if ($r['status'] === 'active') { $live = true; } elseif ($r['error']) { $errors[] = $r['error']; }
        }
        if ($live && !$errors) { $this->jsonSuccess(['message' => 'Your domain is connected']); }
        $this->jsonError($errors ? ($errors[0] . '. DNS changes can take up to an hour.') : 'Not connected yet');
    }

    /** Which of the pair visitors land on (the other one redirects to it). */
    public function domain_set_primaryAction(){
        $owner = $this->require_creator('manage');
        $model = new CreatorDomainsModel();
        $row   = $model->get_for_user((int) $owner['user_id'], (int) ($this->post['id'] ?? 0));
        if (!$row) { $this->jsonError('Domain not found'); }
        $model->set_primary((int) $owner['user_id'], (int) $row['id']);
        $this->jsonSuccess(['message' => $row['hostname'] . ' is now your main address']);
    }

    /** Disconnect the creator's domain (both halves of a pair). Their page goes back to /@handle only. */
    public function domain_removeAction(){
        $owner = $this->require_creator('manage', false);
        $model = new CreatorDomainsModel();
        foreach ($model->list_for_user((int) $owner['user_id']) as $row) {
            $model->remove((int) $owner['user_id'], (int) $row['id']);
        }
        $this->jsonSuccess(['message' => 'Domain removed']);
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

    /* ---------- Creator membership plans ---------- */

    public function save_creator_planAction(){
        $user    = $this->require_creator('manage', false);
        $user_id = (int) $user['user_id'];   // owner account (collaborator acts on it)

        // POST text arrives HTML-encoded (clean_post_data); store it plain, the pages escape it once when shown.
        $dec              = function ($k) { return trim(html_entity_decode((string) ($this->post[$k] ?? ''), ENT_QUOTES, 'UTF-8')); };
        $name             = $dec('name');
        $billing_interval = (string) ($this->post['billing_interval'] ?? 'month');
        if (!in_array($billing_interval, ['week', 'month', 'year'], true)) {
            $billing_interval = 'month';
        }
        $description      = $dec('description');
        $perks            = $dec('perks');
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
        if ($price_cents > 0 && Plan::can_sell($user)) { CreatorAgreement::require($user_id); }   // selling needs the Creator Agreement (Free accepts it at checkout)

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
            if (!Plan::can_sell($user)) { $model->set_active($user_id, $id, false); }   // Free builds it as a draft: turning it on needs a paid plan
        }

        $this->jsonSuccess(['message' => 'Plan saved', 'id' => $id]);
    }

    public function delete_creator_planAction(){
        $this->require_creator('manage', false);
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Plan is required');
        }
        (new CreatorPlansModel())->delete_plan(Permissions::creator_id(), $id);
        $this->jsonSuccess(['message' => 'Plan removed']);
    }

    public function toggle_creator_planAction(){
        $user = $this->require_creator('manage', false);
        $this->plan_to_turn_on($user, !empty($this->post['active']));
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Plan is required');
        }
        if (!empty($this->post['active'])) {   // turning on a paid tier is selling: needs the Creator Agreement
            $cp = (new CreatorPlansModel())->get_one(Permissions::creator_id(), $id);
            if ($cp && (int) $cp['price_cents'] > 0) { CreatorAgreement::require((int) $user['user_id']); }
        }
        (new CreatorPlansModel())->set_active(Permissions::creator_id(), $id, !empty($this->post['active']));
        $this->jsonSuccess(['message' => 'Plan updated']);
    }

    public function reorder_creator_plansAction(){
        $this->require_creator('manage', false);
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
        $user = $this->require_creator('manage', true, 'create promo codes');
        $user_id = (int) $user['user_id'];
        CreatorAgreement::require($user_id);   // a promo code sells: it needs the Creator Agreement
        $id      = (int) ($this->post['id'] ?? 0);

        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($this->post['code'] ?? '')));
        if (strlen($code) < 3 || strlen($code) > 40) {
            $this->jsonError('Use a code of 3–40 letters or numbers.');
        }
        // A percent off, or an amount off (Stripe's two kinds). Amounts follow the price rules ($1–$500, 10¢ steps).
        $by_amount = (string) ($this->post['discount_type'] ?? 'percent') === 'amount';
        $percent = 0; $amount_off = null;
        if ($by_amount) {
            $a = Price::from_credits($this->post['amount_off'] ?? '');
            if (!$a['ok']) { $this->jsonError('Amount off: ' . $a['message']); }
            $amount_off = $a['credits'];
        } else {
            $percent = (int) ($this->post['percent_off'] ?? 0);
            if ($percent < 1 || $percent > 100) {
                $this->jsonError('Discount must be between 1% and 100%.');
            }
        }
        $min_order = null;
        if (trim((string) ($this->post['min_order'] ?? '')) !== '') {
            $m = Price::from_credits($this->post['min_order']);
            if (!$m['ok']) { $this->jsonError('Minimum order: ' . $m['message']); }
            $min_order = $m['credits'];
            if ($amount_off !== null && $amount_off >= $min_order) { $this->jsonError('The minimum order must be more than the amount off.'); }
        }
        $first_only = !empty($this->post['first_purchase_only']) && $this->post['first_purchase_only'] !== '0';
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

        $fields = ['code' => $code, 'percent_off' => $percent, 'amount_off_credits' => $amount_off, 'min_order_credits' => $min_order,
            'first_purchase_only' => $first_only, 'applies_to' => $applies_to, 'max_redemptions' => $max, 'expires_at' => $expires_at];

        if ($id > 0) {
            if (!$model->get_owned($user_id, $id)) { $this->jsonError('Code not found'); }
            $model->update_code($user_id, $id, $fields);
        } else {
            $id = (int) $model->add($user_id, $fields);
        }
        $this->jsonSuccess(['message' => 'Discount code saved', 'id' => $id, 'code' => $code]);
    }

    public function toggle_promo_codeAction(){
        $user = $this->require_creator('manage', false);
        $this->plan_to_turn_on($user, !empty($this->post['active']));
        if (!empty($this->post['active'])) { CreatorAgreement::require((int) $user['user_id']); }   // turning a code on is selling
        $id   = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Code is required'); }
        (new CreatorPromoCodesModel())->set_active((int) $user['user_id'], $id, !empty($this->post['active']));
        $this->jsonSuccess(['message' => 'Code updated']);
    }

    public function delete_promo_codeAction(){
        $user = $this->require_creator('manage', false);
        $id   = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Code is required'); }
        (new CreatorPromoCodesModel())->delete_code((int) $user['user_id'], $id);
        $this->jsonSuccess(['message' => 'Code removed']);
    }

    /** Fan-side: preview a discount code against a PPV post — returns the discounted price. */
    public function promo_previewAction(){
        if ((int) Session::get('user_id') <= 0) { $this->jsonError('Sign in to use a discount code.', ['need_login' => true]); }
        $this->promo_guard();
        $post = (new PostsModel())->get_by_id((int) ($this->post['post_id'] ?? 0));
        if (!$post || ($post['audience'] ?? '') !== 'ppv') { $this->jsonError('Not a pay-per-view post.'); }
        $price = (int) $post['ppv_price_credits'];
        $promo = (new CreatorPromoCodesModel())->get_redeemable((int) $post['creator_id'], (string) ($this->post['code'] ?? ''), 'ppv');
        if (!$promo) { $this->promo_miss(); $this->jsonError("That code isn't valid."); }
        $why = CreatorPromoCodesModel::rule_error($promo, (int) $post['creator_id'], (int) Session::get('user_id'), $price);
        if ($why !== '') { $this->jsonError($why); }
        $new_price = CreatorPromoCodesModel::price_after($promo, $price);
        $this->jsonSuccess(['percent_off' => (int) $promo['percent_off'], 'label' => CreatorPromoCodesModel::label($promo), 'original_price' => $price, 'new_price' => $new_price]);
    }

    /* ---------- Content Studio: media vault ---------- */

    /** Create or update a content bundle. Only the creator's own published PPV posts may be grouped. */
    public function save_bundleAction(){
        $user = $this->require_creator('manage', false);
        $user_id = (int) $user['user_id'];
        $id      = (int) ($this->post['id'] ?? 0);

        $name = trim(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES));
        if ($name === '' || mb_strlen($name) > 120) {
            $this->jsonError('Give the bundle a name (up to 120 characters).');
        }
        // The editor sends dollars ('price'); API callers may still send credits ('price_credits'). Same limits either way.
        if (isset($this->post['price'])) {
            $price = $this->price_credits($this->post['price']);
        } else {
            $chk = Price::check_credits((int) ($this->post['price_credits'] ?? 0));
            if (!$chk['ok']) { $this->jsonError($chk['message']); }
            $price = (int) $chk['credits'];
        }
        if ($price > 0 && Plan::can_sell($user)) { CreatorAgreement::require($user_id); }   // selling needs the Creator Agreement (Free accepts it at checkout)
        $description = trim(html_entity_decode((string) ($this->post['description'] ?? ''), ENT_QUOTES));
        if (mb_strlen($description) > 500) { $description = mb_substr($description, 0, 500); }

        // Only the creator's own ready Library media may be bundled.
        $valid = [];
        foreach ((array) (new MediaAssetsModel())->get_for_creator($user_id, []) as $a) {
            if (($a['status'] ?? '') === 'ready' && empty($a['deleted_at']) && ($a['moderation_status'] ?? '') !== 'blocked') { $valid[(int) $a['id']] = true; }
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
            // Buyers get what's in the bundle: once it has sold, content can be added but not taken out.
            if ($model->has_sales($id) && array_diff($model->get_item_asset_ids($id), $asset_ids)) {
                $this->jsonError('This bundle has sold, so its content can’t be removed. You can still add more.');
            }
            $model->update_bundle($user_id, $id, ['name' => $name, 'description' => $description, 'price_credits' => $price]);
        } else {
            $id = (int) $model->add($user_id, $name, $description, $price);
            if (!Plan::can_sell($user)) { $model->set_active($user_id, $id, 0); }   // Free builds it as a draft: turning it on needs a paid plan
        }
        $model->set_items($id, $asset_ids);
        $this->jsonSuccess(['message' => 'Bundle saved', 'id' => $id]);
    }

    public function toggle_bundleAction(){
        $user = $this->require_creator('manage', false);
        $this->plan_to_turn_on($user, !empty($this->post['active']));
        $id   = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Bundle is required'); }
        (new ContentBundlesModel())->set_active((int) $user['user_id'], $id, !empty($this->post['active']));
        $this->jsonSuccess(['message' => 'Bundle updated']);
    }

    public function delete_bundleAction(){
        $user = $this->require_creator('manage', false);
        $id   = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) { $this->jsonError('Bundle is required'); }
        $model = new ContentBundlesModel();
        if ($model->get_owned((int) $user['user_id'], $id) && $model->has_sales($id)) {   // buyers keep it: take it off sale instead
            $model->set_active((int) $user['user_id'], $id, 0);
            $this->jsonSuccess(['message' => 'This bundle has sold, so it was taken off sale instead of deleted. Buyers keep it.', 'archived' => true]);
        }
        $model->delete_bundle((int) $user['user_id'], $id);
        $this->jsonSuccess(['message' => 'Bundle removed']);
    }

    public function scheduler_listAction(){
        $user       = $this->require_creator('content', false);
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
            'audience'         => (string) ($this->post['audience'] ?? 'free'),
            'tier_id'          => (int) ($this->post['tier_id'] ?? 0),
            'comments_enabled' => ((string) ($this->post['comments_enabled'] ?? '1')) !== '0',
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
        $user       = $this->require_creator();
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
        $aud  = $this->post_audience((string) ($this->post['audience'] ?? 'free'));

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
        $style = ($aud === 'subscribers' || $aud === 'ppv') ? 'tease' : '';
        // Her persona (when the media is an influencer's) and the kind of caption asked for.
        $infl    = $infl_id > 0 ? (new InfluencersModel())->get_one($creator_id, $infl_id) : null;
        $caption = BrandService::caption_for($topic, (array) $cb, $style, array(), array(
            'mode' => (string) ($this->post['caption_mode'] ?? 'standard'), 'persona' => InfluencerService::persona_block($infl)));
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
        $audience   = $this->post_audience((string) ($this->post['audience'] ?? 'free'));
        $tier_id    = 0;   // legacy single tier; multi-tier targeting is tier_ids → post_tiers
        $tier_ids   = ($audience === 'subscribers') ? array_values(array_filter(array_map('intval', (array) ($this->post['tier_ids'] ?? [])))) : [];
        $ppv_credits = ($audience === 'ppv' && $this->price_given($this->post['ppv_price'] ?? '')) ? $this->price_credits($this->post['ppv_price']) : 0;
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
                'id' => 0, 'caption' => '', 'audience' => $audience, 'tier_id' => null, 'tier_ids' => $tier_ids, 'comments_enabled' => $comments, 'on_cls' => $on_cls, 'state' => 'draft',
                'scheduled_local' => '', 'timezone' => (string) ($user['content_timezone'] ?? 'UTC'),
                'assets' => [], 'cover_display_url' => '', 'cover_blurred_url' => '',
                'validation' => ['ok' => false, 'reason' => 'Add a photo, video, or caption before publishing.'],
            ]]);
        }

        $model  = new PostsModel();
        $fields = ['caption' => $caption, 'audience' => $audience, 'tier_id' => $tier_id, 'tier_ids' => $tier_ids, 'comments_enabled' => $comments, 'on_cls' => $on_cls, 'ppv_price_credits' => $ppv_credits];
        // If the post was removed elsewhere while the composer had it open, don't
        // hard-fail — fall back to creating a fresh draft so nothing is lost.
        if ($id > 0 && !$model->get_one($creator_id, $id)) { $id = 0; }
        // Free can't turn a live (or queued) post into a paid one: that is publishing paid content.
        $stored = $id > 0 ? $model->get_one($creator_id, $id) : null;
        if ($stored && in_array((string) $stored['state'], array('published', 'scheduled'), true) && !Plan::can_sell($user)
            && $this->post_is_paid(array('creator_id' => $creator_id, 'audience' => $audience, 'ppv_price_credits' => $ppv_credits, 'tier_id' => $tier_id))) {
            $this->need_plan('publish paid posts');
        }
        // PPV integrity: once a post has buyers you may ADD media but not remove what
        // they paid for, and it stays pay-per-view. Checked before anything is written.
        if ($id > 0) {
            $sold = (new PpvUnlocksModel())->stats_for_posts([$id]);
            if (!empty($sold[$id]['unlocks'])) {
                if ($audience !== 'ppv') {
                    $this->jsonError('This post has buyers. Content and price can be added to, not taken away.');
                }
                $current = array_map(function ($a) { return (int) $a['asset_id']; }, $model->get_assets($id));
                if (array_diff($current, $asset_ids)) {
                    $this->jsonError('This post has buyers — you can add media but not remove what they paid for.');
                }
            }
        }
        if ($id > 0) {
            $model->update_fields($creator_id, $id, $fields);
        } else {
            $id = (int) $model->create_draft($creator_id, $caption, $audience);
            $model->update_fields($creator_id, $id, $fields);
        }
        $model->set_assets($creator_id, $id, $asset_ids, $cover_id);
        $post = $model->get_one($creator_id, $id);
        $json = $this->studio_post_json($post, $user);
        $json['validation'] = $this->post_validation($post);
        $this->jsonSuccess(['id' => $id, 'post' => $json]);
    }

    /** Load a post (edit / reopen draft). */
    public function post_getAction(){
        $user       = $this->require_creator('content', false);
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

    public function post_publishAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $post       = $model->get_one($creator_id, $id);
        if (!$post) { $this->jsonError('That post was not found.'); }
        $v = $this->post_validation($post);
        if (!$v['ok']) { $this->jsonError((string) ($v['reason'])); }
        if ($this->post_is_paid($post) && !Plan::can_sell($user)) { $this->need_plan('publish paid posts'); }   // Free publishes free posts only
        if ($this->post_is_paid($post)) { CreatorAgreement::require($creator_id); }   // selling needs the Creator Agreement
        $shares = $this->share_accounts_from_request();
        if (empty($post['on_cls']) && empty($shares)) { $this->jsonError('Pick at least one place to publish: Creator Link Studio or a social account.'); }
        $this->require_caption_for_shares($post, $shares);
        $post = $this->save_share_options($model, $creator_id, $post);
        if (!$model->publish_once($creator_id, $id)) {   // already published (double-click / retry): don't fan out again
            $this->jsonSuccess(['message' => 'Published', 'state' => 'published']);
        }
        if (!empty($post['on_cls'])) { PostNotifier::published($creator_id, $id); }   // socials-only posts don't notify followers
        $share_error = $this->share_post_to_social($user, $post, $shares, null);
        $this->jsonSuccess(['message' => 'Published', 'state' => 'published', 'share_error' => $share_error]);
    }

    public function post_scheduleAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $post       = $model->get_one($creator_id, $id);
        if (!$post) { $this->jsonError('That post was not found.'); }
        $v = $this->post_validation($post);
        if (!$v['ok']) { $this->jsonError((string) ($v['reason'])); }
        if ($this->post_is_paid($post) && !Plan::can_sell($user)) { $this->need_plan('schedule paid posts'); }   // Free schedules free posts only
        if ($this->post_is_paid($post)) { CreatorAgreement::require($creator_id); }   // selling needs the Creator Agreement
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
        $sold       = (new PpvUnlocksModel())->stats_for_posts([$id]);
        if (!empty($sold[$id]['unlocks']) && $model->get_one($creator_id, $id)) {   // buyers keep it: archive instead
            $model->set_state($creator_id, $id, 'archived');
            $this->jsonSuccess(['message' => 'This post has sold, so it was archived instead of deleted. Buyers keep it.', 'archived' => true]);
        }
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

    /* ---------- Content Studio: posts list ---------- */

    public function posts_listAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $model      = new PostsModel();
        foreach ($model->publish_due($creator_id) as $pid => $cid) { if (!Plan::hold_unsellable_post($cid, $pid)) { PostNotifier::published($cid, $pid); } }   // cron fallback: flip now-due scheduled posts
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
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $model      = new PostsModel();
        foreach ($model->publish_due($creator_id) as $pid => $cid) { if (!Plan::hold_unsellable_post($cid, $pid)) { PostNotifier::published($cid, $pid); } }

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
        $sold  = ($action === 'delete') ? (new PpvUnlocksModel())->stats_for_posts($ids) : [];   // buyers keep sold posts: archive those instead
        $done = 0; $kept = 0;
        foreach ($ids as $id) {
            if (!$model->get_one($creator_id, $id)) { continue; }
            if ($action === 'archive') { $model->set_state($creator_id, $id, 'archived'); $done++; }
            elseif ($action === 'delete' && !empty($sold[$id]['unlocks'])) { $model->set_state($creator_id, $id, 'archived'); $kept++; }
            elseif ($action === 'delete') { $model->delete_post($creator_id, $id); $done++; }
        }
        if ($done === 0 && $kept === 0) { $this->jsonError('Nothing to update.'); }
        $msg = $done . ' post' . ($done > 1 ? 's' : '') . ($action === 'delete' ? ' removed' : ' archived');
        if ($kept > 0) { $msg = ($done > 0 ? $msg . '. ' : '') . $kept . ' sold post' . ($kept > 1 ? 's were' : ' was') . ' archived instead; buyers keep ' . ($kept > 1 ? 'them' : 'it') . '.'; }
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
            'audience'         => (string) $r['audience'],
            'tier_id'          => $r['tier_id'] !== null ? (int) $r['tier_id'] : null,
            'comments_enabled' => (int) $r['comments_enabled'],
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

    private function post_audience($v): string{ return in_array($v, ['subscribers', 'ppv'], true) ? $v : 'free'; }

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
            'audience'          => $post['audience'],
            'tier_id'           => (isset($post['tier_id']) && $post['tier_id'] !== null) ? (int) $post['tier_id'] : null,
            'tier_ids'          => $model->tiers_for_posts([(int) $post['id']])[(int) $post['id']] ?? [],
            'moderation'        => (string) ((new PostsModel())->studio_moderation_map([(int) $post['id']])[(int) $post['id']] ?? 'ok'),
            'ppv_price_credits' => ($post['ppv_price_credits'] ?? null) !== null ? (int) $post['ppv_price_credits'] : null,
            'ppv_price_dollars' => ($post['ppv_price_credits'] ?? null) !== null ? Price::input((int) $post['ppv_price_credits']) : null,
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
            // Post for Me targets live in social_posts; a Fanvue mirror is recorded on the post row
            // itself (fanvue_post_uuid), so add its pseudo account id or the composer forgets it on edit.
            'shared_accounts'   => array_values(array_unique(array_merge(
                (new SocialPostsModel())->account_ids_for_post((int) $post['id']),
                !empty($post['fanvue_post_uuid']) ? [FanvueShareService::ACCOUNT_ID] : []
            ))),
        ];
    }

    /** Why a post can't publish yet (plain words), or ok. */
    /** A pay-per-view post with a price, or one gated to a paid membership tier (any tier: the creator sells one). */
    private function post_is_paid(array $post): bool{ return CreatorAgreement::post_is_paid($post); }

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
            'held'           => (string) ($p['held_reason'] ?? '') !== '' && $p['state'] === 'draft',   // a paid post held for the plan (Plan::hold_unsellable_post)
            'when_label'     => $when_label,
            'when'           => $when,
            'views'          => (int) $p['views'],
            'likes'          => (int) $p['likes'],
            'comments'       => (int) $p['comments'],
            'earnings_cents' => (int) $p['earnings_cents'],
            'ppv_price_credits' => ($p['audience'] === 'ppv' && ($p['ppv_price_credits'] ?? null) !== null) ? (int) $p['ppv_price_credits'] : null,
            'ppv_unlocks'    => ($p['audience'] === 'ppv') ? (int) ($ppv_stats[(int) $p['id']]['unlocks'] ?? 0) : 0,
            'moderation'     => (string) ($mod_map[(int) $p['id']] ?? 'ok'),   // 'blocked'|'pending'|'adult'|'ok'
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
