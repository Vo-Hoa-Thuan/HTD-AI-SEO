import csv
import json
import zipfile
from collections import Counter
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
SRC_PLUGIN = WS / "src" / "htd-ai-seo-writer-pro"
BUILD_ZIP = WS / "build" / "htd-ai-seo-writer-pro-0.2.0-dev.zip"

# 1. Package development ZIP
with zipfile.ZipFile(BUILD_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
    for p in sorted(SRC_PLUGIN.rglob("*")):
        if p.is_file():
            arcname = "htd-ai-seo-writer-pro/" + str(p.relative_to(SRC_PLUGIN)).replace("\\", "/")
            zf.write(p, arcname)

# 2. Reconcile Feature Registry (39 -> 45 features + 15 subfeatures)
features = json.loads((WS / "discovery" / "FEATURE_REGISTRY.json").read_text(encoding="utf-8"))

# Update Phase 02 implemented features in existing 39
PHASE02_IMPLEMENTED = {
    "FEAT_031": ("IMPLEMENTED_CORE", "UNIT_MOCK_PASS"),
    "FEAT_033": ("IMPLEMENTED_CORE", "UNIT_MOCK_PASS"),
    "FEAT_036": ("REPLACED_STUB_SAFE_DEFAULT", "UNIT_MOCK_PASS"),
    "FEAT_037": ("IMPLEMENTED_CORE", "UNIT_MOCK_PASS"),
    "FEAT_038": ("IMPLEMENTED_CORE", "UNIT_MOCK_PASS"),
    "FEAT_039": ("IMPLEMENTED_CORE", "UNIT_MOCK_PASS"),
}
for f in features:
    fid = f["FEATURE_ID"]
    f.setdefault("SUBFEATURES", [])
    if fid in PHASE02_IMPLEMENTED:
        f["IMPLEMENTATION_STATUS"], f["VERIFICATION_STATUS"] = PHASE02_IMPLEMENTED[fid]
    elif f["REUSE_STRATEGY"] == "EXCLUDE_WITH_REASON":
        f["IMPLEMENTATION_STATUS"] = "EXCLUDED"
        f["VERIFICATION_STATUS"] = "NOT_APPLICABLE"

# Add subfeatures for multi-behavior features
SUBFEATURE_MAP = {
    "FEAT_010": ["FEAT_010.1 Add/Edit/Delete Keyword", "FEAT_010.2 Pause/Resume/Bulk Actions", "FEAT_010.3 Filter/Pagination/Search"],
    "FEAT_011": ["FEAT_011.1 Analyze Seed Keywords", "FEAT_011.2 Accept Clusters into Queue"],
    "FEAT_015": ["FEAT_015.1 Outline Generation", "FEAT_015.2 Long-Form Article Drafting", "FEAT_015.3 FAQ/Meta/Slug/Excerpt/Tags"],
    "FEAT_024": ["FEAT_024.1 Product Listing & Selection", "FEAT_024.2 Product Card Insertion (Start/Middle/End)"],
    "FEAT_026": ["FEAT_026.1 Rank Math Meta & Score", "FEAT_026.2 Yoast SEO Meta"],
    "FEAT_029": ["FEAT_029.1 Custom Cron Intervals", "FEAT_029.2 Batch Queue Processing", "FEAT_029.3 Single Job Async Trigger"],
    "FEAT_033": ["FEAT_033.1 Typed Settings Schema & Safe Defaults", "FEAT_033.2 Admin Settings UI & Save Handler"],
}
for f in features:
    if f["FEATURE_ID"] in SUBFEATURE_MAP:
        f["SUBFEATURES"] = SUBFEATURE_MAP[f["FEATURE_ID"]]

# Add 6 new Phase 02 Foundation features (FEAT_040 .. FEAT_045)
NEW_PHASE02 = [
    ("FEAT_040", "CORE", "HTD Core Container, Module Registry & Health Check", "PSR-4 autoload, DI container, dependency graph, real-time health check", "IMPLEMENTED_CORE", "UNIT_MOCK_PASS", "AT_040"),
    ("FEAT_041", "CREDENTIALS", "HTD Credential Vault (AES-256-GCM)", "Authenticated encryption, random IV, key rotation, fail-closed without OpenSSL, masked listing", "IMPLEMENTED_CORE", "UNIT_MOCK_PASS", "AT_041"),
    ("FEAT_042", "SECURITY", "HTD External Request Guard", "Default-deny HTTP client, HTTPS-only, SSRF protection, provider allowlist, SoftViet blocked", "IMPLEMENTED_CORE", "UNIT_MOCK_PASS", "AT_042"),
    ("FEAT_043", "APPROVAL", "HTD Approval Engine & Mutation Guard", "Payload-bound approval state machine, double-execution lock, READ_ONLY & EMERGENCY_STOP enforcement", "IMPLEMENTED_CORE", "UNIT_MOCK_PASS", "AT_043"),
    ("FEAT_044", "QUEUE", "HTD Queue Foundation", "Idempotent enqueue, atomic lease lock, heartbeat, exponential backoff, dead-letter recovery", "IMPLEMENTED_CORE", "UNIT_MOCK_PASS", "AT_044"),
    ("FEAT_045", "SECURITY", "HTD Security Foundation & Capabilities", "7 granular htd_ai_seo_* capabilities, nonces, AI HTML sanitizer", "IMPLEMENTED_CORE", "UNIT_MOCK_PASS", "AT_045"),
]
for fid, mod, name, desc, imp_st, ver_st, atid in NEW_PHASE02:
    features.append({
        "FEATURE_ID": fid,
        "MODULE": mod,
        "FEATURE_NAME": name,
        "DESCRIPTION": desc,
        "SOURCE_FILE": "src/htd-ai-seo-writer-pro/",
        "SOURCE_LINE": 1,
        "UI_ENTRY": "AdminMenu",
        "BACKEND_HANDLER": name,
        "DATABASE_DEPENDENCY": "htd_ai_seo_*",
        "API_DEPENDENCY": "None",
        "LICENSE_DEPENDENCY": "GPL-2.0-or-later (HTD)",
        "EXTERNAL_SERVICE": "None",
        "ORIGINAL_BEHAVIOR": "N/A (HTD Foundation)",
        "HTD_EXPECTED_BEHAVIOR": desc,
        "REUSE_STRATEGY": "REIMPLEMENT_INDEPENDENTLY",
        "SECURITY_RISK": "LOW",
        "IMPLEMENTATION_PHASE": "PHASE_02",
        "ACCEPTANCE_TEST_ID": atid,
        "DISCOVERY_STATUS": "LOCAL_VERIFIED",
        "IMPLEMENTATION_STATUS": imp_st,
        "VERIFICATION_STATUS": ver_st,
        "EVIDENCE": "qa/PHASE_02_TEST_RESULTS.json",
        "BLOCKER": "NONE",
        "SUBFEATURES": [],
    })

(WS / "discovery" / "FEATURE_REGISTRY.json").write_text(json.dumps(features, indent=2, ensure_ascii=False), encoding="utf-8")

# Write updated CSV (without nested list column breaking CSV)
csv_fields = [k for k in features[0].keys() if k != "SUBFEATURES"] + ["SUBFEATURE_COUNT"]
with open(WS / "discovery" / "FEATURE_PARITY_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=csv_fields)
    w.writeheader()
    for feat in features:
        row = {k: feat[k] for k in csv_fields if k != "SUBFEATURE_COUNT"}
        row["SUBFEATURE_COUNT"] = len(feat.get("SUBFEATURES", []))
        w.writerow(row)

# 3. Update QA15_MASTER_MATRIX.csv & QA_EVIDENCE.json
qa_rows = [
    ("QA01", "Source coverage & Feature Parity", "Reconcile 45 features + 17 subfeatures", "100% mapped", "45 features + 17 subfeatures mapped", "discovery/FEATURE_REGISTRY.json", "PASS", "NONE", "Re-verify on Phase 03+"),
    ("QA02", "License & Ownership", "GPLv2+ headers, LICENSE, THIRD_PARTY_NOTICES, 0 SoftViet assets", "Full compliance", "LICENSE & THIRD_PARTY_NOTICES.md present; 0 SoftViet assets", "compliance/LICENSE_COMPLIANCE.md", "PASS", "NONE", "Maintain headers on new files"),
    ("QA03", "Core Architecture & Dependency", "PSR-4, DI Container, ModuleRegistry cycle/order check", "Clean dependency resolution", "CORE_01_ModuleRegistryAndHealthCheck PASS", "qa/PHASE_02_TEST_RESULTS.json", "PASS", "NONE", "Verify Phase 03 modules"),
    ("QA04", "PHP, JS, CSS Quality", "php -l (31 HTD PHP files), node --check admin.js; WPCS/PHPStan", "0 syntax/lint errors", "31/31 PHP lint 0 errors; admin.js 0 errors; phpcs/phpstan NOT_RUN", "qa/PHASE_02_TEST_RESULTS.json", "PARTIAL", "phpcs/phpstan not installed", "Run WPCS/PHPStan in Phase 07"),
    ("QA05", "Database & Migration", "Fresh install, custom prefix, idempotency, data preservation, rollback", "All migration tests pass", "DB_01, DB_02, DB_03 PASS (in-memory SQL double); live MySQL NOT_RUN", "qa/PHASE_02_TEST_RESULTS.json", "PARTIAL", "No live MySQL/WP runtime", "Run on MySQL staging in Phase 05/08"),
    ("QA06", "Credential Vault & HTTP Contract", "Fail-closed without OpenSSL; HTTP Guard default-deny", "Safe fail-closed & allowlist", "VAULT_01 & HTTP_01 PASS; OpenSSL AES-256-GCM runtime & live AI NOT_RUN", "qa/PHASE_02_TEST_RESULTS.json", "PARTIAL", "php_openssl disabled in local CLI; AI live in Phase 03", "Test AES-256-GCM on PHP with OpenSSL"),
    ("QA07", "UI/UX & Admin Controls", "7 Phase 02 admin screens & POST handlers", "All screens functional in WP", "Templates & nonces implemented; WP runtime NOT_RUN", "src/htd-ai-seo-writer-pro/templates/", "NOT_RUN", "No WP runtime in Phase 02", "Run in WP staging"),
    ("QA08", "WordPress Lifecycle & Compatibility", "Activation/deactivation/uninstall without WC/Rank Math", "Zero fatal errors", "Static & unit verified; live WP activation NOT_RUN", "src/htd-ai-seo-writer-pro/src/Core/Lifecycle.php", "NOT_RUN", "No WP runtime in Phase 02", "Run in WP staging"),
    ("QA09", "Rank Math & SEO Compatibility", "Environment::isRankMathActive() detection", "Safe detection without fatal", "Static verified; full SEO integration Phase 05", "src/htd-ai-seo-writer-pro/src/Support/Environment.php", "NOT_RUN", "Phase 05 scope", "Run in Phase 05"),
    ("QA10", "Security & Malware", "MutationGuard, ApprovalEngine, Sanitizer, Logger redaction, static scan", "0 critical/high vulns", "APPR_01, LOG_01, SEC_01, HTTP_01 PASS; YARA/ClamAV/Semgrep/Gitleaks NOT_RUN", "security/SECURITY_REMEDIATION_STATUS.md", "PARTIAL", "SAST/AV binaries not installed", "Run full scanner suite in Phase 07"),
    ("QA11", "External Service & Privacy", "Zero SoftViet requests, SSRF & telemetry blocked", "0 outbound SoftViet calls", "HTTP_01 PASS; SoftViet domains explicitly blocked", "qa/PHASE_02_TEST_RESULTS.json", "PASS", "NONE", "Verify runtime packet trace in Phase 07"),
    ("QA12", "Performance & Hosting Resources", "Core memory/timeout & LiteSpeed detection", "Within hosting limits", "Environment::isLiteSpeed() implemented; load test NOT_RUN", "qa/ACCEPTANCE_TEST_PLAN.md", "NOT_RUN", "No staging server", "Run in Phase 07"),
    ("QA13", "Core Workflow E2E", "Queue -> Approval -> MutationGuard -> Logging", "Full core workflow pass", "Unit/mock E2E passed; WP runtime E2E NOT_RUN", "qa/PHASE_02_TEST_RESULTS.json", "PARTIAL", "No WP runtime", "Run in Phase 07"),
    ("QA14", "Install, Upgrade, Backup, Rollback", "Dev ZIP built; DB rollback tested", "Clean install & rollback", "Dev ZIP built & DB_03 rollback PASS; WP install NOT_RUN", "build/htd-ai-seo-writer-pro-0.2.0-dev.zip", "PARTIAL", "No WP runtime", "Run in Phase 08"),
    ("QA15", "Independent Final Acceptance", "Independent reviewer sign-off", "Formal sign-off", "No independent review performed in Phase 02", "qa/QA_EVIDENCE.json", "NOT_VERIFIED", "Awaiting Phase 08 independent review", "Required before release"),
]

qa_dicts = []
for qid, scope, tc, exp, act, ev, st, blk, reg in qa_rows:
    qa_dicts.append({
        "QA_ID": qid, "SCOPE": scope, "TEST_CASES": tc, "EXPECTED_RESULT": exp,
        "ACTUAL_RESULT": act, "EVIDENCE": ev, "STATUS": st, "BLOCKER": blk, "REGRESSION_REQUIREMENT": reg
    })
with open(WS / "qa" / "QA15_MASTER_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(qa_dicts[0].keys()))
    w.writeheader()
    w.writerows(qa_dicts)
(WS / "qa" / "QA_EVIDENCE.json").write_text(json.dumps(qa_dicts, indent=2, ensure_ascii=False), encoding="utf-8")

# 4. Update MASTER_PROGRESS.md
imp_counts = Counter(f["IMPLEMENTATION_STATUS"] for f in features)
qa_counts = Counter(q["STATUS"] for q in qa_dicts)
sub_total = sum(len(f.get("SUBFEATURES", [])) for f in features)

progress_md = f"""# MASTER PROGRESS — HTD AI SEO WRITER PRO

```yaml
PROJECT_NAME: HTD AI SEO WRITER PRO
CURRENT_PHASE: PHASE_02_COMPLETE
SOURCE_SHA256: fb423524b51bc057a8fb5babad8eda744d4d3d91d57620226fe44f328beb3b10
SOURCE_ARCHIVE_ENTRIES: 60
SOURCE_FILE_COUNT: 60
SOURCE_FILES_ANALYZED: 60
SOURCE_COVERAGE_PERCENT: 100.0
FEATURES_DISCOVERED: {len(features)}
SUBFEATURES_MAPPED: {sub_total}
FEATURES_IMPLEMENTED: {imp_counts['IMPLEMENTED_CORE'] + imp_counts['REPLACED_STUB_SAFE_DEFAULT']}
FEATURES_EXCLUDED: {imp_counts['EXCLUDED']}
FEATURES_PENDING: {imp_counts['NOT_STARTED']}
FEATURES_TESTED: {imp_counts['IMPLEMENTED_CORE'] + imp_counts['REPLACED_STUB_SAFE_DEFAULT']}
FEATURES_ACCEPTED: 0
SECURITY_CRITICAL: 0
SECURITY_HIGH: 0
SECURITY_MEDIUM: 0
SECURITY_LOW: 0
QA_PASS: {qa_counts['PASS']}
QA_PARTIAL: {qa_counts['PARTIAL']}
QA_FAIL: {qa_counts['FAIL']}
QA_BLOCKED: {qa_counts['BLOCKED']}
QA_NOT_RUN: {qa_counts['NOT_RUN']}
QA_NOT_VERIFIED: {qa_counts['NOT_VERIFIED']}
PRODUCTION_WRITE_COUNT: 0
ORIGINAL_SOURCE_MODIFIED: FALSE
NEXT_PHASE: PHASE_03
NEXT_ACTION: Implement HTD AI Providers (OpenAI, Gemini, Claude, DeepSeek, Grok), HTD Local Prompt Engine, and Token Optimization.
```
"""
(WS / "MASTER_PROGRESS.md").write_text(progress_md, encoding="utf-8")
print(f"Reconciled {len(features)} features ({sub_total} subfeatures). Dev ZIP size: {BUILD_ZIP.stat().st_size} bytes.")
print("QA15 counts:", dict(qa_counts))
