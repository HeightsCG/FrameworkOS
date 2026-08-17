<?php
/**
 * MCP protocol handler (Streamable HTTP, JSON-RPC 2.0). Pure logic: takes a
 * decoded request message plus the authenticated creator id and returns
 * ['status' => int, 'body' => array|null] — the controller does the HTTP I/O.
 * Kept transport-free so it can be exercised directly from a CLI test.
 *
 * Spec: https://modelcontextprotocol.io/specification/2025-11-25
 */
class McpServer {

    const PROTOCOL_VERSION = '2025-11-25';
    const SUPPORTED        = array('2025-11-25', '2025-06-18');

    public static function handle(array $msg, $creator_id){
        // Notifications (no "id") expect no response body.
        if (!array_key_exists('id', $msg)) {
            return array('status' => 202, 'body' => null);
        }
        $id     = $msg['id'];
        $method = (string) ($msg['method'] ?? '');

        switch ($method) {

            case 'initialize':
                $pv = (string) ($msg['params']['protocolVersion'] ?? self::PROTOCOL_VERSION);
                if (!in_array($pv, self::SUPPORTED, true)) { $pv = self::PROTOCOL_VERSION; }
                return self::result($id, array(
                    'protocolVersion' => $pv,
                    'capabilities'    => array('tools' => array('listChanged' => false)),
                    'serverInfo'      => array('name' => 'Creator Link Studio', 'version' => '1.0.0'),
                ));

            case 'ping':
                return self::result($id, new stdClass());

            case 'tools/list':
                return self::result($id, array('tools' => McpTools::definitions()));

            case 'tools/call':
                $name = (string) ($msg['params']['name'] ?? '');
                $args = (array) ($msg['params']['arguments'] ?? array());
                try {
                    $data = McpTools::call($name, (int) $creator_id, $args);
                    return self::result($id, array(
                        'content' => array(array(
                            'type' => 'text',
                            'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        )),
                        'isError' => false,
                    ));
                } catch (\Throwable $e) {
                    // Tool-level errors are reported in the result (isError), not as
                    // a protocol error, so the model can see and react to them.
                    return self::result($id, array(
                        'content' => array(array('type' => 'text', 'text' => 'Error: ' . $e->getMessage())),
                        'isError' => true,
                    ));
                }

            default:
                return self::error($id, -32601, 'Method not found: ' . $method);
        }
    }

    private static function result($id, $result){
        return array('status' => 200, 'body' => array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result));
    }

    private static function error($id, $code, $message){
        return array('status' => 200, 'body' => array(
            'jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message)));
    }

}
