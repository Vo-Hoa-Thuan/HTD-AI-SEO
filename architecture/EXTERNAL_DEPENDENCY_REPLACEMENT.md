# EXTERNAL DEPENDENCY REPLACEMENT PLAN

| Upstream SoftViet Dependency | HTD Independent Replacement | Notes |
|---|---|---|
| `SV_AI_License_Service` / `softviet.vn/wp-json/.../license/*` | **HTD Configuration Manager** | Self-hosted, zero time/site limits, no external license checks. |
| `softviet.vn/.../ai/prompt-bundle` & `keyword-cluster-prompt` | **HTD Local Prompt Engine** | Versioned, editable PHP/JSON prompt templates owned by HTD. |
| `SV_AI_Updater` (`softviet.vn/*.json`, `*.zip`) | **HTD Update Manager** | GitHub Private / manual ZIP release workflow; `ALLOW_REMOTE_UPDATE=false` by default. |
| `SV_AI_OpenAI_Client` | **HTD Provider Adapter** | Clean interface per provider (OpenAI, Gemini, Claude, DeepSeek, Grok) using HTD API keys. |
| `SV_AI_Credential_Store` | **HTD Credential Vault** | AES-256-GCM encryption for all API keys & OAuth tokens, excluded from exports. |
| `SV_AI_DB::log` | **HTD Logging Engine** | Full audit trail with strict secret redaction across all providers. |
