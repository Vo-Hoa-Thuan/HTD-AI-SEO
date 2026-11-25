# XỬ LÝ SỰ CỐ (TROUBLESHOOTING)

- **Lỗi CredentialVault fail-closed**: Kiểm tra PHP `openssl` extension đã bật và hỗ trợ `aes-256-gcm`.
- **Request AI bị chặn (`REQUEST_BLOCKED`)**: Kiểm tra `emergency_stop=false` và `allow_external_ai_requests=true` trong **Settings**.
- **Mutation bị chặn (`403`)**: Kiểm tra `wp_read_only_mode=false`, có `approval_id` trạng thái `APPROVED`, và `payload_hash` không bị thay đổi sau khi duyệt.
- **Cron không chạy**: Kiểm tra `allow_background_content_generation=true` và transient lock `htd_ai_seo_cron_lock`.
