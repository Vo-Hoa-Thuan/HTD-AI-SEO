# BLOCKERS & OPEN GAPS — PHASE 02

1. **BLK-001 (Security Scanner Binaries Missing — QA10 PARTIAL)**: `phpcs`, `phpstan`, `semgrep`, `yara`, `clamscan`, and `gitleaks` are not installed in the local Windows environment. Deferred to Phase 07 or CI/CD runner.
2. **BLK-002 (No WordPress/MySQL Runtime — QA05/07/08/09/12/13/14 PARTIAL or NOT_RUN)**: `wp`, `mysql`, `mariadb`, `composer`, `phpunit` are not installed in local environment. Core SQL/logic was verified via pure-PHP in-memory `TestWpdb` doubles (13/13 PASS), not live WordPress/MySQL runtime.
3. **BLK-003 (PHP CLI Missing `php_openssl` DLL — QA06 PARTIAL)**: Local Windows CLI PHP 8.3.36 does not have `php_openssl.dll` enabled, so `CredentialVault` correctly demonstrated fail-closed behavior (`VAULT_01_FailClosedWithoutOpenSSL` PASS), while full AES-256-GCM roundtrip awaits a PHP runtime with OpenSSL enabled.
4. **BLK-004 (Independent Reviewer — QA15 NOT_VERIFIED)**: No independent reviewer sign-off yet (scheduled for Phase 08).
