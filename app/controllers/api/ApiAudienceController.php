<?php
/** Audience CRM tags and notes. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiAudienceController extends BaseApiController {

    public function audience_tag_addAction(){
        list($me, $fan) = $this->audience_guard();
        $tag = trim(html_entity_decode((string) ($this->post['tag'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($tag === '') { $this->jsonError('Empty tag'); }
        $model = new AudienceModel();
        $model->add_tag($me, $fan, $tag);
        $this->jsonSuccess(['tags' => $model->tags_for($me, $fan)]);
    }

    public function audience_tag_removeAction(){
        list($me, $fan) = $this->audience_guard();
        $tag = trim(html_entity_decode((string) ($this->post['tag'] ?? ''), ENT_QUOTES, 'UTF-8'));
        (new AudienceModel())->remove_tag($me, $fan, $tag);
        $this->jsonSuccess();
    }

    public function audience_note_saveAction(){
        list($me, $fan) = $this->audience_guard();
        $note = trim(html_entity_decode((string) ($this->post['note'] ?? ''), ENT_QUOTES, 'UTF-8'));
        (new AudienceModel())->save_note($me, $fan, $note);
        $this->jsonSuccess();
    }

    /* ---------- Notifications (PRD §27) ---------- */

    /** Guard: current user is a creator and the fan is in their audience. Returns [creator_id, fan_id] or exits with JSON error. */
    private function audience_guard(): array{
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::has_role('Creator')) { $this->jsonError('Creators only'); }
        $fan = (int) ($this->post['fan_id'] ?? 0);
        if ($fan <= 0 || !(new AudienceModel())->is_audience_member($me, $fan)) {
            $this->jsonError('Not in your audience');
        }
        return [$me, $fan];
    }

}
