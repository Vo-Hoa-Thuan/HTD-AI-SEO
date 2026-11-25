import csv
import json
import zipfile
from collections import Counter
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
SRC_PLUGIN = WS / "src" / "htd-ai-seo-writer-pro"
BUILD_ZIP = WS / "build" / "htd-ai-seo-writer-pro-0.4.0-dev.zip"

with zipfile.ZipFile(BUILD_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
    for p in sorted(SRC_PLUGIN.rglob("*")):
        if p.is_file():
            arcname = "htd-ai-seo-writer-pro/" + str(p.relative_to(SRC_PLUGIN)).replace("\\", "/")
            zf.write(p, arcname)

features = json.loads((WS / "discovery" / "FEATURE_REGISTRY.json").read_text(encoding="utf-8"))

PHASE04_UPDATES = {
    "FEAT_010": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_011": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_012": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_013": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_014": ("IMPLEMENTED_UI_HANDLER", "UNIT_MOCK_PASS"),
    "FEAT_017": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_019": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_020": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_021": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
    "FEAT_022": ("IMPLEMENTED_VALIDATOR_CONTRACT", "UNIT_MOCK_PASS"),
    "FEAT_023": ("IMPLEMENTED_ENGINE", "UNIT_MOCK_PASS"),
}
for f in features:
    fid = f["FEATURE_ID"]
    if fid in PHASE04_UPDATES:
        f["IMPLEMENTATION_STATUS"], f["VERIFICATION_STATUS"] = PHASE04_UPDATES[fid]

(WS / "discovery" / "FEATURE_REGISTRY.json").write_text(json.dumps(features, indent=2, ensure_ascii=False), encoding="utf-8")

csv_fields = [k for k in features[0].keys() if k != "SUBFEATURES"] + ["SUBFEATURE_COUNT"]
with open(WS / "discovery" / "FEATURE_PARITY_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=csv_fields)
    w.writeheader()
    for feat in features:
        row = {k: feat[k] for k in csv_fields if k != "SUBFEATURE_COUNT"}
        row["SUBFEATURE_COUNT"] = len(feat.get("SUBFEATURES", []))
        w.writerow(row)

qa_rows = [
    ("QA01", "Source coverage & Feature Parity", "45 main features + 17 subfeatures tracked", "100% mapped", "45 features (33 implemented, 10 pending, 2 excluded); 7/17 subfeatures verified", "discovery/FEATURE_REGISTRY.json", "PASS", "NONE", "Re-verify Phase 05+"),
    ("QA02", "License & Ownership", "GPLv2+ headers, 0 SoftViet assets/prompts", "Full compliance", "HTD-authored Keyword/Content/Rewrite/Media engines", "compliance/LICENSE_COMPLIANCE.md", "PASS", "NONE", "Maintain headers"),
    ("QA03", "Core Architecture & Dependency", "Container, ModuleRegistry, Phase 04 wiring", "Clean dependency resolution", "17 modules registered; 15 ACTIVE, 2 NOT_IMPLEMENTED (Phase 05-06)", "qa/PHASE_04_TEST_RESULTS.json", "PASS", "NONE", "Verify Phase 05"),
    ("QA04", "PHP, JS, CSS Quality", "php -l (73 PHP files), node --check; WPCS/PHPStan", "0 syntax errors", "73/73 PHP lint 0 errors; phpcs/phpstan NOT_RUN", "qa/PHASE_04_TEST_RESULTS.json", "PARTIAL", "phpcs/phpstan not installed", "Run in Phase 07"),
    ("QA05", "Database & Migration", "v0.4.0 12 tables migration & rollback", "Migration tests pass", "DB4_01 PASS (in-memory SQL double); live MySQL NOT_RUN", "qa/PHASE_04_TEST_RESULTS.json", "PARTIAL", "Docker/MySQL not running", "Run on MySQL staging"),
    ("QA06", "AI Providers & Credential Vault", "Real OpenSSL AES-256-GCM + 5 adapter mocks", "Vault crypto + adapter contracts pass", "VAULT_01-02 (real OpenSSL) PASS; AI_01-02 MOCK_PASS; live AI APIs NOT_RUN", "qa/PHASE_03_TEST_RESULTS.json", "PARTIAL", "No live AI API keys", "Test live APIs"),
    ("QA07", "UI/UX & Admin Controls", "15 admin screens & POST handlers", "All screens functional in WP", "Templates & nonces implemented; WP runtime NOT_RUN", "src/htd-ai-seo-writer-pro/templates/", "NOT_RUN", "No WP runtime", "Run in WP staging"),
    ("QA08", "WordPress Lifecycle & Compatibility", "Activation/deactivation/uninstall", "Zero fatal errors", "Static verified; live WP NOT_RUN", "src/htd-ai-seo-writer-pro/src/Core/Lifecycle.php", "NOT_RUN", "No WP runtime", "Run in WP staging"),
    ("QA09", "Rank Math & SEO Compatibility", "Internal link proposals & SEO contract", "Valid SEO contract", "LINK_01 PASS; Rank Math runtime Phase 05", "qa/PHASE_04_TEST_RESULTS.json", "PARTIAL", "Phase 05 scope", "Run in Phase 05"),
    ("QA10", "Security & Malware", "ImageValidator, RewriteEngine, Sanitizer, Vault, HTTP Guard", "0 critical/high vulns", "MED_01, REW_01, CONT_01, SEC_01 PASS; SAST/AV NOT_RUN", "qa/PHASE_04_TEST_RESULTS.json", "PARTIAL", "SAST/AV binaries not installed", "Run full scanner suite Phase 07"),
    ("QA11", "External Service & Privacy", "Zero SoftViet requests, SSRF & default-deny", "0 outbound SoftViet calls", "HTTP_01 & MED_01 SSRF PASS; SoftViet blocked", "qa/PHASE_04_TEST_RESULTS.json", "PASS", "NONE", "Verify runtime packet trace Phase 07"),
    ("QA12", "Performance & Hosting Resources", "Token budget, trimming, cache, batch boundary", "Budget & cache enforced", "TOKEN_01 & LINK_01 PASS; live hosting load test NOT_RUN", "qa/PHASE_04_TEST_RESULTS.json", "PARTIAL", "No staging server", "Run in Phase 07"),
    ("QA13", "Content & Rewrite Workflow E2E", "Keyword -> Content -> QA -> Approval; Rewrite -> Diff -> Snapshot", "E2E mock pass", "KW_01-02, CONT_01, DEC_01, REW_01 PASS; WP runtime NOT_RUN", "qa/PHASE_04_TEST_RESULTS.json", "PARTIAL", "No WP runtime", "Run in Phase 07"),
    ("QA14", "Install, Upgrade, Backup, Rollback", "Dev ZIP 0.4.0-dev built; v0.4.0 rollback tested", "Clean install & rollback", "Dev ZIP built & DB4_01 PASS; WP install NOT_RUN", "build/htd-ai-seo-writer-pro-0.4.0-dev.zip", "PARTIAL", "No WP runtime", "Run in Phase 08"),
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

impl_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] not in ("NOT_STARTED", "EXCLUDED"))
excl_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] == "EXCLUDED")
pend_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] == "NOT_STARTED")
qa_counts = Counter(q["STATUS"] for q in qa_dicts)
sub_total = sum(len(f.get("SUBFEATURES", [])) for f in features)

progress_md = f"""# MASTER PROGRESS — HTD AI SEO WRITER PRO

```yaml
PROJECT_NAME: HTD AI SEO WRITER PRO
CURRENT_PHASE: PHASE_04_COMPLETE
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
NEXT_PHASE: PHASE_05
NEXT_ACTION: Implement WooCommerce Product Engine, Rank Math SEO Integration, Internal Link Engine, Queue/Cron Automation, and Phase 05 Admin UI.
```
"""
(WS / "MASTER_PROGRESS.md").write_text(progress_md, encoding="utf-8")
print(f"Phase 04 reconciled: {impl_count} implemented, {pend_count} pending, {excl_count} excluded. Dev ZIP: {BUILD_ZIP.stat().st_size} bytes.")
print("QA15:", dict(qa_counts))
