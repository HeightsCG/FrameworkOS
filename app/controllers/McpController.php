<?php
/**
 * Remote MCP connector endpoint (POST /mcp). Registered in Claude.ai via
 * Settings > Connectors > "Add custom connector" with this URL. Authenticated
 * by a per-creator bearer token (Authorization: Bearer <token>) minted in
 * Settings > Integrations; also accepts the token as the /mcp/<token> path
 * segment as a fallback when the platform can't forward the Authorization
 * header. Every request is scoped to exactly one creator.
 *
 * Like WebhookController: extends Controller with $protected = 0, so there is
 * no CSRF check and the login layout is never swapped in. Token auth replaces
 * both. Transport is Streamable HTTP / JSON-RPC 2.0 (see McpServer).
 */
class McpController extends Controller {

    public $protected = 0;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        // Only POST carries JSON-RPC. SSE/GET streaming isn't needed for tools.
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Method Not Allowed'));
            return;
        }

        // Origin is only present on browser-originated requests; reject a
        // disallowed one (DNS-rebinding guard), allow when absent (server-side).
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && !in_array($origin, array('https://claude.ai', 'https://api.claude.ai'), true)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Forbidden origin'));
            return;
        }

        // Authenticate: bearer header first, then /mcp/<token> path fallback.
        $token = $this->bearer_token();
        if ($token === '') { $token = $this->url_token(); }
        $creator_id = ($token === '') ? null : (new ApiTokensModel())->resolve($token);
        if (!$creator_id) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer');
            header('Content-Type: application/json');
            echo json_encode(array('jsonrpc' => '2.0', 'id' => null,
                'error' => array('code' => -32001, 'message' => 'Unauthorized')));
            return;
        }

        $msg = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($msg)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(array('jsonrpc' => '2.0', 'id' => null,
                'error' => array('code' => -32700, 'message' => 'Parse error')));
            return;
        }

        $res = McpServer::handle($msg, (int) $creator_id);
        http_response_code($res['status']);
        header('MCP-Protocol-Version: ' . McpServer::PROTOCOL_VERSION);
        if ($res['body'] === null) { return; }   // notification → 202, no body
        header('Content-Type: application/json');
        echo json_encode($res['body'], JSON_UNESCAPED_SLASHES);
    }

    /** Extract a bearer token from the Authorization header (several sources). */
    private function bearer_token(){
        $h = '';
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $h = (string) $v; break; }
            }
        }
        if ($h === '') {
            $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        }
        return (stripos($h, 'Bearer ') === 0) ? trim(substr($h, 7)) : '';
    }

    /** Fallback: token supplied as the /mcp/<token> path segment. */
    private function url_token(){
        $url = Main::get_url();
        return isset($url[1]) ? trim((string) $url[1]) : '';
    }

}
