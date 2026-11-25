import csv
import json
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
SOURCE_INDEX = json.loads((WS / "discovery" / "SOURCE_INDEX.json").read_text(encoding="utf-8"))
DEEP_AUDIT = json.loads((WS / "discovery" / "DEEP_STATIC_AUDIT.json").read_text(encoding="utf-8"))
UI_RAW = json.loads((WS / "discovery" / "UI_RAW.json").read_text(encoding="utf-8"))

with open(WS / "discovery" / "SOURCE_INVENTORY.csv", encoding="utf-8") as f:
    INV_ROWS = list(csv.DictReader(f))

# 1. LICENSE_COMPONENT_MATRIX.csv
lic_rows = []
for row in INV_ROWS:
    rel = row["RELATIVE_PATH"]
    low = rel.lower()
    if "wordpress-org-assets/" in low or low.endswith("wporg-assets"):
        cat = "F. BRAND_ASSET_EXCLUDED"
        reuse = "EXCLUDE"
        obligations = "Do not reuse SoftViet logos, banners, icons, or screenshots."
    elif any(x in low for x in ["license-api", "license-service", "license-server", "license-client", "license.php", "free-clients", "upgrade.php", "updater", "updates.php"]):
        cat = "C. REIMPLEMENT_REQUIRED"
        reuse = "REPLACE_OR_EXCLUDE"
        obligations = "SoftViet commercial licensing/updater; exclude SoftViet endpoints and reimplement HTD config/update engine."
    elif "chatgpt-oauth" in low or "chatgpt-client" in low or "chatgpt.js" in low or "chatgpt-connection" in low:
        cat = "A. GPL_REUSE_ALLOWED"
        reuse = "REFACTOR_WITH_EXTERNAL_TERMS"
        obligations = "GPLv2+ PHP/JS code; connects to OpenAI OAuth/Codex external service subject to OpenAI Terms."
    elif low.endswith(".po"):
        cat = "A. GPL_REUSE_ALLOWED"
        reuse = "REIMPLEMENT_HTD_I18N"
        obligations = "GPLv2+ translation file; create fresh HTD POT/PO/MO."
    else:
        cat = "A. GPL_REUSE_ALLOWED"
        reuse = "REUSE_OR_REFACTOR_GPL"
        obligations = "GPLv2 or later per plugin header & license.txt. Must preserve original copyright/GPLv2+ notices on derived code."

    lic_rows.append({
        "FILE_ID": row["FILE_ID"],
        "RELATIVE_PATH": rel,
        "MODULE": row["MODULE"],
        "LICENSE_CLASSIFICATION": cat,
        "DECLARED_LICENSE": "GPL-2.0-or-later" if cat != "F. BRAND_ASSET_EXCLUDED" else "PROPRIETARY_BRAND_ASSET",
        "REUSE_DECISION": reuse,
        "OBLIGATIONS_AND_NOTES": obligations,
    })

