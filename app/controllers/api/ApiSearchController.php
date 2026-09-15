<?php
/** Universal search. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiSearchController extends BaseApiController {

    /** Universal search (PRD §28) — creators + published content for the top-chrome box. */
    public function searchAction(){
        $q = trim((string) ($this->post['q'] ?? ''));
        if (mb_strlen($q) < 2) { $this->jsonSuccess(['creators' => [], 'posts' => []]); }
        $viewer     = (int) Session::get('user_id');
        $show_adult = false;
        if ($viewer > 0) {
            $rows = $this->userModel->get_user_by_id($viewer);
            $u    = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            $show_adult = !empty($u['adult_content_enabled']);
        }
        $model = new SearchModel();
        $this->jsonSuccess(['creators' => $model->creators($q), 'posts' => $model->posts($q, $show_adult)]);
    }

    /* ---------- Audience / CRM (PRD §26) ---------- */

}
