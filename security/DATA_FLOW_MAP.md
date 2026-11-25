# DATA FLOW MAP

```mermaid
flowchart LR
    Admin[WordPress Admin UI] -->|Nonce + Cap Check| Core[HTD Core / Queue]
    Core -->|Read/Write| DB[(wp_htd_ai_seo_* & wp_options)]
    Core -->|AES-256-GCM| Vault[HTD Credential Vault]
    Core -->|Local Templates| Prompt[HTD Local Prompt Engine]
    Prompt -->|HTTPS + API Key| AI[Official AI APIs: OpenAI / Gemini / Claude / DeepSeek / Grok]
    AI -->|JSON + Content| Sanitizer[wp_kses_post + Schema Validator]
    Sanitizer -->|Approval Gate| Approval[HTD Approval Engine]
    Approval -->|Human Approved| WP[(wp_posts / WooCommerce / Rank Math)]
```

- **Removed Flows**: Zero connections to `softviet.vn` (license, prompt-bundle, update manifest/ZIP).
- **Safety Gate**: `WP_READ_ONLY_MODE=true`, `ALLOW_AUTOPUBLISH=false`, `REQUIRE_HUMAN_APPROVAL=true` prevent unapproved writes to WordPress LIVE.
