# BLOCKERS & OPEN GAPS — PHASE 03

1. **BLK-001 (Security Scanner Binaries Missing — QA04/QA10 PARTIAL)**: `phpcs`, `phpstan`, `semgrep`, `yara`, `clamscan`, and `gitleaks` are not installed locally. Deferred to Phase 07 or CI/CD runner.
2. **BLK-002 (No WordPress/MySQL Runtime — QA05/07/08/09/12/13/14 PARTIAL or NOT_RUN)**: Docker daemon is not running and `wp`/`mysql`/`mariadb` are not installed locally. Core SQL/AI/Prompt/Token logic verified via PHP 8.3 + OpenSSL unit/mock tests (22/22 PASS), not live WordPress/MySQL runtime.
3. **BLK-004 (Independent Reviewer — QA15 NOT_VERIFIED)**: No independent reviewer sign-off yet (scheduled for Phase 08).
4. **BLK-005 (No Live AI API Credentials in Phase 03 — QA06 PARTIAL)**: All 5 official AI provider adapters verified via mock HTTP payloads/responses (`MOCK_PASS`), not `LIVE_PROVIDER_VERIFIED`.

*(Resolved in Phase 03: Previous `BLK-003` PHP OpenSSL resolved via project-scoped `tests/php-cli.ini` — real AES-256-GCM encryption, tamper rejection, and key rotation verified.)*
