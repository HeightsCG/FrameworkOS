<?php
/**
 * Clip editor (Content Studio, New Edit): edit projects and their exports. Thin wrappers over
 * ClipEditActions (shared with the Claude connector). Routed from /api/<action> by ApiRoutes.
 * Creator content role and a paid plan, like the rest of the Studio; exports cost no AI credits.
 */
class ApiClipEditorController extends BaseApiController {

    private function answer(array $r){
        if (empty($r['ok'])) { $this->jsonError((string) $r['error'], array_intersect_key($r, array_flip(['problems', 'need_upgrade']))); }
        unset($r['ok'], $r['error']);
        $this->jsonSuccess($r);
    }

    private function text($key, $max){ return mb_substr(trim(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8')), 0, $max); }

    public function edit_project_listAction(){
        $user = $this->require_creator('content', false);
        $this->answer(ClipEditActions::listing((int) $user['user_id']));
    }

    public function edit_project_createAction(){
        $user = $this->require_creator('content');
        $this->answer(ClipEditActions::create((int) $user['user_id'], $this->text('name', 160), (string) ($this->post['aspect'] ?? '9:16')));
    }

    public function edit_project_getAction(){
        $user = $this->require_creator('content', false);
        $this->answer(ClipEditActions::get((int) $user['user_id'], (int) ($this->post['id'] ?? 0)));
    }

    public function edit_project_saveAction(){
        $user = $this->require_creator('content');
        $f = array();
        if (isset($this->post['name']))   { $f['name'] = $this->text('name', 160); }
        if (isset($this->post['aspect'])) { $f['aspect'] = (string) $this->post['aspect']; }
        if (isset($this->post['timeline'])) {
            $t = json_decode(html_entity_decode((string) $this->post['timeline'], ENT_QUOTES, 'UTF-8'), true);
            if (!is_array($t)) { $this->jsonError('The edit could not be saved.'); }
            $f['timeline'] = $t;
        }
        $this->answer(ClipEditActions::save((int) $user['user_id'], (int) ($this->post['id'] ?? 0), $f));
    }

    public function edit_project_deleteAction(){
        $user = $this->require_creator('content', false);
        $this->answer(ClipEditActions::delete((int) $user['user_id'], (int) ($this->post['id'] ?? 0)));
    }

    public function edit_project_exportAction(){
        $user = $this->require_creator('content');
        $this->answer(ClipEditActions::export((int) $user['user_id'], (int) ($this->post['id'] ?? 0)));
    }

    public function edit_project_statusAction(){
        $user = $this->require_creator('content', false);
        $this->answer(ClipEditActions::status((int) $user['user_id'], (int) ($this->post['id'] ?? 0)));
    }
}
