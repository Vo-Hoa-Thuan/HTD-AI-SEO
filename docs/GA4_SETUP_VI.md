# HƯỚNG DẪN GOOGLE ANALYTICS 4 (GA4)

- **API chính thức**: Google Analytics Data API `v1beta` (`analyticsdata.googleapis.com`).
- **Chỉ số đọc**: `totalUsers`, `sessions`, `engagedSessions`, `engagementRate`, `screenPageViews`, `averageSessionDuration`, `keyEvents`.
- **Nguyên tắc dữ liệu**: Không đồng nhất GSC clicks với GA4 sessions; không tự bịa doanh thu hoặc suy luận nhân quả khi thiếu bằng chứng.
- **Trạng thái**: Đã kiểm thử parser/payload (`MOCK_PASS`); chưa test LIVE vì thiếu credentials (`BLK-005`).
