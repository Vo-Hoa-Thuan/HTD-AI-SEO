# BLOCKERS & OPEN GAPS — PHASE 04

1. **BLK-001 (Security Scanner Binaries Missing — QA04/QA10 PARTIAL)**: `phpcs`, `phpstan`, `semgrep`, `yara`, `clamscan`, and `gitleaks` are not installed locally. Deferred to Phase 07 or CI/CD runner.
2. **BLK-002 (No WordPress/MySQL Runtime — QA05/07/08/09/12/13/14 PARTIAL or NOT_RUN)**: Docker Desktop daemon is not started (`//./pipe/docker_engine` unavailable) and `wp`/`mysql`/`mariadb` are not installed locally. To unblock: start Docker Desktop so an isolated WordPress + MySQL + WooCommerce + Rank Math container can be launched.
3. **BLK-004 (Independent Reviewer — QA15 NOT_VERIFIED)**: No independent reviewer sign-off yet (scheduled for Phase 08).
4. **BLK-005 (No Live AI API Credentials — QA06 PARTIAL)**: All 5 official AI provider adapters verified via mock HTTP payloads/responses (`MOCK_PASS`), not `LIVE_PROVIDER_VERIFIED`.
5. **BLK-006 (FEAT_007 ChatGPT/Codex OAuth Policy Decision)**: `FEAT_007` (`chatgpt.com/backend-api/codex/*`) awaits user approval on whether to implement OAuth2 PKCE + JWKS or standardize strictly on official OpenAI API Key (`api.openai.com/v1/*`).
