# HƯỚNG DẪN CRON TRÊN LITESPEED / CPANEL

- Plugin đăng ký hook `htd_ai_seo_cron_process` với lịch `htd_every_5_min` và `htd_every_15_min`, có transient lock `htd_ai_seo_cron_lock` chống chạy chồng chéo.
- Mặc định `allow_background_content_generation=false` (an toàn).
- Trên cPanel/LiteSpeed, có thể dùng system cron gọi `wp-cron.php?doing_wp_cron` mỗi 5–15 phút theo cấu hình chuẩn của hosting (không cần sửa crontab tự động từ plugin).
- Khi `emergency_stop=true`, mọi worker và cron đều bị chặn ngay lập tức.
