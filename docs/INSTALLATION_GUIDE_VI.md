# HƯỚNG DẪN CÀI ĐẶT (STAGING TRƯỚC — KHÔNG CÀI TRỰC TIẾP LÊN LIVE)

1. Chuẩn bị môi trường WordPress staging cách ly (PHP 8.1+, bật extension `openssl`, `mbstring`, `curl`, `mysqli`).
   - Có thể dùng `tests/docker-compose.staging.yml`: `docker compose -f tests/docker-compose.staging.yml up -d`.
2. Vào **WP Admin → Plugins → Add New → Upload Plugin**, chọn file `build/htd-ai-seo-writer-pro-0.8.0-dev.zip` và bấm **Install Now → Activate**.
3. Khi kích hoạt, plugin tự tạo 23 bảng `{$wpdb->prefix}htd_ai_seo_*` và bật cấu hình an toàn mặc định (`WP_READ_ONLY_MODE=true`, `ALLOW_AUTOPUBLISH=false`, `REQUIRE_HUMAN_APPROVAL=true`).
4. Không gọi API ngoài hoặc tạo bài viết/sản phẩm mẫu khi kích hoạt.
