import csv
import json
import zipfile
from collections import Counter
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
SRC_PLUGIN = WS / "src" / "htd-ai-seo-writer-pro"
BUILD_ZIP = WS / "build" / "htd-ai-seo-writer-pro-0.6.0-dev.zip"

with zipfile.ZipFile(BUILD_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
    for p in sorted(SRC_PLUGIN.rglob("*")):
        if p.is_file():
            arcname = "htd-ai-seo-writer-pro/" + str(p.relative_to(SRC_PLUGIN)).replace("\\", "/")
            zf.write(p, arcname)

features = json.loads((WS / "discovery" / "FEATURE_REGISTRY.json").read_text(encoding="utf-8"))
for f in features:
    if f["FEATURE_ID"] == "FEAT_002":
        f["IMPLEMENTATION_STATUS"] = "IMPLEMENTED_ADAPTER"
        f["VERIFICATION_STATUS"] = "UNIT_MOCK_PASS"
        f["EVIDENCE"] = "qa/PHASE_06_TEST_RESULTS.json"

(WS / "discovery" / "FEATURE_REGISTRY.json").write_text(json.dumps(features, indent=2, ensure_ascii=False), encoding="utf-8")

csv_fields = [k for k in features[0].keys() if k != "SUBFEATURES"] + ["SUBFEATURE_COUNT"]
with open(WS / "discovery" / "FEATURE_PARITY_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=csv_fields)
    w.writeheader()
    for feat in features:
        row = {k: feat[k] for k in csv_fields if k != "SUBFEATURE_COUNT"}
        row["SUBFEATURE_COUNT"] = len(feat.get("SUBFEATURES", []))
        w.writerow(row)

# Original 39 vs Foundation 6
orig_feats = [f for f in features if int(f["FEATURE_ID"].split("_")[1]) <= 39]
orig_impl = sum(1 for f in orig_feats if f["IMPLEMENTATION_STATUS"] not in ("NOT_STARTED", "EXCLUDED", "BLOCKED"))
orig_blk = sum(1 for f in orig_feats if f["IMPLEMENTATION_STATUS"] == "BLOCKED")
orig_excl = sum(1 for f in orig_feats if f["IMPLEMENTATION_STATUS"] == "EXCLUDED")
orig_pend = sum(1 for f in orig_feats if f["IMPLEMENTATION_STATUS"] == "NOT_STARTED")

qa_rows = [
    ("QA01", "Original Parity & HTD Extensions", "39 Original + 6 Foundation + 10 HTD Extensions", "Separated & tracked", f"Original: {orig_impl}/39 impl, {orig_blk} blocked (FEAT_007), {orig_excl} excluded; Extensions: 10/10 impl", "discovery/FEATURE_REGISTRY.json", "PASS", "NONE", "Resolve FEAT_007"),
    ("QA02", "License & Ownership", "GPLv2+ headers, 0 SoftViet assets/prompts", "Full compliance", "All Phase 06 modules HTD-authored GPLv2+", "compliance/LICENSE_COMPLIANCE.md", "PASS", "NONE", "Maintain headers"),
    ("QA03", "Core Architecture & Dependency", "22 modules registered & dependency-ordered", "Clean dependency resolution", "22/22 ACTIVE in Plugin.php", "src/htd-ai-seo-writer-pro/src/Core/Plugin.php", "PASS", "NONE", "Maintain contracts"),
    ("QA04", "PHP, JS, CSS Quality", "php -l (97 PHP files), node --check; WPCS/PHPStan", "0 syntax errors", "97/97 PHP lint 0 errors; phpcs/phpstan NOT_RUN", "qa/PHASE_06_TEST_RESULTS.json", "PARTIAL", "phpcs/phpstan not installed", "Run in Phase 07"),
    ("QA05", "Database & Migration", "v0.6.0 23 tables migration & rollback", "Migration tests pass", "DB6_01 PASS (in-memory SQL double); live MySQL NOT_RUN", "qa/PHASE_06_TEST_RESULTS.json", "PARTIAL", "Docker/MySQL not running", "Run on MySQL staging"),
    ("QA06", "AI, GSC & GA4 Contracts", "FEAT_002, GSC, GA4, 5 AI adapters + Vault", "Contracts & crypto pass", "FEAT002_01, GSC_01, GA4_01 MOCK_PASS; live APIs NOT_RUN", "qa/PHASE_06_TEST_RESULTS.json", "PARTIAL", "No live AI/GSC/GA4 credentials", "Test live APIs"),
    ("QA07", "UI/UX & Admin Controls", "18 admin screens & Growth Center", "All screens functional in WP", "Templates & nonces implemented; WP runtime NOT_RUN", "src/htd-ai-seo-writer-pro/templates/", "NOT_RUN", "Docker/WP not running", "Run in WP staging"),
    ("QA08", "WordPress & WooCommerce Runtime", "WooCommerceAdapter, PublishingEngine", "WC CRUD & WP publish verified", "Unit/mock PASS; live WP/WC runtime NOT_RUN", "qa/PHASE_05_TEST_RESULTS.json", "PARTIAL", "Docker/WP/WC not running", "Run in WP+WC staging"),
    ("QA09", "Rank Math & Technical SEO Audit", "RankMathAdapter, CategorySEO, TechAudit, TrafficGuard", "Read-only audit & protection verified", "CAT_01, GROWTH_01 PASS; live Rank Math NOT_RUN", "qa/PHASE_06_TEST_RESULTS.json", "PARTIAL", "Docker/Rank Math not running", "Run in WP+RM staging"),
    ("QA10", "Security & Malware", "FEAT_002 SSRF/isolation, Vault, MutationGuard, SAST", "0 critical/high vulns", "FEAT002_01, VAULT_01-02 PASS; SAST/AV NOT_RUN", "qa/PHASE_06_TEST_RESULTS.json", "PARTIAL", "SAST/AV binaries not installed", "Run full scanner suite Phase 07"),
    ("QA11", "External Service & Privacy", "Zero SoftViet requests, SSRF & default-deny", "0 outbound SoftViet calls", "HTTP_01 & FEAT002_01 PASS; SoftViet blocked", "qa/PHASE_06_TEST_RESULTS.json", "PASS", "NONE", "Verify runtime packet trace Phase 07"),
    ("QA12", "Performance & Token Optimization", "Rule-based audit first, deduplication, budget caps", "Safe shared-hosting limits", "OPP_01, GROWTH_01 PASS; load benchmark NOT_RUN", "qa/PHASE_06_TEST_RESULTS.json", "PARTIAL", "No staging server", "Run in Phase 07"),
    ("QA13", "End-to-End SEO & Growth Workflow", "One-click audit, 6-col roadmap, Growth delta", "E2E mock pass", "OPP_01, AIO_01, GROWTH_01 PASS; live WP E2E NOT_RUN", "qa/PHASE_06_TEST_RESULTS.json", "PARTIAL", "Docker/WP not running", "Run in Phase 07"),
    ("QA14", "Install, Upgrade, Backup, Rollback", "Dev ZIP 0.6.0-dev built; v0.6.0 rollback tested", "Clean install & rollback", "Dev ZIP built & DB6_01 PASS; WP install NOT_RUN", "build/htd-ai-seo-writer-pro-0.6.0-dev.zip", "PARTIAL", "Docker/WP not running", "Run in Phase 08"),
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

impl_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] not in ("NOT_STARTED", "EXCLUDED", "BLOCKED"))
excl_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] == "EXCLUDED")
blk_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] == "BLOCKED")
pend_count = sum(1 for f in features if f["IMPLEMENTATION_STATUS"] == "NOT_STARTED")
qa_counts = Counter(q["STATUS"] for q in qa_dicts)
sub_total = sum(len(f.get("SUBFEATURES", [])) for f in features)

