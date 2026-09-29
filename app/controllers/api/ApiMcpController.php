<?php
/** MCP connector bearer tokens. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiMcpController extends BaseApiController {

    /**
     * Mint a bearer token for the creator's Claude MCP connector. Rotates
     * (revokes any prior token) so there is a single active credential, and
     * returns the RAW token once — the caller must copy it immediately.
     */
    public function mcp_token_generateAction(){
        // Owner only: the token acts as the owner with no expiry, so it must not outlive a collaborator.
        $user   = $this->require_creator('owner');
        $tokens = new ApiTokensModel();
        $tokens->revoke_for_user((int) $user['user_id']);
        $raw    = $tokens->create_for_user((int) $user['user_id'], 'Claude MCP connector');
        $this->jsonSuccess(['token' => $raw, 'message' => 'Connection token generated']);
    }

    /** Revoke the creator's MCP connector token(s). */
    public function mcp_token_revokeAction(){
        $user = $this->require_creator('manage', false);
        (new ApiTokensModel())->revoke_for_user((int) $user['user_id']);
        $this->jsonSuccess(['message' => 'Connection revoked']);
    }

}
