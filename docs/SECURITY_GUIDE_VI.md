# HƯỚNG DẪN BẢO MẬT & PHÂN QUYỀN

- **Cấu hình an toàn mặc định**: `WP_READ_ONLY_MODE=true`, `ALLOW_AUTOPUBLISH=false`, `ALLOW_PRODUCT_UPDATE=false`, `ALLOW_REMOTE_UPDATE=false`, `ALLOW_EXTERNAL_TELEMETRY=false`, `REQUIRE_HUMAN_APPROVAL=true`.
- **7 Capabilities riêng**: `htd_ai_seo_manage`, `htd_ai_seo_view_logs`, `htd_ai_seo_manage_settings`, `htd_ai_seo_manage_credentials`, `htd_ai_seo_review`, `htd_ai_seo_approve`, `htd_ai_seo_publish` (chỉ cấp cho `administrator` khi kích hoạt).
- **Credential Vault**: Mã hóa AES-256-GCM; fail-closed nếu thiếu OpenSSL.
- **ExternalRequestGuard**: Default-deny, chỉ HTTPS, chống SSRF (chặn loopback/private/IPv6/metadata), chặn tuyệt đối `softviet.vn`.
