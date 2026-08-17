-- ============================================================================
-- 2026-08-17  Remote MCP connector
-- ----------------------------------------------------------------------------
-- Per-creator API tokens that authenticate the Claude MCP connector
-- (Settings > Integrations -> "Claude (MCP Connector)"). Only the SHA-256 hash
-- of each token is stored; the raw token is shown to the creator once.
-- Applied to the live DB on 2026-08-17.
-- ============================================================================

CREATE TABLE IF NOT EXISTS api_tokens (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  token_hash   CHAR(64) NOT NULL,            -- sha256('cls_<64hex>'); raw shown once
  label        VARCHAR(80) NULL,
  last_used_at DATETIME NULL,
  revoked      TINYINT NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL,
  UNIQUE KEY uniq_token (token_hash),
  KEY idx_user (user_id)
);

-- ----------------------------------------------------------------------------
-- Cleanup (optional): the column below was added for an earlier, reverted
-- "Claude API key" settings feature and is now unused/empty. Drop it if you
-- want the schema clean. Left in place by default since it is harmless.
-- ----------------------------------------------------------------------------
-- ALTER TABLE user_accounts DROP COLUMN claude_api_key;
