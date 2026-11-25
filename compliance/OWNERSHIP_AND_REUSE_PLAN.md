# OWNERSHIP AND REUSE PLAN

## 1. Clean-Room & GPL Strategy
- **Zero SoftViet Server Dependency**: Remove all calls to `https://softviet.vn` (license activate/check/deactivate, prompt-bundle, keyword-cluster-prompt, remote update manifest/ZIP).
- **Zero SoftViet Brand Assets**: Exclude all 8 PNG assets (`wordpress-org-assets/*`, `wporg-assets`) and all SoftViet logos, QR codes (`QRVCB.png`), Facebook/Zalo links, and `sv_ai_*` prefixes.
- **HTD Namespace & Prefixing**: Use `HTD\AiSeoWriterPro\` namespace, `htd_ai_seo_` option/DB/cron/AJAX prefixes, and `htd-ai-seo-writer-pro` text domain/slug.
- **Independent Modules Owned by HTD**:
  - `HTD Prompt Engine`: Local, versioned, customizable SEO prompt templates managed by HTD.
  - `HTD Provider Adapter`: Unified interface for OpenAI, Gemini, Claude, DeepSeek, Grok, and optional OAuth using HTD-owned API keys.
  - `HTD Update & Config Manager`: GitHub Private / HTD-controlled release & configuration without external telemetry or kill-switches.
  - `HTD SEO Extensions`: Keyword-to-URL mapping, cannibalization detection, GSC/GA4 integration, WooCommerce MASTER PRODUCT HTD validation, and Human Approval workflow.
