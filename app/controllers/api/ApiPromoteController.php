<?php
/** Cross-promotion swaps (/promote) and the Settings opt-in. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiPromoteController extends BaseApiController {

    /** The signed-in creator's owner id, on a plan the admin allows to cross-promote. */
    private function promo_creator(): int {
        $user = $this->require_creator('manage', false);
        if (!PromoSwapsModel::eligible_user($user)) { $this->jsonError('Cross-promotion is not included on your plan.', ['need_plan' => 1]); }
        return (int) $user['user_id'];
    }

    /** Settings: switch the Cross-Promotion opt-in. Turning it off ends running swaps and closes open requests (the other side is told). */
    public function promo_opt_inAction(){
        $on = !empty($this->post['on']) && (string) $this->post['on'] !== '0';
        if ($on) { $me = $this->promo_creator(); }
        else { $me = (int) $this->require_creator('manage', false)['user_id']; }   // opting out works on any plan
        $model = new PromoSwapsModel();
        if (!$model->set_opt_in($me, $on)) { $this->jsonError('Save your profile first.'); }
        if (!$on) {
            $who = Notify::name_of($me);
            $who = $who !== '' ? $who : 'The creator';
            foreach ($model->open_for($me) as $sw) {
                $other = (int) $sw['requester_id'] === $me ? (int) $sw['partner_id'] : (int) $sw['requester_id'];
                if ((string) $sw['status'] === 'active') {
                    if ($model->end_swap((int) $sw['id'], $me)) { Notify::send($other, 'system', $who . ' ended your promotion swap', 'You no longer feature each other on your profiles.', '/promote', 'fa-handshake'); }
                } elseif ((int) $sw['partner_id'] === $me) {
                    if ($model->decline((int) $sw['id'])) { Notify::send($other, 'system', $who . ' declined your promotion swap', '', '/promote', 'fa-handshake'); }
                } elseif ($model->end_swap((int) $sw['id'], $me)) {
                    Notify::send($other, 'system', $who . ' withdrew their promotion swap request', '', '/promote', 'fa-handshake');
                }
            }
        }
        $this->jsonSuccess(['on' => $on ? 1 : 0, 'message' => $on ? 'Cross-promotion turned on' : 'Cross-promotion turned off']);
    }

    /** Opted-in creators to swap with, by niche and name / handle. */
    public function promo_browseAction(){
        $me = $this->promo_creator();
        $model = new PromoSwapsModel();
        if (!$model->opted_in($me)) { $this->jsonError('Turn on Cross-Promotion in Settings first.'); }
        $cat = (string) ($this->post['category'] ?? '');
        if ($cat !== '' && !isset(DirectoryService::CATEGORIES[$cat])) { $cat = ''; }
        $q = trim(html_entity_decode((string) ($this->post['q'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $out = array();
        foreach ($model->browse($me, $cat, mb_substr($q, 0, 80)) as $c) {
            $out[] = array('id' => $c['user_id'], 'handle' => $c['u_name'], 'name' => html_entity_decode($c['display_name'] !== '' ? $c['display_name'] : $c['u_name'], ENT_QUOTES, 'UTF-8'),
                           'avatar' => $c['avatar_url'], 'niche' => DirectoryService::CATEGORIES[$c['category']] ?? '', 'followers' => $c['followers'], 'verified' => $c['verified']);
        }
        $this->jsonSuccess(['creators' => $out]);
    }

    /** Ask another opted-in creator for a swap of 7, 14 or 30 days. */
    public function promo_requestAction(){
        $me = $this->promo_creator();
        $model = new PromoSwapsModel();
        $partner = (int) ($this->post['partner_id'] ?? 0);
        $days = (int) ($this->post['days'] ?? 0);
        $note = trim(html_entity_decode((string) ($this->post['note'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if (!in_array($days, PromoSwapsModel::DAYS, true)) { $this->jsonError('Choose 7, 14 or 30 days.'); }
        if (mb_strlen($note) > 500) { $this->jsonError('Keep the note under 500 characters.'); }
        if ($partner <= 0 || $partner === $me) { $this->jsonError('Choose a creator.'); }
        if (!$model->opted_in($me)) { $this->jsonError('Turn on Cross-Promotion in Settings first.'); }
        if (!$model->opted_in($partner) || !PromoSwapsModel::eligible($partner) || (new BlocksModel())->either_blocked($me, $partner)) {
            $this->jsonError('This creator is not taking swaps right now.');
        }
        if ($model->open_between($me, $partner)) { $this->jsonError('You already have a swap or a request open with this creator.'); }
        $id = $model->create($me, $partner, $days, $note);
        if ($id <= 0) { $this->jsonError('You already have a swap or a request open with this creator.'); }
        $who = Notify::name_of($me);
        Notify::send($partner, 'system', ($who !== '' ? $who : 'A creator') . ' wants to swap promotion with you',
            $days . ' days on each other\'s profiles' . ($note !== '' ? '. "' . mb_substr($note, 0, 200) . '"' : '.'), '/promote', 'fa-handshake');
        $this->jsonSuccess(['id' => $id, 'message' => 'Request sent']);
    }

    /** The partner accepts or declines a pending request. */
    public function promo_respondAction(){
        $me = $this->promo_creator();
        $model = new PromoSwapsModel();
        $sw = $model->get((int) ($this->post['id'] ?? 0));
        $accept = (string) ($this->post['answer'] ?? '') === 'accept';
        if (!$sw || (int) $sw['partner_id'] !== $me || (string) $sw['status'] !== 'pending') { $this->jsonError('This request is no longer open.'); }
        $who = Notify::name_of($me);
        $who = $who !== '' ? $who : 'The creator';
        if ($accept) {
            if (!$model->opted_in($me)) { $this->jsonError('Turn on Cross-Promotion in Settings first.'); }
            if (!$model->opted_in((int) $sw['requester_id']) || !PromoSwapsModel::eligible((int) $sw['requester_id']) || (new BlocksModel())->either_blocked($me, (int) $sw['requester_id'])) {
                $model->decline((int) $sw['id']);
                $this->jsonError('This creator can\'t take swaps right now, so the request was closed.');
            }
            if (!$model->accept((int) $sw['id'], (int) $sw['days'])) { $this->jsonError('This request is no longer open.'); }
            Notify::send((int) $sw['requester_id'], 'system', $who . ' accepted your promotion swap',
                'You now feature each other on your profiles for ' . (int) $sw['days'] . ' days.', '/promote', 'fa-handshake');
            $this->jsonSuccess(['message' => 'Swap started']);
        }
        if (!$model->decline((int) $sw['id'])) { $this->jsonError('This request is no longer open.'); }
        Notify::send((int) $sw['requester_id'], 'system', $who . ' declined your promotion swap', '', '/promote', 'fa-handshake');
        $this->jsonSuccess(['message' => 'Request declined']);
    }

    /** Either side ends a running swap, or the requester withdraws a pending one. The other side is told. */
    public function promo_endAction(){
        $user = $this->require_creator('manage', false);   // ending stays possible after a plan change
        $me = (int) $user['user_id'];
        $model = new PromoSwapsModel();
        $sw = $model->get((int) ($this->post['id'] ?? 0));
        if (!$sw || ((int) $sw['requester_id'] !== $me && (int) $sw['partner_id'] !== $me)) { $this->jsonError('Swap not found'); }
        $pending = (string) $sw['status'] === 'pending';
        if ($pending && (int) $sw['requester_id'] !== $me) { $this->jsonError('Decline the request instead.'); }
        if (!$model->end_swap((int) $sw['id'], $me)) { $this->jsonError('This swap has already ended.'); }
        $other = (int) $sw['requester_id'] === $me ? (int) $sw['partner_id'] : (int) $sw['requester_id'];
        $who = Notify::name_of($me);
        $who = $who !== '' ? $who : 'The creator';
        Notify::send($other, 'system', $pending ? $who . ' withdrew their promotion swap request' : $who . ' ended your promotion swap',
            $pending ? '' : 'You no longer feature each other on your profiles.', '/promote', 'fa-handshake');
        $this->jsonSuccess(['message' => $pending ? 'Request withdrawn' : 'Swap ended']);
    }
}