with open(WS / "compliance" / "LICENSE_COMPONENT_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(lic_rows[0].keys()))
    w.writeheader()
    w.writerows(lic_rows)

# 2. FEATURE_PARITY_MATRIX.csv & FEATURE_REGISTRY.json
# Comprehensive list of verified features across modules A-J
FEATURES = [
    # A. AI PROVIDERS
    ("FEAT_001", "AI_PROVIDERS", "OpenAI Chat Completions API", "Generate text/JSON via OpenAI /v1/chat/completions with API key", "softviet-ai-seo-writer/includes/class-sv-ai-openai-client.php", 1, "settings.php#api", "SV_AI_OpenAI_Client::json_completion", "wp_options:sv_ai_writer_settings", "https://api.openai.com/v1/chat/completions", "GPL-2.0+", "OpenAI API", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_03", "AT_001"),
    ("FEAT_002", "AI_PROVIDERS", "Custom OpenAI-Compatible Base URL", "Configure custom base URL & model for OpenAI-compatible endpoints", "softviet-ai-seo-writer/includes/class-sv-ai-openai-client.php", 1, "settings.php#api", "SV_AI_OpenAI_Client", "wp_options:sv_ai_writer_settings", "Custom endpoint", "GPL-2.0+", "Custom AI API", "LOCAL_VERIFIED", "REFACTOR_GPL", "MEDIUM", "PHASE_03", "AT_002"),
    ("FEAT_003", "AI_PROVIDERS", "Google Gemini Provider", "Generate text/JSON via Gemini generateContent API", "softviet-ai-seo-writer/includes/class-sv-ai-openai-client.php", 1, "settings.php#api", "SV_AI_OpenAI_Client", "wp_options:sv_ai_writer_settings", "https://generativelanguage.googleapis.com/v1beta/models/", "GPL-2.0+", "Google Gemini API", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_03", "AT_003"),
    ("FEAT_004", "AI_PROVIDERS", "Anthropic Claude Provider", "Generate text/JSON via Claude /v1/messages API", "softviet-ai-seo-writer/includes/class-sv-ai-openai-client.php", 1, "settings.php#api", "SV_AI_OpenAI_Client", "wp_options:sv_ai_writer_settings", "https://api.anthropic.com/v1/messages", "GPL-2.0+", "Anthropic API", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_03", "AT_004"),
    ("FEAT_005", "AI_PROVIDERS", "DeepSeek Provider", "Generate text/JSON via DeepSeek /chat/completions API", "softviet-ai-seo-writer/includes/class-sv-ai-openai-client.php", 1, "settings.php#api", "SV_AI_OpenAI_Client", "wp_options:sv_ai_writer_settings", "https://api.deepseek.com/chat/completions", "GPL-2.0+", "DeepSeek API", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_03", "AT_005"),
    ("FEAT_006", "AI_PROVIDERS", "xAI Grok Provider", "Generate text/JSON via xAI /v1/chat/completions API", "softviet-ai-seo-writer/includes/class-sv-ai-openai-client.php", 1, "settings.php#api", "SV_AI_OpenAI_Client", "wp_options:sv_ai_writer_settings", "https://api.x.ai/v1/chat/completions", "GPL-2.0+", "xAI API", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_03", "AT_006"),
    ("FEAT_007", "AI_PROVIDERS", "ChatGPT / Codex OAuth & JWKS", "OAuth2 PKCE sign-in, JWKS ID token verification, token refresh lock, Codex inference", "softviet-ai-seo-writer/includes/class-sv-ai-chatgpt-oauth.php", 1, "chatgpt-connection.php", "SV_AI_ChatGPT_OAuth::ajax", "wp_options:sv_ai_chatgpt_credentials", "https://auth.openai.com, https://chatgpt.com/backend-api/codex", "GPL-2.0+", "OpenAI OAuth/Codex", "LOCAL_VERIFIED", "REIMPLEMENT_INDEPENDENTLY", "MEDIUM", "PHASE_03", "AT_007"),
    ("FEAT_008", "AI_PROVIDERS", "Encrypted Credential Store", "AES-256-GCM encryption of OAuth tokens using wp_salt + atomic DB lock", "softviet-ai-seo-writer/includes/class-sv-ai-credential-store.php", 5, "chatgpt-connection.php", "SV_AI_Credential_Store", "wp_options:sv_ai_chatgpt_credentials", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_03", "AT_008"),
    ("FEAT_009", "AI_PROVIDERS", "AI Request Retry, Timeout & Token Budget", "Adaptive token budget, strict JSON retry prompt, timeout handling", "softviet-ai-seo-writer/includes/class-sv-ai-openai-client.php", 1, "settings.php#api", "SV_AI_OpenAI_Client::execute_json_request", "None", "AI Provider APIs", "GPL-2.0+", "AI Providers", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_03", "AT_009"),

    # B. KEYWORD MANAGEMENT
    ("FEAT_010", "KEYWORDS", "Keyword Queue CRUD", "Add, list, filter, pause, resume, delete keywords with primary/secondary keywords", "softviet-ai-seo-writer/includes/class-sv-ai-keyword-service.php", 1, "keywords.php", "SV_AI_Keyword_Service", "wp_sv_ai_keywords", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_04", "AT_010"),
    ("FEAT_011", "KEYWORDS", "Keyword Clustering & Batch Queue", "Analyze seed keywords into clusters and accept into queue", "softviet-ai-seo-writer/includes/class-sv-ai-keyword-service.php", 1, "keywords.php", "SV_AI_Admin_Menu::ajax_analyze_keyword_clusters", "wp_sv_ai_keywords", "AI Provider / SoftViet remote", "GPL-2.0+", "AI / SoftViet", "LOCAL_VERIFIED", "REIMPLEMENT_INDEPENDENTLY", "LOW", "PHASE_04", "AT_011"),
    ("FEAT_012", "KEYWORDS", "Keyword Audit Service", "Audit existing site keywords/posts for SEO gaps and cannibalization", "softviet-ai-seo-writer/includes/class-sv-ai-keyword-audit-service.php", 1, "keyword-audit.php", "SV_AI_Keyword_Audit_Service", "wp_posts, wp_sv_ai_keywords", "AI Provider", "GPL-2.0+", "AI Provider", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_04", "AT_012"),
    ("FEAT_013", "KEYWORDS", "Keyword Action Service", "Execute actions on audited keywords (queue new post, rewrite, ignore)", "softviet-ai-seo-writer/includes/class-sv-ai-keyword-action-service.php", 1, "keyword-audit.php", "SV_AI_Keyword_Action_Service", "wp_sv_ai_keywords", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_04", "AT_013"),
    ("FEAT_014", "KEYWORDS", "Inline Keyword Title/Images/Categories Edit", "AJAX update of keyword title, media images, and target categories", "softviet-ai-seo-writer/admin/class-sv-ai-admin-menu.php", 1, "keywords.php", "SV_AI_Admin_Menu::ajax_update_keyword_*", "wp_sv_ai_keywords", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_04", "AT_014"),

    # C. CONTENT GENERATION
    ("FEAT_015", "CONTENT_GEN", "Local Prompt Engine & Outline Generation", "Build SEO outline, article sections, FAQ, meta, slug, excerpt, tags", "softviet-ai-seo-writer/includes/class-sv-ai-content-generator.php", 1, "keywords.php", "SV_AI_Content_Generator::generate_for_keyword", "wp_sv_ai_keywords, wp_options", "AI Provider", "GPL-2.0+", "AI Provider", "LOCAL_VERIFIED", "REIMPLEMENT_INDEPENDENTLY", "MEDIUM", "PHASE_03", "AT_015"),
    ("FEAT_016", "CONTENT_GEN", "Remote Prompt Bundle Client Call", "Fetch prompt bundle from softviet.vn in commercial client mode", "softviet-ai-seo-writer/includes/class-sv-ai-content-generator.php", 1, "None (backend)", "SV_AI_Content_Generator", "wp_options", "https://softviet.vn/wp-json/sv-ai-writer/v1/ai/prompt-bundle", "PROPRIETARY_REMOTE", "SoftViet Server", "REMOTE_DEPENDENT", "REIMPLEMENT_INDEPENDENTLY", "HIGH", "PHASE_03", "AT_016"),
    ("FEAT_017", "CONTENT_GEN", "Heading Depth & Word Count Control", "Control H2/H3/H4 depth, min_content_words, quality refinement passes", "softviet-ai-seo-writer/includes/class-sv-ai-content-generator.php", 1, "settings.php#content", "SV_AI_Content_Generator", "wp_options, wp_sv_ai_keywords", "AI Provider", "GPL-2.0+", "AI Provider", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_04", "AT_017"),
    ("FEAT_018", "CONTENT_GEN", "Multilingual Content & Brand Prompt", "Support Vietnamese, English, Chinese content + custom brand voice/CTA", "softviet-ai-seo-writer/includes/class-sv-ai-content-generator.php", 1, "settings.php#content", "SV_AI_Content_Generator", "wp_options", "AI Provider", "GPL-2.0+", "AI Provider", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_03", "AT_018"),
    ("FEAT_019", "CONTENT_GEN", "Checkpoint Resume for Long Articles", "Save generation_step & generation_state to resume interrupted jobs", "softviet-ai-seo-writer/includes/class-sv-ai-content-generator.php", 1260, "keywords.php", "SV_AI_Content_Generator::queue_checkpoint_resume", "wp_sv_ai_keywords", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_04", "AT_019"),

    # D. POST REWRITE
    ("FEAT_020", "POST_REWRITE", "Existing Post Selection & Analysis", "List existing WP posts, inspect SEO/content metrics for rewrite", "softviet-ai-seo-writer/includes/class-sv-ai-post-rewrite-service.php", 1, "rewrite-posts.php", "SV_AI_Post_Rewrite_Service", "wp_posts, wp_postmeta", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_04", "AT_020"),
    ("FEAT_021", "POST_REWRITE", "AI Post Rewrite & Metadata Update", "Rewrite post content while preserving URL/slug and updating post meta", "softviet-ai-seo-writer/includes/class-sv-ai-post-rewrite-publisher.php", 1, "rewrite-posts.php", "SV_AI_Post_Rewrite_Publisher", "wp_posts, wp_postmeta", "AI Provider", "GPL-2.0+", "AI Provider", "LOCAL_VERIFIED", "REFACTOR_GPL", "MEDIUM", "PHASE_04", "AT_021"),

    # E. IMAGE & MEDIA
    ("FEAT_022", "MEDIA", "AI Image Generation Runtime", "Generate images via OpenAI /v1/images/generations, decode base64, upload to Media Library", "softviet-ai-seo-writer/includes/class-sv-ai-image-generator-runtime.php", 1, "settings.php#media", "SV_AI_Image_Generator::generate_images", "wp_posts (attachment), wp_postmeta", "https://api.openai.com/v1/images/generations", "GPL-2.0+", "OpenAI Images API", "LOCAL_VERIFIED", "REFACTOR_GPL", "MEDIUM", "PHASE_04", "AT_022"),
    ("FEAT_023", "MEDIA", "Media Library Picker & Metadata", "Pick random/selected Media Library images, set ALT text and featured image", "softviet-ai-seo-writer/includes/class-sv-ai-media-picker.php", 1, "keywords.php, settings.php", "SV_AI_Media_Picker", "wp_posts, wp_postmeta", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_04", "AT_023"),

    # F. WOOCOMMERCE
    ("FEAT_024", "WOOCOMMERCE", "WooCommerce Product Selection & Tagging", "List WooCommerce products and insert product links/cards at start/middle/end of articles", "softviet-ai-seo-writer/includes/class-sv-ai-product-service.php", 1, "products.php, settings.php", "SV_AI_Product_Service", "wp_posts (product), wp_postmeta", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_05", "AT_024"),
    ("FEAT_025", "WOOCOMMERCE", "WooCommerce Product AI Content & Publisher", "Generate/update WooCommerce product description, short description, SEO, categories", "softviet-ai-seo-writer/includes/class-sv-ai-product-publisher.php", 1, "products.php", "SV_AI_Product_Publisher", "wp_posts (product), wp_postmeta, wp_terms", "AI Provider", "GPL-2.0+", "AI Provider", "LOCAL_VERIFIED", "REFACTOR_GPL", "MEDIUM", "PHASE_05", "AT_025"),

    # G. SEO INTEGRATION
    ("FEAT_026", "SEO", "Rank Math & Yoast SEO Meta Mapping", "Write focus keyword, SEO title, meta description, and estimate Rank Math score", "softviet-ai-seo-writer/includes/class-sv-ai-seo-meta.php", 1, "settings.php#seo", "SV_AI_SEO_Meta::save", "wp_postmeta (rank_math_*, _yoast_wpseo_*)", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_05", "AT_026"),
    ("FEAT_027", "SEO", "SEO Enhancer & Internal Linking", "Insert internal links from existing posts/products, external links, and HTML structure", "softviet-ai-seo-writer/includes/class-sv-ai-seo-enhancer.php", 1, "settings.php#seo", "SV_AI_SEO_Enhancer", "wp_posts, wp_postmeta", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_05", "AT_027"),
    ("FEAT_028", "SEO", "Post Publisher (Draft/Publish/Future)", "Insert/update WP post with categories, tags, featured image, and scheduled future date", "softviet-ai-seo-writer/includes/class-sv-ai-post-publisher.php", 1, "settings.php#publish", "SV_AI_Post_Publisher", "wp_posts, wp_terms, wp_postmeta", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "MEDIUM", "PHASE_05", "AT_028"),

    # H. AUTOMATION
    ("FEAT_029", "AUTOMATION", "WP-Cron Scheduler & Custom Intervals", "Register 5m/10m/15m/30m/2h/3h/6h/weekly schedules and process queued keywords", "softviet-ai-seo-writer/includes/class-sv-ai-cron.php", 16, "schedule.php", "SV_AI_Cron::process", "wp_options, wp_sv_ai_keywords", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_05", "AT_029"),
    ("FEAT_030", "AUTOMATION", "Loopback Background Worker & Diagnostics", "Dispatch async loopback admin-ajax with one-time transient token + hosting diagnostics", "softviet-ai-seo-writer/includes/class-sv-ai-cron.php", 498, "schedule.php", "SV_AI_Cron::background_generate", "wp_options (transients), wp_sv_ai_logs", "Self loopback", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_05", "AT_030"),
    ("FEAT_031", "AUTOMATION", "Execution & Error Logging", "Log keyword generation steps, masked API keys, and errors to custom DB table", "softviet-ai-seo-writer/includes/class-sv-ai-db.php", 169, "logs.php", "SV_AI_DB::log", "wp_sv_ai_logs", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_02", "AT_031"),

    # I. ADMINISTRATION & LICENSING
    ("FEAT_032", "ADMIN", "Admin Menu & Multilingual UI (VI/EN/ZH)", "Register WP admin menu/submenus and translate UI strings in VI/EN/ZH", "softviet-ai-seo-writer/includes/class-sv-ai-i18n.php", 1, "All admin views", "SV_AI_Admin_Menu, SV_AI_I18N", "wp_options", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_05", "AT_032"),
    ("FEAT_033", "ADMIN", "Tabbed Plugin Settings UI", "Manage AI, content, media, SEO, WooCommerce, and cron settings", "softviet-ai-seo-writer/admin/views/settings.php", 1, "settings.php", "SV_AI_Admin_Menu", "wp_options:sv_ai_writer_settings", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REFACTOR_GPL", "LOW", "PHASE_02", "AT_033"),
    ("FEAT_034", "LICENSE", "SoftViet Commercial License Client", "Activate/check/deactivate license against softviet.vn", "softviet-ai-seo-writer/admin/views/license-client.php", 1, "license-client.php", "SV_AI_Admin_Menu::ajax_mark_license_sent", "wp_options", "https://softviet.vn/wp-json/sv-ai-writer/v1/license/*", "PROPRIETARY_SERVICE", "SoftViet License Server", "REMOTE_DEPENDENT", "EXCLUDE_WITH_REASON", "HIGH", "PHASE_02", "AT_034"),
    ("FEAT_035", "LICENSE", "SoftViet License Server & REST API", "Local license server tables, REST endpoints, and admin management", "softviet-ai-seo-writer/includes/class-sv-ai-license-api.php", 1, "license-server.php, free-clients.php", "SV_AI_License_API, SV_AI_License_Service", "wp_sv_ai_licenses, wp_sv_ai_license_activations", "None (exposes REST)", "GPL-2.0+ (SoftViet server)", "None", "LOCAL_VERIFIED", "EXCLUDE_WITH_REASON", "MEDIUM", "PHASE_02", "AT_035"),
    ("FEAT_036", "ADMIN", "SoftViet Remote Plugin Updater", "Check softviet.vn manifest JSON and download remote ZIP updates", "softviet-ai-seo-writer/includes/class-sv-ai-updater.php", 1, "updates.php", "SV_AI_Updater", "wp_options (transients)", "https://softviet.vn/softviet-ai-seo-writer.json", "PROPRIETARY_SERVICE", "SoftViet Update Server", "REMOTE_DEPENDENT", "REPLACE_WITH_OFFICIAL_API", "HIGH", "PHASE_02", "AT_036"),

    # J. INSTALLATION & LIFECYCLE
    ("FEAT_037", "LIFECYCLE", "PHP Version Guard & Activation/Deactivation", "Check PHP version on boot, create DB tables on activation, clear cron on deactivation", "softviet-ai-seo-writer/includes/class-sv-ai-activator.php", 1, "Plugins screen", "SV_AI_Activator", "wp_sv_ai_*, wp_options", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REIMPLEMENT_INDEPENDENTLY", "LOW", "PHASE_02", "AT_037"),
    ("FEAT_038", "LIFECYCLE", "DB Schema Creation & Versioned Migration", "Create 4 core tables and run column/index migrations", "softviet-ai-seo-writer/includes/class-sv-ai-db.php", 1, "plugins_loaded", "SV_AI_DB::create_tables", "wp_sv_ai_keywords, logs, client_sites, daily_usage", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REIMPLEMENT_INDEPENDENTLY", "LOW", "PHASE_02", "AT_038"),
    ("FEAT_039", "LIFECYCLE", "Uninstall Cleanup", "Remove OAuth credentials & transients on uninstall while preserving settings/data", "softviet-ai-seo-writer/uninstall.php", 1, "Plugins delete", "uninstall.php", "wp_options", "None", "GPL-2.0+", "None", "LOCAL_VERIFIED", "REIMPLEMENT_INDEPENDENTLY", "LOW", "PHASE_02", "AT_039"),
]

feat_rows = []
for f in FEATURES:
    fid, mod, name, desc, src, line, ui, handler, db, api, lic, ext, disc_st, strat, risk, phase, atid = f
    feat_rows.append({
        "FEATURE_ID": fid,
        "MODULE": mod,
        "FEATURE_NAME": name,
        "DESCRIPTION": desc,
        "SOURCE_FILE": src,
        "SOURCE_LINE": line,
        "UI_ENTRY": ui,
        "BACKEND_HANDLER": handler,
        "DATABASE_DEPENDENCY": db,
        "API_DEPENDENCY": api,
        "LICENSE_DEPENDENCY": lic,
        "EXTERNAL_SERVICE": ext,
        "ORIGINAL_BEHAVIOR": desc,
        "HTD_EXPECTED_BEHAVIOR": f"HTD-managed independent implementation ({strat})",
        "REUSE_STRATEGY": strat,
        "SECURITY_RISK": risk,
        "IMPLEMENTATION_PHASE": phase,
        "ACCEPTANCE_TEST_ID": atid,
        "DISCOVERY_STATUS": disc_st,
        "IMPLEMENTATION_STATUS": "NOT_STARTED",
        "VERIFICATION_STATUS": "NOT_VERIFIED",
        "EVIDENCE": f"{src}:L{line}",
        "BLOCKER": "NONE" if disc_st == "LOCAL_VERIFIED" else "REMOTE_SERVER_DEPENDENCY_EXCLUDED",
    })

with open(WS / "discovery" / "FEATURE_PARITY_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(feat_rows[0].keys()))
    w.writeheader()
    w.writerows(feat_rows)

(WS / "discovery" / "FEATURE_REGISTRY.json").write_text(json.dumps(feat_rows, indent=2, ensure_ascii=False), encoding="utf-8")

# 3. UI_FUNCTION_INVENTORY.csv
screen_to_feat = {
    "chatgpt-connection": ("FEAT_007", "AT_007", "sv_ai_chatgpt"),
    "free-clients": ("FEAT_035", "AT_035", "N/A"),
    "keyword-audit": ("FEAT_012", "AT_012", "sv_ai_keyword_audit"),
    "keywords": ("FEAT_010", "AT_010", "sv_ai_generate_post"),
    "license-client": ("FEAT_034", "AT_034", "sv_ai_mark_license_sent"),
    "license-server": ("FEAT_035", "AT_035", "N/A"),
    "license": ("FEAT_034", "AT_034", "N/A"),
    "logs": ("FEAT_031", "AT_031", "N/A"),
    "products": ("FEAT_024", "AT_024", "N/A"),
    "rewrite-posts": ("FEAT_020", "AT_020", "N/A"),
    "schedule": ("FEAT_029", "AT_029", "sv_ai_run_loopback_diagnostic"),
    "settings": ("FEAT_033", "AT_033", "admin_post_sv_ai_save_settings"),
    "updates": ("FEAT_036", "AT_036", "N/A"),
    "upgrade": ("FEAT_034", "AT_034", "N/A"),
}
ui_rows = []
for u in UI_RAW:
    fid, atid, act = screen_to_feat.get(u["SCREEN"], ("FEAT_032", "AT_032", "N/A"))
    ui_rows.append({
        "UI_ID": u["UI_ID"],
        "SCREEN": u["SCREEN"],
        "CONTROL_TYPE": u["CONTROL_TYPE"],
        "IDENTIFIER": u["IDENTIFIER"],
        "SOURCE_FILE": u["SOURCE_FILE"],
        "SOURCE_LINE": u["SOURCE_LINE"],
        "EVENT_HANDLER": "admin.js / chatgpt.js / form submit",
        "AJAX_OR_REST_ACTION": act,
        "BACKEND_METHOD": "SV_AI_Admin_Menu",
        "INPUT_VALIDATION": "nonce + manage_options + sanitize_*",
        "DATABASE_MUTATION": "UPDATE/INSERT" if u["CONTROL_TYPE"] in ("FORM", "BUTTON") else "READ_ONLY",
        "OUTPUT": "Admin HTML / JSON response",
        "FEATURE_ID": fid,
        "ACCEPTANCE_TEST_ID": atid,
    })

with open(WS / "discovery" / "UI_FUNCTION_INVENTORY.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(ui_rows[0].keys()))
    w.writeheader()
    w.writerows(ui_rows)

# 4. WP_HOOK_REGISTRY.json & DATA_MUTATION_MATRIX.csv
hook_registry = {
    "options": DEEP_AUDIT["options"],
    "post_meta": DEEP_AUDIT["post_meta"],
    "transients": DEEP_AUDIT["transients"],
    "cron_hooks": ["sv_ai_writer_cron_process", "sv_ai_writer_license_check", "sv_ai_writer_generate_single", "sv_ai_writer_post_auto_internal_links", "sv_ai_writer_process_auto_internal_links_queue"],
    "capabilities": DEEP_AUDIT["capabilities"],
    "ajax_actions": DEEP_AUDIT["ajax_actions"],
    "rest_routes": DEEP_AUDIT["rest_routes"],
    "db_tables": ["sv_ai_keywords", "sv_ai_logs", "sv_ai_client_sites", "sv_ai_daily_usage", "sv_ai_licenses (server)", "sv_ai_license_activations (server)"],
}
(WS / "discovery" / "WP_HOOK_REGISTRY.json").write_text(json.dumps(hook_registry, indent=2, ensure_ascii=False), encoding="utf-8")

mut_rows = [
    {"TARGET": "wp_sv_ai_keywords", "TYPE": "CUSTOM_TABLE", "MUTATION": "CREATE/UPDATE/DELETE", "SOURCE": "class-sv-ai-db.php, class-sv-ai-keyword-service.php", "RISK": "LOW"},
    {"TARGET": "wp_sv_ai_logs", "TYPE": "CUSTOM_TABLE", "MUTATION": "CREATE", "SOURCE": "class-sv-ai-db.php", "RISK": "LOW"},
    {"TARGET": "wp_sv_ai_daily_usage", "TYPE": "CUSTOM_TABLE", "MUTATION": "CREATE/UPDATE", "SOURCE": "class-sv-ai-db.php", "RISK": "LOW"},
    {"TARGET": "wp_posts (post, product, attachment)", "TYPE": "WP_CORE_TABLE", "MUTATION": "CREATE/UPDATE/PUBLISH", "SOURCE": "class-sv-ai-post-publisher.php, class-sv-ai-post-rewrite-publisher.php, class-sv-ai-product-publisher.php, class-sv-ai-image-generator-runtime.php", "RISK": "HIGH"},
    {"TARGET": "wp_postmeta (Rank Math, Yoast, WC)", "TYPE": "WP_CORE_TABLE", "MUTATION": "CREATE/UPDATE", "SOURCE": "class-sv-ai-seo-meta.php, class-sv-ai-product-publisher.php", "RISK": "MEDIUM"},
    {"TARGET": "wp_options (sv_ai_*)", "TYPE": "WP_CORE_TABLE", "MUTATION": "CREATE/UPDATE/DELETE", "SOURCE": "class-sv-ai-credential-store.php, class-sv-ai-content-generator.php, uninstall.php", "RISK": "MEDIUM"},
    {"TARGET": "External AI APIs", "TYPE": "EXTERNAL_HTTP", "MUTATION": "EXTERNAL_TRANSMISSION", "SOURCE": "class-sv-ai-openai-client.php, class-sv-ai-chatgpt-client.php, class-sv-ai-image-generator-runtime.php", "RISK": "MEDIUM"},
    {"TARGET": "SoftViet APIs (softviet.vn)", "TYPE": "EXTERNAL_HTTP", "MUTATION": "EXTERNAL_TRANSMISSION", "SOURCE": "class-sv-ai-content-generator.php, class-sv-ai-updater.php, class-sv-ai-cron.php", "RISK": "HIGH_EXCLUDED_IN_HTD"},
]
with open(WS / "discovery" / "DATA_MUTATION_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(mut_rows[0].keys()))
    w.writeheader()
    w.writerows(mut_rows)

print(f"Generated: {len(lic_rows)} license rows, {len(feat_rows)} features, {len(ui_rows)} UI rows, {len(mut_rows)} mutation rows.")
