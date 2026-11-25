# ARCHITECTURAL & COMPLIANCE DECISIONS

1. **DEC-001 (Namespace & Prefix)**: Use `HTD\AISEO\` and `htd_ai_seo_` prefix across all DB tables, options, cron hooks, and AJAX/REST routes.
2. **DEC-002 (Exclude SoftViet Licensing & Updater)**: Exclude `SV_AI_License_Service`, `SV_AI_License_API`, and `SV_AI_Updater` completely.
3. **DEC-003 (Replace Remote Prompt Bundle)**: Reimplement prompt generation locally in `HTD Prompt Engine` (20 local templates).
4. **DEC-004 (Exclude Brand Assets)**: Exclude all 8 SoftViet PNG assets.
5. **DEC-005 (Safety-First Defaults)**: Enforce `WP_READ_ONLY_MODE=true`, `ALLOW_AUTOPUBLISH=false`, `ALLOW_PRODUCT_UPDATE=false`, `ALLOW_REMOTE_UPDATE=false`, `ALLOW_EXTERNAL_TELEMETRY=false`, `REQUIRE_HUMAN_APPROVAL=true`.
6. **DEC-006 (GPLv2+ Compliance)**: Distribute `htd-ai-seo-writer-pro` under `GPL-2.0-or-later` with preserved attribution in `THIRD_PARTY_NOTICES.md`.
7. **DEC-007 (FEAT_007 ChatGPT/Codex OAuth — OPTION B APPROVED)**:
   - **User Decision**: `OPTION B APPROVED` — Keep `FEAT_007`, do NOT remove it, and do NOT replace it with OpenAI API Key. Maintain OpenAI API Key and all existing AI providers simultaneously.
   - **Original Behavior (`class-sv-ai-chatgpt-oauth.php`)**: Hardcoded Codex CLI `client_id='app_EMoamEEZ73f0CkXaXp7hrann'`, `originator='codex_cli_rs'`, `redirect_uri='http://localhost:1455/auth/callback'`, and called `https://chatgpt.com/backend-api/codex/*`.
   - **Official OpenAI Docs Audited**:
     - `https://developers.openai.com/siwc/token-sharing-open-source`
     - `https://developers.openai.com/siwc/token-sharing-open-source/sign-in`
     - Official Sign in with ChatGPT (SIWC) requires either a registered `oaiapp_*` OAuth Client ID from OpenAI or Dynamic Client Registration (`dynamic_agent_client` + `ext_agent_host_id`), PKCE (`S256`), `openid profile email offline_access chatgpt.tokens.use.direct`, JWKS (`https://auth.openai.com/.well-known/jwks.json`), and official `POST /v1/responses`.
   - **HTD Compliance Rule**: Never copy `app_EMoamEEZ73f0CkXaXp7hrann`, never impersonate `codex_cli_rs`, never extract browser cookies, never call undocumented `chatgpt.com/backend-api/codex/*`.
   - **Current Status**: `BLOCKED_BY_PROVIDER_SUPPORT` until HTD obtains an official `oaiapp_*` client registration or verifies official SIWC dynamic registration in live staging.
