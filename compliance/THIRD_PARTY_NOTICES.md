# THIRD-PARTY NOTICES

## 1. Upstream Reference Code
- **SoftViet AI SEO Auto Writer** (v26.10.20.02) by Soft Viet Digital (`https://softviet.vn`)
- **License**: GNU General Public License v2.0 or later (`GPL-2.0-or-later`)
- **Notice**: Any derivative modules refactored from GPLv2+ source files retain their GPLv2+ license and upstream attribution.

## 2. External APIs & Protocols (Runtime Dependencies Only — No Bundled SDKs)
Static audit confirmed **no bundled Composer/npm vendor libraries** inside `softviet-ai-seo-writer.zip`. All AI/OAuth integrations use native WordPress HTTP API (`wp_remote_post` / `wp_remote_get`) and PHP OpenSSL:
- **OpenAI API & ChatGPT/Codex OAuth**: Subject to OpenAI Terms of Use (`https://openai.com/policies/terms-of-use/`) and Privacy Policy (`https://openai.com/policies/privacy-policy/`).
- **Google Gemini API**: Subject to Google APIs Terms of Service.
- **Anthropic Claude API**: Subject to Anthropic Commercial/Consumer Terms.
- **DeepSeek API**: Subject to DeepSeek Terms of Service.
- **xAI Grok API**: Subject to xAI Terms of Service.
