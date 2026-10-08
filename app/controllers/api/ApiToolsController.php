<?php
/**
 * The free tools (/tools/fan-questions, /tools/ai-influencer-persona) and the creator's Ideas page (/ideas).
 * tool_run answers at once and queues ToolRunJob: a public run records a lead and the result is emailed; an Ideas run
 * (source 'ideas', paid creators) is stored on tool_runs and the page polls tool_run_status.
 */
class ApiToolsController extends BaseApiController {

    const PER_EMAIL_DAY = 3;    // across both tools
    const PER_IP_DAY    = 10;   // per tool
    const IDEAS_PER_DAY = 50;   // per creator

    /** POST text arrives html-encoded by clean_post_data(); keep it decoded (views and emails escape on output). */
    private function text($key, $max){
        $v = trim(preg_replace('/\s+/', ' ', html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return mb_substr($v, 0, $max);
    }

    /**
     * Public run: niche, first name, email and the consent box (persona adds vibe and audience). A filled honeypot
     * ("company") gets the same reply and nothing happens. 3 runs per email a day across both tools, 10 per IP a day per
     * tool. The attempt is recorded before the counts are read, so parallel requests can't slip past the limit.
     */
    public function tool_runAction(){
        $source = (string) ($this->post['source'] ?? '');
        if ($source === 'ideas') { $this->ideas_run(); }
        if (!isset(LeadsModel::SOURCES[$source])) { $this->jsonError('Pick a tool.'); }
        $ok = 'Check your inbox in a few minutes.';
        if ($this->text('company', 190) !== '') { $this->jsonSuccess(['message' => $ok]); }
        if ($source === 'fan-questions' && !ToolRunJob::fan_questions_ready()) { $this->jsonError('This tool is briefly unavailable.'); }

        $niche = $this->text('niche', 190);
        $name  = $this->text('first_name', 100);
        $email = mb_strtolower($this->text('email', 190));
        if (mb_strlen($niche) < 3) { $this->jsonError('Add your niche'); }
        if ($name === '') { $this->jsonError('Add your first name'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->jsonError('Add a valid email address'); }
        if ((string) ($this->post['consent'] ?? '') !== '1') { $this->jsonError('Tick the box to agree to receive emails'); }

        $kind = 'tool:' . $source;
        $ip = $this->get_ip_address();
        $this->loginAttemptsModel->record($ip, $email, 'tool');   // per address, both tools
        $this->loginAttemptsModel->record($ip, $email, $kind);    // per IP, this tool
        if ($this->loginAttemptsModel->count_recent_for($email, 'tool', 1440) > self::PER_EMAIL_DAY) { $this->jsonError('You have used these tools three times today. Try again tomorrow.'); }
        if ($this->loginAttemptsModel->count_recent($ip, $kind, 1440) > self::PER_IP_DAY) { $this->jsonError('This tool has been used a lot from your network today. Try again tomorrow.'); }

        $extra = $source === 'persona-tool' ? array('vibe' => $this->text('vibe', 190), 'audience' => $this->text('audience', 190)) : array();
        $lead_id = (new LeadsModel())->add($source, $name, $email, $niche, $extra, $ip);
        if ($lead_id <= 0) { $this->jsonError('Something went wrong. Please try again.'); }
        ToolRunJob::queue(array('lead_id' => $lead_id));
        $this->jsonSuccess(['message' => $ok]);
    }

    /** Ideas (/ideas): a paid creator's run, one at a time, unlimited. */
    private function ideas_run(){
        $user  = $this->require_creator('content', 'ai', 'use Ideas');
        $niche = $this->text('niche', 190);
        if (mb_strlen($niche) < 3) { $this->jsonError('Add your niche'); }
        if (!ToolRunJob::fan_questions_ready()) { $this->jsonError('Ideas is briefly unavailable. Try again later.'); }
        $model = new LeadsModel();
        if ($model->run_in_progress((int) $user['user_id'])) { $this->jsonError('A run is already in progress. It takes a minute or two.'); }
        if ($model->runs_today((int) $user['user_id']) >= self::IDEAS_PER_DAY) { $this->jsonError('You have reached today\'s limit for Ideas. Try again tomorrow.'); }
        $run_id = $model->run_add((int) $user['user_id'], 'fan-questions', $niche);
        if ($run_id <= 0) { $this->jsonError('Something went wrong. Please try again.'); }
        ToolRunJob::queue(array('run_id' => $run_id));
        $this->jsonSuccess(['run_id' => $run_id, 'message' => 'Finding questions']);
    }

    /** One of the creator's Ideas runs: status, and when done the themes, a flat idea list (Draft Post index) and subreddits. */
    public function tool_run_statusAction(){
        $user = $this->require_creator('content', false);
        $run = (new LeadsModel())->run_for((int) $user['user_id'], (int) ($this->post['run_id'] ?? 0));
        if (!$run) { $this->jsonError('Run not found'); }
        $r = (array) json_decode((string) ($run['result'] ?? ''), true);
        $ideas = array();
        foreach ((array) ($r['themes'] ?? array()) as $t) { foreach ((array) ($t['ideas'] ?? array()) as $i) { $ideas[] = (string) $i; } }
        $this->jsonSuccess([
            'run_id' => (int) $run['id'], 'status' => LeadsModel::shown_status($run), 'niche' => (string) $run['niche'],
            'themes' => (array) ($r['themes'] ?? array()), 'ideas' => $ideas, 'subreddits' => (array) ($r['subreddits'] ?? array()),
            'questions_read' => (int) ($r['questions_read'] ?? 0), 'error' => (string) ($r['error'] ?? (LeadsModel::shown_status($run) === 'failed' ? 'This run did not finish. Try again.' : '')),
        ]);
    }
}
