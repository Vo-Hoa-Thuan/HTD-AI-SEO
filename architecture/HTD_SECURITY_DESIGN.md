# HTD SECURITY DESIGN

- **Capability-Based Access**: Every admin page, AJAX action, and REST route requires `manage_options` (or dedicated `htd_ai_seo_manage` capability) + WordPress Nonce verification.
- **Credential Vault**: AES-256-GCM encryption for all AI API keys & OAuth tokens; never exported in settings JSON or logged.
- **Output & AI Sanitization**: All AI-generated HTML passed through `wp_kses_post()` + custom script/iframe/event-handler stripper.
- **Emergency Stop**: Global option `htd_ai_seo_emergency_stop` immediately halts all cron, queue workers, and AI API calls.
- **Backup & Rollback**: `htd_ai_seo_approval_queue` stores `previous_snapshot` before any `wp_update_post` or WooCommerce product update.
- **Zero Telemetry**: No outbound requests except user-configured official AI/GSC/GA4 APIs.
