# BLOCKERS & OPEN GAPS — PHASE 06

1. **BLK-001 (Security Scanner Binaries Missing — QA04/QA10 PARTIAL)**: `phpcs`, `phpstan`, `semgrep`, `yara`, `clamscan`, and `gitleaks` are not installed locally. Deferred to Phase 07 or CI/CD runner.
2. **BLK-002 (BLOCKED_RUNTIME — Docker Desktop Daemon Not Running)**: Docker Desktop daemon is not started (`//./pipe/docker_engine` unavailable) and `wp`/`mysql`/`mariadb` are not installed locally. *Action Required*: Start Docker Desktop and run `docker compose -f tests/docker-compose.staging.yml up -d`.
3. **BLK-004 (Independent Reviewer — QA15 NOT_VERIFIED)**: No independent reviewer sign-off yet (scheduled for Phase 08).
4. **BLK-005 (No Live AI/GSC/GA4 Credentials — QA06 PARTIAL)**: All AI, GSC, and GA4 adapters verified via mock HTTP contracts & CSV imports (`MOCK_PASS`), not `LIVE_API_VERIFIED`.
5. **BLK-006 (FEAT_007 ChatGPT/Codex OAuth — BLOCKED)**: `FEAT_007` (`chatgpt.com/backend-api/codex/*`) remains `BLOCKED` pending explicit user decision on OAuth2 PKCE vs official OpenAI API Key (`api.openai.com/v1/*`).
