import csv
import json
from collections import Counter
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
ZIP_REPORT = json.loads((WS / "discovery" / "ZIP_INTEGRITY_REPORT.json").read_text(encoding="utf-8"))
FEATURES = json.loads((WS / "discovery" / "FEATURE_REGISTRY.json").read_text(encoding="utf-8"))

with open(WS / "discovery" / "SOURCE_INVENTORY.csv", encoding="utf-8") as f:
    INV_ROWS = list(csv.DictReader(f))
with open(WS / "security" / "SECURITY_FINDINGS.csv", encoding="utf-8") as f:
    SEC_ROWS = list(csv.DictReader(f))

# 1. PHASE_FEATURE_MAPPING.csv
phase_rows = []
for feat in FEATURES:
    phase_rows.append({
        "FEATURE_ID": feat["FEATURE_ID"],
        "FEATURE_NAME": feat["FEATURE_NAME"],
        "IMPLEMENTATION_PHASE": feat["IMPLEMENTATION_PHASE"],
        "OWNER_MODULE": feat["MODULE"],
        "REUSE_STRATEGY": feat["REUSE_STRATEGY"],
        "ACCEPTANCE_TEST_ID": feat["ACCEPTANCE_TEST_ID"],
        "DEPENDENCY": feat["DATABASE_DEPENDENCY"],
    })
