# ARCHITECTURAL & COMPLIANCE DECISIONS

1. **DEC-001 (Namespace & Prefix)**: Use `HTD\AiSeoWriterPro\` and `htd_ai_seo_` prefix across all DB tables, options, cron hooks, and AJAX/REST routes to prevent any collision with `sv_ai_*` or HTD SEO AI Command Center.
2. **DEC-002 (Exclude SoftViet Licensing & Updater)**: Exclude `SV_AI_License_Service`, `SV_AI_License_API`, and `SV_AI_Updater` completely. HTD plugin is self-hosted with no time/site limits and no outbound `softviet.vn` requests.
3. **DEC-003 (Replace Remote Prompt Bundle)**: Reimplement prompt generation locally in `HTD Prompt Engine` so zero keyword/site data leaves to `softviet.vn`.
4. **DEC-004 (Exclude Brand Assets)**: Exclude all 8 SoftViet PNG assets (`wordpress-org-assets/*`, `wporg-assets`).
5. **DEC-005 (Safety-First Defaults)**: Enforce `WP_READ_ONLY_MODE=true`, `ALLOW_AUTOPUBLISH=false`, `ALLOW_PRODUCT_UPDATE=false`, `ALLOW_REMOTE_UPDATE=false`, `ALLOW_EXTERNAL_TELEMETRY=false`, `REQUIRE_HUMAN_APPROVAL=true` as default configuration requirements.
6. **DEC-006 (GPLv2+ Compliance)**: Distribute `htd-ai-seo-writer-pro` under `GPL-2.0-or-later` with preserved upstream attribution in refactored GPL modules and `THIRD_PARTY_NOTICES.md`.
