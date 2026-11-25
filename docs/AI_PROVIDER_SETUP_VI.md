# HƯỚNG DẪN CẤU HÌNH AI PROVIDERS & CHI PHÍ

1. Vào **HTD AI SEO PRO → Credentials** để lưu API Key vào **HTD Credential Vault** (mã hóa AES-256-GCM; không hiển thị plaintext).
2. Vào **HTD AI SEO PRO → AI Providers** để bật/tắt provider (`openai`, `gemini`, `claude`, `deepseek`, `grok`, hoặc `custom` HTTPS-only), chọn model, đặt `daily_budget_usd` và `monthly_budget_usd`.
3. Vào **Settings** bật `allow_external_ai_requests` khi sẵn sàng gọi API thật.
4. **Lưu ý chi phí**: Mọi request đều tính toán ngân sách trước khi gửi; bảng giá trong `ModelRegistry` mang tính tham khảo và có thể chỉnh sửa.
5. **Ghi chú OAuth (`FEAT_007`)**: ChatGPT/Codex OAuth hiện ở trạng thái `BLOCKED` chờ quyết định phạm vi; sử dụng OpenAI API Key chính thức (`api.openai.com`).
