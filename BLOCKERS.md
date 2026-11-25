# BLOCKERS & OPEN GAPS — PHASE 08 (FINAL HANDOVER)

| ID | Category | Root Cause | Required Action | Responsible | Status |
|---|---|---|---|---|---|
| `BLK-001` | Security Toolchain | `phpcs`, `phpstan`, `semgrep`, `yara`, `clamscan`, `gitleaks` not installed locally | Install toolchain in CI/container and run full scan | DevOps / User | `BLOCKED_BY_ENVIRONMENT` |
| `BLK-002` | WordPress Runtime | Docker Desktop daemon not started (`//./pipe/docker_engine` unavailable) | Start Docker Desktop & run `docker compose -f tests/docker-compose.staging.yml up -d` | User | `BLOCKED_BY_USER` |
| `BLK-004` | Independent QA15 | No independent human reviewer assigned yet | Assign independent reviewer to audit package & sign off QA15 | User / QA Lead | `BLOCKED_BY_USER` |
| `BLK-005` | Live API Credentials | No live OpenAI/Gemini/Claude/DeepSeek/Grok/GSC/GA4 credentials provided | Configure staging credentials in Vault & run minimal smoke tests | User | `BLOCKED_BY_USER` |
| `BLK-006` | `FEAT_007` OAuth | User approved Option B (keep `FEAT_007`). SoftViet's `app_EMoamEEZ73f0CkXaXp7hrann` / `codex_cli_rs` / `chatgpt.com/backend-api/codex` cannot be reused; official OpenAI SIWC (`developers.openai.com/siwc/token-sharing-open-source`) requires registered `oaiapp_*` client ID or live SIWC verification | Register official HTD `oaiapp_*` OAuth client with OpenAI & verify on staging | User / OpenAI | `BLOCKED_BY_PROVIDER_SUPPORT` |
