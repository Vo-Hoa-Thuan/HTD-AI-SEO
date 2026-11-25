# SECURITY & MALWARE AUDIT — PHASE 01

## 1. Executive Summary
- **Scope**: 60/60 files in `softviet-ai-seo-writer.zip` (static analysis only; zero code executed).
- **Confirmed Malware**: `0` (`eval`, `shell_exec`, `exec`, `system`, `passthru`, `proc_open`, `unserialize`, `gzinflate`, `create_function`, `sslverify => false` all returned **0 hits**).
- **Findings Summary**:
  - **CRITICAL**: 0
  - **HIGH**: 2 (`SEC_001` Remote Update Supply Chain to `softviet.vn`, `SEC_002` External Data Transmission to `softviet.vn`)
  - **MEDIUM**: 2 (`SEC_003` Public REST routes in Server Mode, `SEC_004` AI-generated HTML sanitization)
  - **LOW**: 3 (`SEC_005` Unauthenticated loopback ping, `SEC_006` Dynamic include false positive, `SEC_007` Partial API key masking in logs)

## 2. Positive Security Controls Observed in Reference
- `SV_AI_Credential_Store` uses `openssl_encrypt` (`aes-256-gcm`) with `wp_salt('auth') . wp_salt('secure_auth')` and atomic DB locking.
- `SV_AI_Cron::background_generate()` verifies a one-time transient token (`sv_ai_bg_{id}`) with `hash_equals()` and immediately deletes it.
- All 10 admin AJAX/form handlers verify both WordPress nonces and `manage_options` capability.
- All dynamic SQL queries use `$wpdb->prepare()` or `$wpdb->insert()`/`update()`.

## 3. Scanners Status
- **Executed**: `php -l` (43 PHP files, 0 errors), Custom PHP AST + Regex Security Scanner (60 files).
- **NOT_RUN**: `phpcs`, `phpstan`, `semgrep`, `yara`, `clamscan`, `gitleaks` (not installed in local environment).
