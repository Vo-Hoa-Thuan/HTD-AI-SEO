# UI NAVIGATION MAP — HTD AI SEO WRITER PRO

## 1. Original Screens Audited (13 Views, 385 Controls)
- `settings.php` (129 controls) → **Keep & Refactor** into HTD Settings (AI, Content, Media, SEO, WooCommerce, Automation, Safety Defaults).
- `keywords.php` (38 controls) → **Keep & Refactor** into HTD Keyword Queue.
- `schedule.php` (38 controls) → **Keep & Refactor** into HTD Scheduler & Diagnostics.
- `keyword-audit.php` (32 controls) → **Keep & Refactor** into HTD Keyword Audit & Cannibalization.
- `products.php` (32 controls) → **Keep & Refactor** into HTD WooCommerce Product Engine.
- `rewrite-posts.php` (23 controls) → **Keep & Refactor** into HTD Post Rewrite Engine.
- `logs.php` (8 controls) → **Keep & Refactor** into HTD Audit & Execution Logs.
- `chatgpt-connection.php` (6 controls) → **Keep Optional** in HTD AI Providers.
- `license-client.php` (15), `license-server.php` (38), `free-clients.php` (17), `upgrade.php` (1), `updates.php` (8) → **Excluded/Replaced** with HTD Update & Release Info screen (no SoftViet licensing).

## 2. HTD New Admin Screens (Phase 06)
- **HTD Approval Queue**: Human-in-the-loop review before any post/product publish or update.
- **HTD SEO Intelligence**: Keyword-to-URL Map, Category SEO (Camera hành trình ô tô, Android Box ô tô), GSC/GA4 performance, AI Overview observation.
- **HTD Emergency Stop**: One-click global pause for all cron/queue/AI workers.
