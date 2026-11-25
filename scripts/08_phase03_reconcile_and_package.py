import csv
import json
import zipfile
from collections import Counter
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
SRC_PLUGIN = WS / "src" / "htd-ai-seo-writer-pro"
BUILD_ZIP = WS / "build" / "htd-ai-seo-writer-pro-0.3.0-dev.zip"

# 1. Package development ZIP 0.3.0-dev
with zipfile.ZipFile(BUILD_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
    for p in sorted(SRC_PLUGIN.rglob("*")):
        if p.is_file():
            arcname = "htd-ai-seo-writer-pro/" + str(p.relative_to(SRC_PLUGIN)).replace("\\", "/")
            zf.write(p, arcname)

# 2. Update Feature Registry
features = json.loads((WS / "discovery" / "FEATURE_REGISTRY.json").read_text(encoding="utf-8"))

PHASE03_UPDATES = {
    "FEAT_001": ("IMPLEMENTED_ADAPTER", "MOCK_PASS"),
    "FEAT_003": ("IMPLEMENTED_ADAPTER", "MOCK_PASS"),
    "FEAT_004": ("IMPLEMENTED_ADAPTER", "MOCK_PASS"),
    "FEAT_005": ("IMPLEMENTED_ADAPTER", "MOCK_PASS"),
    "FEAT_006": ("IMPLEMENTED_ADAPTER", "MOCK_PASS"),
    "FEAT_008": ("IMPLEMENTED_CORE", "UNIT_OPENSSL_PASS"),
    "FEAT_009": ("IMPLEMENTED_CORE", "UNIT_MOCK_PASS"),
    "FEAT_015": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_016": ("REPLACED_LOCAL_PROMPT_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_018": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_041": ("IMPLEMENTED_CORE", "UNIT_OPENSSL_PASS"),
}
for f in features:
    fid = f["FEATURE_ID"]
    if fid in PHASE03_UPDATES:
        f["IMPLEMENTATION_STATUS"], f["VERIFICATION_STATUS"] = PHASE03_UPDATES[fid]

(WS / "discovery" / "FEATURE_REGISTRY.json").write_text(json.dumps(features, indent=2, ensure_ascii=False), encoding="utf-8")

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
    ("QA01", "Source coverage & Feature Parity", "45 main features + 17 subfeatures tracked", "100% mapped", "45 features (23 implemented, 20 pending, 2 excluded)", "discovery/FEATURE_REGISTRY.json", "PASS", "NONE", "Re-verify Phase 04+"),
    ("QA02", "License & Ownership", "GPLv2+ headers, 0 SoftViet prompts/assets", "Full compliance", "20 HTD-authored templates; 0 SoftViet dependencies", "compliance/LICENSE_COMPLIANCE.md", "PASS", "NONE", "Maintain headers"),
    ("QA03", "Core Architecture & Dependency", "Container, ModuleRegistry, AIOrchestrator wiring", "Clean dependency resolution", "CORE_01 & ORCH_01 PASS", "qa/PHASE_03_TEST_RESULTS.json", "PASS", "NONE", "Verify Phase 04"),
    ("QA04", "PHP, JS, CSS Quality", "php -l (60 PHP files), node --check; WPCS/PHPStan", "0 syntax errors", "60/60 PHP lint 0 errors; phpcs/phpstan NOT_RUN", "qa/PHASE_03_TEST_RESULTS.json", "PARTIAL", "phpcs/phpstan not installed", "Run in Phase 07"),
    ("QA05", "Database & Migration", "In-memory SQL migration & rollback", "Migration tests pass", "DB_01–03 PASS; live MySQL NOT_RUN", "qa/PHASE_02_TEST_RESULTS.json", "PARTIAL", "No live MySQL/WP runtime", "Run in Phase 05/08"),
    ("QA06", "AI Providers & Credential Vault", "Real OpenSSL AES-256-GCM + 5 adapter mocks", "Vault crypto + adapter contracts pass", "VAULT_01–02 (real OpenSSL) PASS; AI_01–02 MOCK_PASS; live AI APIs NOT_RUN", "qa/PHASE_03_TEST_RESULTS.json", "PARTIAL", "No live AI API keys tested", "Test live APIs with user keys"),
    ("QA07", "UI/UX & Admin Controls", "12 admin screens & POST handlers", "All screens functional in WP", "Templates & nonces implemented; WP runtime NOT_RUN", "src/htd-ai-seo-writer-pro/templates/", "NOT_RUN", "No WP runtime", "Run in WP staging"),
    ("QA08", "WordPress Lifecycle & Compatibility", "Activation/deactivation/uninstall", "Zero fatal errors", "Static verified; live WP NOT_RUN", "src/htd-ai-seo-writer-pro/src/Core/Lifecycle.php", "NOT_RUN", "No WP runtime", "Run in WP staging"),
    ("QA09", "Rank Math & SEO Compatibility", "Environment detection", "Safe detection", "Static verified; full SEO Phase 05", "src/htd-ai-seo-writer-pro/src/Support/Environment.php", "NOT_RUN", "Phase 05 scope", "Run in Phase 05"),
    ("QA10", "Security & Malware", "Prompt injection guard, OutputValidator, Vault, HTTP Guard", "0 critical/high vulns", "PROMPT_02, VAL_01, SEC_01, HTTP_01 PASS; SAST/AV NOT_RUN", "qa/PHASE_03_TEST_RESULTS.json", "PARTIAL", "SAST/AV binaries not installed", "Run full scanner suite Phase 07"),
    ("QA11", "External Service & Privacy", "Zero SoftViet requests, SSRF & default-deny", "0 outbound SoftViet calls", "HTTP_01 PASS; SoftViet blocked", "qa/PHASE_02_TEST_RESULTS.json", "PASS", "NONE", "Verify runtime packet trace Phase 07"),
    ("QA12", "Performance & Hosting Resources", "Token estimation, trimming, budget, cache", "Budget & cache enforced", "TOKEN_01 PASS; live hosting load test NOT_RUN", "qa/PHASE_03_TEST_RESULTS.json", "PARTIAL", "No staging server", "Run in Phase 07"),
    ("QA13", "AI Workflow E2E", "Orchestrator -> Prompt -> Provider -> Validator -> Token", "E2E mock pass", "ORCH_01 PASS; live WP E2E NOT_RUN", "qa/PHASE_03_TEST_RESULTS.json", "PARTIAL", "No WP runtime", "Run in Phase 07"),
    ("QA14", "Install, Upgrade, Backup, Rollback", "Dev ZIP 0.3.0-dev built; DB rollback tested", "Clean install & rollback", "Dev ZIP built & DB_03 PASS; WP install NOT_RUN", "build/htd-ai-seo-writer-pro-0.3.0-dev.zip", "PARTIAL", "No WP runtime", "Run in Phase 08"),
    ("QA15", "Independent Final Acceptance", "Independent reviewer sign-off", "Formal sign-off", "No independent review performed", "qa/QA_EVIDENCE.json", "NOT_VERIFIED", "Awaiting Phase 08 independent review", "Required before release"),
]

