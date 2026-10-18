<?php
/** Universal search. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiSearchController extends BaseApiController {

    /** Universal search (PRD §28) — creators + published content for the top-chrome box. */
    public function searchAction(){
        $q = trim(html_entity_decode((string) ($this->post['q'] ?? ''), ENT_QUOTES, 'UTF-8'));   // POST input arrives html-encoded; stored text is plain
        if (mb_strlen($q) < 2) { $this->jsonSuccess(['creators' => [], 'posts' => []]); }
        $viewer     = (int) Session::get('user_id');
        $show_adult = false;
        if ($viewer > 0) {
            $rows = $this->userModel->get_user_by_id($viewer);
            $u    = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            $show_adult = AgeVerification::adult_allowed($u);   // toggle on AND age verified
        }
        $model = new SearchModel();
        $this->jsonSuccess(['creators' => $model->creators($q, 6, $viewer), 'posts' => $model->posts($q, $show_adult, 6, $viewer)]);
    }

    /* ---------- Audience / CRM (PRD §26) ---------- */

}
