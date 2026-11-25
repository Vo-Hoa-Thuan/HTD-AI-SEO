# IMPLEMENTATION ROADMAP (08 PHASES)

- **PHASE 01 (DONE)**: Source Discovery, License Audit, Security Audit, Feature Parity, HTD Architecture, QA15 Framework.
- **PHASE 02**: Independent Core (`htd-ai-seo-writer-pro`), PSR-4 autoloader, `HTD Database` migrations, `HTD Settings` with safe defaults, `HTD Logging Engine`, `HTD Update Manager` stub, Activator/Uninstall (`FEAT_031`, `FEAT_033`–`FEAT_039`).
- **PHASE 03**: `HTD Credential Vault`, `HTD AI Providers` (OpenAI, Gemini, Claude, DeepSeek, Grok, optional OAuth), `HTD Prompt Engine` (local templates replacing `softviet.vn` prompt-bundle), Token Optimization (`FEAT_001`–`FEAT_009`, `FEAT_015`, `FEAT_016`, `FEAT_018`).
- **PHASE 04**: `HTD Keyword Engine` (Queue, Clustering, Audit, Action), `HTD Content Engine`, `HTD Rewrite Engine`, `HTD Media Engine` (`FEAT_010`–`FEAT_014`, `FEAT_017`, `FEAT_019`–`FEAT_023`).
- **PHASE 05**: `HTD Product Engine` (WooCommerce), `HTD SEO Engine` (Rank Math), `HTD Internal Link Engine`, `HTD Queue/Scheduler`, `HTD Admin UI` (`FEAT_024`–`FEAT_030`, `FEAT_032`).
- **PHASE 06**: HTD SEO Extensions (`HTD_EXT_001`–`HTD_EXT_010`: GSC, GA4, Category SEO for Camera hành trình ô tô & Android Box ô tô, MASTER PRODUCT HTD validation, AI Overview Observation, Human Approval Gate).
- **PHASE 07**: Security Hardening, Full QA15 Execution (including YARA/ClamAV/Semgrep/PHPCS), Regression, Performance, E2E.
- **PHASE 08**: Staging Acceptance, `htd-ai-seo-writer-pro.zip` Packaging, Documentation, GitHub Private Release.
