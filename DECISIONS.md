# ARCHITECTURAL & COMPLIANCE DECISIONS

1. **DEC-001 (Namespace & Prefix)**: Use `HTD\AISEO\` and `htd_ai_seo_` prefix across all DB tables, options, cron hooks, and AJAX/REST routes.
2. **DEC-002 (Exclude SoftViet Licensing & Updater)**: Exclude `SV_AI_License_Service`, `SV_AI_License_API`, and `SV_AI_Updater` completely.
3. **DEC-003 (Replace Remote Prompt Bundle)**: Reimplement prompt generation locally in `HTD Prompt Engine` (20 local templates).
4. **DEC-004 (Exclude Brand Assets)**: Exclude all 8 SoftViet PNG assets.
5. **DEC-005 (Safety-First Defaults)**: Enforce `WP_READ_ONLY_MODE=true`, `ALLOW_AUTOPUBLISH=false`, `ALLOW_PRODUCT_UPDATE=false`, `ALLOW_REMOTE_UPDATE=false`, `ALLOW_EXTERNAL_TELEMETRY=false`, `REQUIRE_HUMAN_APPROVAL=true`.
6. **DEC-006 (GPLv2+ Compliance)**: Distribute `htd-ai-seo-writer-pro` under `GPL-2.0-or-later` with preserved attribution in `THIRD_PARTY_NOTICES.md`.
7. **DEC-007 (FEAT_007 ChatGPT/Codex OAuth Reconciliation)**: Confirmed `FEAT_007` is an original feature in `class-sv-ai-chatgpt-oauth.php`. Uses `auth.openai.com` + `chatgpt.com/backend-api/codex/*` (consumer Codex route whose upstream ToS/stability is unverified for third-party plugins). Kept as `PENDING_USER_APPROVAL` — not silently dropped or marked optional; user to decide between OAuth2 PKCE implementation vs official `api.openai.com` API Key only.
