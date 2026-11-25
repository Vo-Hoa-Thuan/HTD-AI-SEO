# HTD DATABASE DESIGN

All tables use prefix `{$wpdb->prefix}htd_ai_seo_`:
1. `htd_ai_seo_keywords`: `id, keyword, secondary_keywords, cluster_id, target_url, search_intent, job_type, target_post_id, status, priority, min_content_words, generation_step, generation_state, lock_token, lock_expires_at, retry_count, created_at, updated_at`
2. `htd_ai_seo_logs`: `id, keyword_id, action, request_redacted, response_redacted, tokens_in, tokens_out, cost_usd, status, created_at`
3. `htd_ai_seo_daily_usage`: `id, usage_date, provider, model, requests_count, tokens_total, cost_usd`
4. `htd_ai_seo_approval_queue`: `id, entity_type, target_post_id, proposed_payload, previous_snapshot, status (pending|approved|rejected|rolled_back), reviewed_by, reviewed_at, created_at`
5. `htd_ai_seo_keyword_url_map`: `id, keyword, target_url, search_intent, cannibalization_risk, gsc_clicks, gsc_impressions, ga4_sessions, updated_at`
6. `htd_ai_seo_seo_history`: `id, post_id, change_type, before_json, after_json, changed_by, created_at`
