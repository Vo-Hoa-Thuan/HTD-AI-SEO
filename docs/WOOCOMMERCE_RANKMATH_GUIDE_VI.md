# HƯỚNG DẪN WOOCOMMERCE & RANK MATH

- **WooCommerce (`Products`)**: Chỉ cập nhật `description` và `short_description` sau khi qua `ApprovalEngine` và `MutationGuard`. Tuyệt đối bảo toàn ID, SKU, URL, Slug, Giá, Tồn kho, Variations, Attributes.
- **MASTER PRODUCT HTD**: Hỗ trợ nhập JSON/SKU để phát hiện xung đột dữ liệu với WooCommerce (`NEEDS_VERIFICATION`) mà không tự ghi đè.
- **Rank Math**: Đọc/ghi `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword` khi được duyệt; giữ canonical/robots/schema ở chế độ chỉ đọc. Nếu thiếu WooCommerce hoặc Rank Math, plugin không lỗi fatal.
