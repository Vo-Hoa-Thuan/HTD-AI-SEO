# LICENSE COMPLIANCE AUDIT — HTD AI SEO WRITER PRO

## 1. Summary
- **Reference Source**: `softviet-ai-seo-writer.zip` (Version `26.10.20.02`, SHA-256: `fb423524b51bc057a8fb5babad8eda744d4d3d91d57620226fe44f328beb3b10`)
- **Declared License**: `GPL v2 or later` (`https://www.gnu.org/licenses/gpl-2.0.html`) in `softviet-ai-seo-writer.php`, `license.txt`, `readme.txt`, `readme-public.txt`.
- **Total Files Audited**: 60 / 60 (100%).

## 2. Classification Breakdown
| Category | Count | Meaning & HTD Rule |
|---|---|---|
| `A. GPL_REUSE_ALLOWED` | 42 | Core PHP/JS/CSS/Docs/PO files licensed under GPLv2+. HTD may use, modify, and distribute under GPLv2+ while preserving original copyright notices on derived code. |
| `C. REIMPLEMENT_REQUIRED` | 10 | SoftViet commercial licensing client/server & remote updater (`class-sv-ai-license-*.php`, `class-sv-ai-updater.php`, `license*.php`, `free-clients.php`, `upgrade.php`, `updates.php`). Excluded/reimplemented independently by HTD. |
| `F. BRAND_ASSET_EXCLUDED` | 8 | SoftViet PNGs in `wordpress-org-assets/` (7 files) and `wporg-assets` (1 PNG file). Strictly excluded; HTD will create original brand assets. |

## 3. Rights & Obligations Distinction
1. **Right to Use / Modify / Redistribute**: Granted by GPLv2+ for GPL-covered code. HTD **must** distribute `htd-ai-seo-writer-pro` under GPLv2+ if it contains derived GPL code.
2. **Ownership of Newly Authored Code**: HTD owns copyright on all newly written HTD modules (HTD Core, Local Prompt Engine, GSC/GA4 Integrations, HTD SEO Extensions, HTD Admin UI), while respecting GPLv2+ copyleft on the combined plugin.
3. **Attribution & Copyright Preservation**: HTD will NOT claim exclusive authorship over derived GPL code and will preserve SoftViet Digital attribution in any refactored GPL files + `THIRD_PARTY_NOTICES.md`.
4. **Commercial Services & Remote Prompts**: GPLv2+ covers the plugin PHP/JS code, **NOT** SoftViet's server-side prompt bundles, licensing API, or commercial SaaS. HTD will NOT spoof license keys, bypass auth, or scrape `softviet.vn`.
