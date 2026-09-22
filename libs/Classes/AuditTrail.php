<?php
/**
 * Mixed into the staff-facing API controllers (ApiAdminController, ApiSeoContentController, ApiSupportController).
 * Every successful response from a staff user is recorded in admin_audit_log with the action, the submitted
 * fields and the result, so new admin actions are audited without extra code. Actions listed in
 * $audit_skip (user-facing ones like report_submit) are not recorded. A failure to write the log never
 * blocks the action itself.
 */
trait AuditTrail {

    protected function jsonSuccess(array $data = [], int $httpCode = 200): void {
        try {
            $action = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) (Main::get_url()[1] ?? '')));
            $skip = property_exists($this, 'audit_skip') ? (array) $this->audit_skip : array();
            if ($action !== '' && !in_array($action, $skip, true) && Permissions::is_admin() && $this->audit_applies($action)) {
                (new AuditModel())->record((int) Session::get('user_id'), $action, (array) $this->post, $data, $this->get_ip_address());
            }
        } catch (\Throwable $e) {
            error_log('[audit] ' . $e->getMessage());
        }
        parent::jsonSuccess($data, $httpCode);
    }

    /** Override to limit auditing further (e.g. only when staff act on someone else's support request). */
    protected function audit_applies(string $action): bool { return true; }
}
