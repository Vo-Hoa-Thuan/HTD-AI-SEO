# HTD INTEGRATION PLAN (WITH HTD SEO AI COMMAND CENTER)

- **Zero Conflict Namespace**: Uses `htd_ai_seo_*` tables/options/cron hooks, completely isolated from any existing `sv_ai_*` or Command Center tables.
- **Shared Source of Truth (Read-Only Sync)**: Can import/sync Keyword-to-URL Mapping and MASTER PRODUCT HTD data via explicit JSON/REST import without mutating Command Center state.
- **Schedule Deconfliction**: `HTD Scheduler` respects configurable active hours and rate limits so it does not collide with Command Center publishing windows.
- **No Unapproved LIVE Writes**: Enforces `WP_READ_ONLY_MODE=true` and `REQUIRE_HUMAN_APPROVAL=true` until explicitly approved by HTD operator.