qa_dicts = [
    {"QA_ID": q[0], "SCOPE": q[1], "TEST_CASES": q[2], "EXPECTED_RESULT": q[3], "ACTUAL_RESULT": q[4], "EVIDENCE": q[5], "STATUS": q[6], "BLOCKER": q[7], "REGRESSION_REQUIREMENT": q[8]}
    for q in qa_rows
]
with open(WS / "qa" / "QA15_MASTER_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(qa_dicts[0].keys()))
    w.writeheader()
    w.writerows(qa_dicts)
(WS / "qa" / "QA_EVIDENCE.json").write_text(json.dumps(qa_dicts, indent=2, ensure_ascii=False), encoding="utf-8")

# 4. Update MASTER_PROGRESS.md
impl_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] not in ("NOT_STARTED", "EXCLUDED"))
excl_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] == "EXCLUDED")
pend_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] == "NOT_STARTED")
qa_counts = Counter(q["STATUS"] for q in qa_dicts)
sub_total = sum(len(f.get("SUBFEATURES", [])) for f in features)

progress_md = f"""# MASTER PROGRESS — HTD AI SEO WRITER PRO

```yaml
PROJECT_NAME: HTD AI SEO WRITER PRO
CURRENT_PHASE: PHASE_03_COMPLETE
SOURCE_SHA256: fb423524b51bc057a8fb5babad8eda744d4d3d91d57620226fe44f328beb3b10
SOURCE_ARCHIVE_ENTRIES: 60
SOURCE_FILE_COUNT: 60
SOURCE_FILES_ANALYZED: 60
SOURCE_COVERAGE_PERCENT: 100.0
FEATURES_DISCOVERED: {len(features)}
SUBFEATURES_MAPPED: {sub_total}
FEATURES_IMPLEMENTED: {impl_count}
FEATURES_EXCLUDED: {excl_count}
FEATURES_PENDING: {pend_count}
FEATURES_TESTED: {impl_count}
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
NEXT_PHASE: PHASE_04
NEXT_ACTION: Implement HTD Keyword Engine, Content Engine, Rewrite Engine, and Media Engine.
```
"""
(WS / "MASTER_PROGRESS.md").write_text(progress_md, encoding="utf-8")
print(f"Phase 03 reconciled: {impl_count} implemented, {pend_count} pending, {excl_count} excluded. Dev ZIP: {BUILD_ZIP.stat().st_size} bytes.")
print("QA15:", dict(qa_counts))
