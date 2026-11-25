# HƯỚNG DẪN BACKUP & DISASTER RECOVERY

1. Sử dụng `BackupRestoreService::exportBackup()` để xuất JSON toàn bộ 13 bảng dữ liệu nghiệp vụ kèm `checksum` SHA-256 (không xuất `htd_ai_seo_credentials`).
2. Trước khi khôi phục (`restoreBackup()`), hệ thống kiểm tra SHA-256 checksum; nếu sai lệch sẽ hủy khôi phục ngay.
3. **Runbook khôi phục sự cố**: Bật `EMERGENCY_STOP=true` → Chụp snapshot DB MySQL staging/production → Kiểm tra `htd_ai_seo_logs` → Khôi phục từ bản backup gần nhất đã xác minh checksum.
