# SECURITY REMEDIATION PLAN

1. **SEC_001 & SEC_002 (HIGH)**: Completely remove `SV_AI_Updater` and all `softviet.vn` license/prompt HTTP calls. Enforce `ALLOW_REMOTE_UPDATE=false` and `ALLOW_EXTERNAL_TELEMETRY=false` by default in HTD Core.
2. **SEC_003 (MEDIUM)**: Do not port `SV_AI_License_API` or `SV_AI_License_Service`. HTD plugin operates self-hosted without a license server.
3. **SEC_004 (MEDIUM)**: Sanitize all AI-generated HTML with `wp_kses_post()` + strict heading/tag whitelist, and enforce `REQUIRE_HUMAN_APPROVAL=true`, `ALLOW_AUTOPUBLISH=false`, `WP_READ_ONLY_MODE=true` defaults.
4. **SEC_005 (LOW)**: Require `manage_options` or a signed HMAC/transient token for loopback diagnostics.
5. **SEC_006 (LOW)**: Replace legacy `require_once $file` fallback with PSR-4 / Composer-free SPL autoloader.
6. **SEC_007 (LOW)**: Upgrade log redaction in `HTD Logging Engine` to mask all API key formats (`sk-*`, `AIza*`, `sk-ant-*`, `Bearer *`) before DB insert.
