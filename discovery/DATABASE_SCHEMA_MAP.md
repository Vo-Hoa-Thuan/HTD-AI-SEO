# DATABASE SCHEMA MAP — REFERENCE VS HTD DESIGN

## 1. Original Plugin Custom Tables (`SV_AI_DB` & `SV_AI_License_Service`)
1. `{$wpdb->prefix}sv_ai_keywords` — Keyword queue, secondary keywords, status (`pending`, `processing`, `pause`, `done`, `failed`), `job_type`, `target_post_id`, `min_content_words`, `generation_step`, `generation_state`.
2. `{$wpdb->prefix}sv_ai_logs` — Execution logs (`keyword_id`, `action`, `request`, `response` with masked `sk-*`, `status`, `created_at`).
3. `{$wpdb->prefix}sv_ai_client_sites` — Client site tracking (with `license_plan` column).
4. `{$wpdb->prefix}sv_ai_daily_usage` — Daily generation quota tracking.
5. `{$wpdb->prefix}sv_ai_licenses` (Server mode only — **EXCLUDED in HTD**).
6. `{$wpdb->prefix}sv_ai_license_activations` (Server mode only — **EXCLUDED in HTD**).

## 2. WordPress Core Tables & Meta Keys Used
- **Options (`wp_options`)**: `sv_ai_writer_settings`, `sv_ai_writer_db_version`, `sv_ai_chatgpt_credentials` (AES-256-GCM encrypted), `sv_ai_chatgpt_credentials_lock`, `sv_ai_chatgpt_models`, `sv_ai_chatgpt_cooldown`, `sv_ai_chatgpt_auth_epoch`.
- **Post Meta (`wp_postmeta`)**: Rank Math (`rank_math_focus_keyword`, `rank_math_title`, `rank_math_description`, `rank_math_seo_score`), Yoast (`_yoast_wpseo_focuskw`, `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`), WooCommerce product meta, attachment alt text (`_wp_attachment_image_alt`).
- **Transients**: `sv_ai_bg_{keyword_id}` (one-time loopback token), `sv_ai_writer_processing_lock`, `sv_ai_writer_cron_cooldown`, `sv_ai_chatgpt_jwks`.

## 3. Proposed HTD Prefixes (Zero Conflict)
- **Namespace**: `HTD\AiSeoWriterPro\`
- **DB Table Prefix**: `{$wpdb->prefix}htd_ai_seo_*` (`keywords`, `logs`, `daily_usage`, `keyword_url_map`, `seo_history`, `approval_queue`)
- **Option Prefix**: `htd_ai_seo_*`
- **Cron Hook Prefix**: `htd_ai_seo_cron_*`
- **AJAX / REST Namespace**: `wp_ajax_htd_ai_seo_*` / `htd-ai-seo/v1`
