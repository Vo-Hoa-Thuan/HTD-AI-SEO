# HƯỚNG DẪN GOOGLE SEARCH CONSOLE (GSC)

- **API chính thức**: Sử dụng scope chỉ đọc `https://www.googleapis.com/auth/webmasters.readonly` (`searchconsole.googleapis.com`).
- **Import thủ công (CSV)**: Hỗ trợ nhập báo cáo `search_performance`, `queries`, `pages`, `search_appearance`, `links`, `indexing`, `generative_ai_features` với kiểm tra `dataset_hash` chống trùng lặp.
- **Lưu ý**: Hiện đã kiểm thử contract API & CSV import (`MOCK_PASS`); kết nối Google OAuth LIVE chưa kiểm thử vì thiếu credentials (`BLK-005`).
