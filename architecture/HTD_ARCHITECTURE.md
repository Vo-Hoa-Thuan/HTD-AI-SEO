# HTD INDEPENDENT ARCHITECTURE (`htd-ai-seo-writer-pro`)

## 1. Core Principles
- **Namespace**: `HTD\AiSeoWriterPro\` (PSR-4 autoloading, PHP 8.1+, WordPress 6.0+, WooCommerce & Rank Math compatible, LiteSpeed/cPanel safe).
- **Zero External Vendor Lock-In**: No SoftViet license checks, no remote prompt-bundle calls, no telemetry, no remote kill-switches.
- **Safety Defaults** (Design Requirements):
  - `WP_READ_ONLY_MODE=true`
  - `ALLOW_AUTOPUBLISH=false`
  - `ALLOW_PRODUCT_UPDATE=false`
  - `ALLOW_REMOTE_UPDATE=false`
  - `ALLOW_EXTERNAL_TELEMETRY=false`
  - `REQUIRE_HUMAN_APPROVAL=true`

## 2. Finalized Module Architecture (Aligned with Feature Registry)
1. `HTD Core` — Bootstrap, container/DI, Emergency Stop switch, lifecycle.
2. `HTD Database` — Versioned migrations (`htd_ai_seo_*` tables).
3. `HTD Settings` — Typed configuration with safe defaults.
4. `HTD Credential Vault` — AES-256-GCM encryption for API keys & OAuth tokens.
5. `HTD Security Engine` — Nonces, `manage_options` / capability checks, `wp_kses_post`, rate limits.
6. `HTD Logging Engine` — Structured audit trail with full secret redaction.
7. `HTD AI Providers` — Adapters for OpenAI, Gemini, Claude, DeepSeek, Grok, + token budget & cost tracking.
8. `HTD Prompt Engine` — Local versioned prompt templates (no remote prompt dependency).
9. `HTD Keyword Engine` — Queue CRUD, clustering, audit, Keyword-to-URL mapping, cannibalization detection.
10. `HTD Content Engine` — Outline, long-form drafting, FAQ, schema/HTML validation.
11. `HTD Rewrite Engine` — Safe rewrite with URL preservation & diff/history.
12. `HTD Media Engine` — Media Library picker, AI image generation, ALT/metadata.
13. `HTD Product Engine` — WooCommerce product selection, tagging, MASTER PRODUCT HTD validation.
14. `HTD SEO Engine` & `HTD Internal Link Engine` — Rank Math / Yoast mapping, internal linking.
15. `HTD Queue Engine` & `HTD Scheduler` — Idempotent locking, retry/backoff, LiteSpeed-safe cron.
16. `HTD Approval Engine` — Human approval gate, backup & rollback snapshot before writes.
17. `HTD Admin` — Multilingual UI (VI/EN/ZH) with HTD branding.
18. `HTD Update Engine` — Controlled GitHub Private / manual ZIP updates.
19. `HTD GSC Integration`, `HTD GA4 Integration`, `HTD AI Search Observation`, `HTD QA Engine` — Phase 06 SEO extensions.
