<?php
/** Admin endpoints for the scene template library (/admin, Scenes tab). Routed by ApiRoutes; every action is audit-logged. */
class ApiAdminScenesController extends BaseApiController {

    use AuditTrail;
    private function guard(){ if (!Permissions::is_admin()) { $this->jsonError('Admins only'); } }

    private function text($key, $max){
        return mb_substr(trim(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8')), 0, $max);
    }

    private function row($id){
        $m = new SceneTemplatesModel();
        foreach ($m->list_all() as $r) { if ((int) $r['id'] === (int) $id) { return SceneTemplates::json($r, true); } }
        return null;
    }

    /** Create (no id) or update a template. */
    public function admin_scene_saveAction(){
        $this->guard();
        $f = array('title' => $this->text('title', 120), 'category' => $this->text('category', 60), 'base_prompt' => $this->text('base_prompt', 4000),
            'is_adult' => (string) ($this->post['is_adult'] ?? '0') === '1', 'default_aspect' => (string) ($this->post['default_aspect'] ?? ''),
            'is_active' => (string) ($this->post['is_active'] ?? '1') === '1', 'sort_order' => (int) ($this->post['sort_order'] ?? 0));
        $errors = array();
        if ($f['title'] === '') { $errors[] = array('input' => 'title', 'msg' => 'Give the scene a title.'); }
        if ($f['base_prompt'] === '') { $errors[] = array('input' => 'base_prompt', 'msg' => 'Write the base prompt.'); }
        if (!Aspect::valid($f['default_aspect'])) { $errors[] = array('input' => 'default_aspect', 'msg' => 'Pick a default shape.'); }
        if (!empty($errors)) { $this->jsonError($errors[0]['msg'], ['errors' => $errors]); }
        $m  = new SceneTemplatesModel();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id > 0) {
            if (!$m->get_platform($id)) { $this->jsonError('Scene not found'); }
            $m->update_one($id, $f);
        } else {
            $id = $m->add($f, (int) Session::get('user_id'));
            if ($id <= 0) { $this->jsonError('Could not save the scene'); }
        }
        $this->jsonSuccess(['id' => $id, 'scene' => $this->row($id)]);
    }

    public function admin_scene_set_activeAction(){
        $this->guard();
        $m = new SceneTemplatesModel(); $id = (int) ($this->post['id'] ?? 0);
        if (!$m->get_platform($id)) { $this->jsonError('Scene not found'); }
        $m->set_active($id, (string) ($this->post['active'] ?? '1') === '1');
        $this->jsonSuccess(['id' => $id, 'scene' => $this->row($id)]);
    }

    public function admin_scene_deleteAction(){
        $this->guard();
        $m = new SceneTemplatesModel(); $id = (int) ($this->post['id'] ?? 0);
        $t = $m->get_platform($id);
        if (!$t) { $this->jsonError('Scene not found'); }
        $m->soft_delete($id);
        $this->jsonSuccess(['id' => $id, 'title' => (string) $t['title']]);
    }

    /** Upload the thumbnail (JPG/PNG/WebP, stored as a 600px JPEG by SceneTemplates::store_thumb, shared with the creator path). */
    public function admin_scene_thumbAction(){
        $this->guard();
        $m = new SceneTemplatesModel(); $id = (int) ($this->post['id'] ?? 0);
        $t = $m->get_platform($id);
        if (!$t) { $this->jsonError('Scene not found'); }
        $r = SceneTemplates::store_thumb($t, $_FILES['file'] ?? null, null);
        if (empty($r['ok'])) { $this->jsonError((string) $r['error']); }
        $this->jsonSuccess(['id' => $id, 'scene' => $this->row($id)]);
    }
}
