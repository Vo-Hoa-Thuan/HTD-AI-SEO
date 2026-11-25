# KNOWN LIMITATIONS (GIỚI HẠN HIỆN TẠI)

1. **Chưa kiểm thử WordPress/MySQL/WooCommerce/Rank Math Runtime (`BLK-002`)**: Docker Desktop chưa chạy nên các kiểm thử hiện tại chạy trên PHP 8.3 CLI + OpenSSL + `TestWpdb` in-memory.
2. **Chưa chạy SAST/AV Toolchain (`BLK-001`)**: `phpcs`, `phpstan`, `semgrep`, `yara`, `clamscan`, `gitleaks` chưa được cài đặt trên máy local.
3. **Chưa kiểm thử API LIVE (`BLK-005`)**: OpenAI, Gemini, Claude, DeepSeek, Grok, Custom Endpoint, Image Gen, GSC, GA4 mới xác minh contract bằng mock (`MOCK_PASS`).
4. **Chưa có Reviewer độc lập (`BLK-004`)**: QA15 giữ trạng thái `NOT_VERIFIED`.
5. **`FEAT_007` ChatGPT/Codex OAuth (`BLK-006`)**: Đang `BLOCKED` chờ người dùng phê duyệt dùng OpenAI API Key thay thế hay triển khai OAuth chính thức.
