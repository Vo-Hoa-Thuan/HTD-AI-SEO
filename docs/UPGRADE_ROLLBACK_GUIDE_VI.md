# HƯỚNG DẪN NÂNG CẤP & ROLLBACK

- **Migration có phiên bản**: `0.2.0` → `0.4.0` → `0.5.0` → `0.6.0` (23 bảng `htd_ai_seo_*`).
- **Deactivate**: Chỉ dọn cron hooks, **không xóa bảng hay dữ liệu**.
- **Uninstall**: Mặc định giữ dữ liệu; chỉ xóa bảng khi `delete_data_on_uninstall=true`.
- **Rollback**: `Migrator::rollbackVersion()` hỗ trợ rollback theo từng version; không tự ghi đè dữ liệu mới chưa backup.