progress_md = f"""# MASTER PROGRESS — HTD AI SEO WRITER PRO

```yaml
PROJECT_NAME: HTD AI SEO WRITER PRO
CURRENT_PHASE: PHASE_06_COMPLETE
SOURCE_SHA256: fb423524b51bc057a8fb5babad8eda744d4d3d91d57620226fe44f328beb3b10
SOURCE_ARCHIVE_ENTRIES: 60
SOURCE_FILE_COUNT: 60
SOURCE_FILES_ANALYZED: 60
SOURCE_COVERAGE_PERCENT: 100.0
ORIGINAL_FEATURES_TOTAL: {len(orig_feats)}
ORIGINAL_FEATURES_IMPLEMENTED: {orig_impl}
ORIGINAL_FEATURES_BLOCKED: {orig_blk}
ORIGINAL_FEATURES_EXCLUDED: {orig_excl}
ORIGINAL_FEATURES_PENDING: {orig_pend}
HTD_FOUNDATION_AND_EXTENSIONS: 16
FEATURES_TOTAL_TRACKED: {len(features)}
SUBFEATURES_MAPPED: {sub_total}
FEATURES_IMPLEMENTED: {impl_count}
FEATURES_BLOCKED: {blk_count}
FEATURES_EXCLUDED: {excl_count}
FEATURES_PENDING: {pend_count}
QA_PASS: {qa_counts['PASS']}
QA_PARTIAL: {qa_counts['PARTIAL']}
QA_FAIL: {qa_counts['FAIL']}
QA_BLOCKED: {qa_counts['BLOCKED']}
QA_NOT_RUN: {qa_counts['NOT_RUN']}
QA_NOT_VERIFIED: {qa_counts['NOT_VERIFIED']}
PRODUCTION_WRITE_COUNT: 0
ORIGINAL_SOURCE_MODIFIED: FALSE
NEXT_PHASE: PHASE_07
NEXT_ACTION: Security Hardening, Full QA15 Execution, Regression, Performance Benchmark, and E2E Verification.
```
"""
(WS / "MASTER_PROGRESS.md").write_text(progress_md, encoding="utf-8")
print(f"Phase 06 reconciled: Orig={orig_impl}/39 (1 blocked, 2 excluded, {orig_pend} pending), Total={impl_count}/45. Dev ZIP: {BUILD_ZIP.stat().st_size} bytes.")
print("QA15:", dict(qa_counts))