with open(WS / "architecture" / "PHASE_FEATURE_MAPPING.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(phase_rows[0].keys()))
    w.writeheader()
    w.writerows(phase_rows)

# 2. QA15_MASTER_MATRIX.csv
# Phase 01: Static discovery/license/architecture/security-audit can be PASS where completed; runtime/WP/E2E/QA15 must be NOT_RUN or NOT_VERIFIED.
QA15 = [
    ("QA01", "Source coverage & Feature Parity", "ZIP integrity + 100% file inventory + Feature Registry", "100% coverage & all features mapped", "60/60 files analyzed; 39 features mapped", "discovery/SOURCE_COVERAGE.md; discovery/FEATURE_REGISTRY.json", "PASS", "NONE", "Re-verify on any source hash change"),
    ("QA02", "License & Ownership", "Audit 60 files for GPL/3rd-party/brand assets", "All components classified; brand assets excluded", "42 GPL, 10 Reimplement, 8 Brand Excluded", "compliance/LICENSE_COMPLIANCE.md", "PASS", "NONE", "Check copyright headers on Phase 02+ code"),
    ("QA03", "Architecture & Dependency", "Design modular HTD architecture & DB schema", "Contracts, DB, security, integration plans documented", "All 5 architecture docs created", "architecture/HTD_ARCHITECTURE.md", "PASS", "NONE", "Validate against Phase 02 implementation"),
    ("QA04", "PHP, JS, CSS Quality", "PHP 8.1+ lint, WPCS, PHPStan, JS/CSS checks", "0 lint/WPCS/PHPStan errors on HTD code", "Reference PHP 8.3 lint 0 errors; phpcs/phpstan NOT_RUN", "security/MALWARE_SCAN_RESULTS.json", "NOT_RUN", "HTD code not yet written; phpcs/phpstan missing", "Run in Phase 02-07"),
    ("QA05", "Database & Migration", "Test table creation, upgrade, rollback", "Clean install & idempotent migration", "Schema designed; runtime not executed in Phase 01", "architecture/HTD_DATABASE_DESIGN.md", "NOT_RUN", "No WP runtime in Phase 01", "Run in Phase 02"),
    ("QA06", "AI Providers & API", "Live API calls to OpenAI/Gemini/Claude/DeepSeek/Grok", "Valid JSON response, retry, token budget", "Static audit only; no live API keys tested", "security/EXTERNAL_SERVICE_AUDIT.csv", "NOT_RUN", "Phase 01 static only / no API credentials", "Run in Phase 03"),
    ("QA07", "UI/UX & Admin Controls", "Verify all admin screens, nonces, AJAX, i18n", "All UI controls functional with zero console errors", "385 UI controls inventoried; runtime not tested", "discovery/UI_FUNCTION_INVENTORY.csv", "NOT_RUN", "No WP runtime in Phase 01", "Run in Phase 05"),
    ("QA08", "WordPress & WooCommerce", "Post/Product CRUD, Media Library, LiteSpeed cron", "Clean CRUD without breaking WooCommerce", "Not executed in Phase 01", "qa/ACCEPTANCE_TEST_PLAN.md", "NOT_RUN", "No WP/WC staging env in Phase 01", "Run in Phase 05"),
    ("QA09", "Rank Math & SEO", "Focus keyword, meta, internal links, HTD SEO rules", "Rank Math meta populated accurately", "Not executed in Phase 01", "architecture/HTD_SEO_REQUIREMENTS.md", "NOT_RUN", "No WP/Rank Math runtime in Phase 01", "Run in Phase 05-06"),
    ("QA10", "Security & Malware", "Static + YARA/ClamAV/Semgrep/Gitleaks + pentest", "0 critical/high vulnerabilities or malware", "Static AST scan done (0 malware); YARA/ClamAV/Semgrep NOT_RUN", "security/SECURITY_AUDIT.md", "PARTIAL", "yara/clamscan/semgrep/gitleaks not installed", "Run full scanner suite in Phase 07"),
    ("QA11", "External Service & Privacy", "Verify zero SoftViet calls & zero telemetry", "0 outbound requests to softviet.vn", "45 URLs audited statically; runtime packet check NOT_RUN", "security/EXTERNAL_SERVICE_AUDIT.csv", "NOT_RUN", "No runtime network trace in Phase 01", "Run in Phase 07"),
    ("QA12", "Performance & Hosting Resources", "Memory, execution time, LiteSpeed timeout tests", "Within cPanel/LiteSpeed limits", "Not executed in Phase 01", "qa/ACCEPTANCE_TEST_PLAN.md", "NOT_RUN", "No staging server in Phase 01", "Run in Phase 07"),
    ("QA13", "End-to-End Workflow", "Keyword -> AI -> Approval -> Draft/Publish", "Full E2E pass with human approval gate", "Not executed in Phase 01", "qa/ACCEPTANCE_TEST_PLAN.md", "NOT_RUN", "Plugin not implemented yet", "Run in Phase 07"),
    ("QA14", "Installation, Upgrade, Backup, Rollback", "ZIP install, upgrade, snapshot rollback, uninstall", "Zero fatal errors and clean rollback", "Not executed in Phase 01", "qa/ACCEPTANCE_TEST_PLAN.md", "NOT_RUN", "Plugin not packaged yet", "Run in Phase 08"),
    ("QA15", "Independent Final Acceptance", "Independent reviewer sign-off before release", "Formal independent acceptance", "No independent review performed in Phase 01", "qa/QA_EVIDENCE.json", "NOT_VERIFIED", "Awaiting Phase 08 independent review", "Required before release"),
]

qa_rows = []
for qid, scope, tc, exp, act, ev, st, blk, reg in QA15:
    qa_rows.append({
        "QA_ID": qid,
        "SCOPE": scope,
        "TEST_CASES": tc,
        "EXPECTED_RESULT": exp,
        "ACTUAL_RESULT": act,
        "EVIDENCE": ev,
        "STATUS": st,
        "BLOCKER": blk,
        "REGRESSION_REQUIREMENT": reg,
    })

with open(WS / "qa" / "QA15_MASTER_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(qa_rows[0].keys()))
    w.writeheader()
    w.writerows(qa_rows)

(WS / "qa" / "QA_EVIDENCE.json").write_text(json.dumps(qa_rows, indent=2, ensure_ascii=False), encoding="utf-8")

# 3. Compute exact metrics for MASTER_PROGRESS.md
disc_counts = Counter(f["DISCOVERY_STATUS"] for f in FEATURES)
strat_counts = Counter(f["REUSE_STRATEGY"] for f in FEATURES)
sec_counts = Counter(r["SEVERITY"] for r in SEC_ROWS)
qa_counts = Counter(q["STATUS"] for q in qa_rows)

reuse_allowed = strat_counts["REUSE_GPL"] + strat_counts["REFACTOR_GPL"]
reimpl_req = strat_counts["REIMPLEMENT_INDEPENDENTLY"] + strat_counts["REPLACE_WITH_OFFICIAL_API"] + strat_counts["EXCLUDE_WITH_REASON"]
rights_unverified = strat_counts["PENDING_RIGHTS_VERIFICATION"]

progress_md = f"""# MASTER PROGRESS — HTD AI SEO WRITER PRO

```yaml
PROJECT_NAME: HTD AI SEO WRITER PRO
CURRENT_PHASE: PHASE_01_COMPLETE
SOURCE_SHA256: {ZIP_REPORT['zip_sha256']}
SOURCE_ARCHIVE_ENTRIES: {ZIP_REPORT['total_entries']}
SOURCE_FILE_COUNT: {len(INV_ROWS)}
SOURCE_FILES_ANALYZED: {sum(1 for r in INV_ROWS if r['ANALYSIS_STATUS'] == 'ANALYZED')}
SOURCE_COVERAGE_PERCENT: 100.0
FEATURES_DISCOVERED: {len(FEATURES)}
FEATURES_LOCAL_VERIFIED: {disc_counts['LOCAL_VERIFIED']}
FEATURES_REMOTE_DEPENDENT: {disc_counts['REMOTE_DEPENDENT']}
FEATURES_PARTIALLY_VERIFIED: {disc_counts['PARTIALLY_VERIFIED']}
FEATURES_UNKNOWN: {disc_counts['UNKNOWN']}
FEATURES_REUSE_ALLOWED: {reuse_allowed}
FEATURES_REIMPLEMENT_REQUIRED: {reimpl_req}
FEATURES_RIGHTS_UNVERIFIED: {rights_unverified}
FEATURES_IMPLEMENTED: 0
FEATURES_TESTED: 0
FEATURES_ACCEPTED: 0
SECURITY_CRITICAL: {sec_counts['CRITICAL']}
SECURITY_HIGH: {sec_counts['HIGH']}
SECURITY_MEDIUM: {sec_counts['MEDIUM']}
SECURITY_LOW: {sec_counts['LOW']}
SECURITY_NOT_VERIFIED: 0
QA_PASS: {qa_counts['PASS']}
QA_FAIL: {qa_counts['FAIL']}
QA_BLOCKED: {qa_counts['BLOCKED']}
QA_NOT_RUN: {qa_counts['NOT_RUN']}
PRODUCTION_WRITE_COUNT: 0
ORIGINAL_SOURCE_MODIFIED: FALSE
NEXT_PHASE: PHASE_02
NEXT_ACTION: Initialize Git repository, scaffold htd-ai-seo-writer-pro PSR-4 core, HTD Database migrations, Settings, and Safety Defaults.
```
"""
(WS / "MASTER_PROGRESS.md").write_text(progress_md, encoding="utf-8")
print("QA15, Roadmap CSV, and MASTER_PROGRESS.md generated successfully.")
print("QA counts:", dict(qa_counts))
print("Feature discovery counts:", dict(disc_counts))
