import hashlib
import json
import re
import zipfile
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
SRC_PLUGIN = WS / "src" / "htd-ai-seo-writer-pro"
BUILD_ZIP = WS / "build" / "htd-ai-seo-writer-pro-0.9.0-dev.zip"

with zipfile.ZipFile(BUILD_ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
    for p in sorted(SRC_PLUGIN.rglob("*")):
        if p.is_file():
            arcname = "htd-ai-seo-writer-pro/" + str(p.relative_to(SRC_PLUGIN)).replace("\\", "/")
            zf.write(p, arcname)

zip_sha256 = hashlib.sha256(BUILD_ZIP.read_bytes()).hexdigest()
zip_size = BUILD_ZIP.stat().st_size

# Verify internal ZIP consistency
with zipfile.ZipFile(BUILD_ZIP, "r") as zf:
    assert zf.testzip() is None, "ZIP testzip() failed!"
    main_php = zf.read("htd-ai-seo-writer-pro/htd-ai-seo-writer-pro.php").decode("utf-8")
    readme_txt = zf.read("htd-ai-seo-writer-pro/readme.txt").decode("utf-8")
    assert "Version: 0.9.0-dev" in main_php
    assert "define('HTD_AI_SEO_VERSION', '0.9.0-dev');" in main_php
    assert "define('HTD_AI_SEO_DB_VERSION', '0.6.0');" in main_php
    assert "Stable tag: 0.9.0-dev" in readme_txt

manifest = {
    "plugin_name": "HTD AI SEO Writer Pro",
    "plugin_slug": "htd-ai-seo-writer-pro",
    "version": "0.9.0-dev",
    "db_schema_version": "0.6.0",
    "release_status": "NO_GO (0.9.0-dev; blockers BLK-001/002/004/005/006 remain)",
    "production_ready": False,
    "license": "GPL-2.0-or-later",
    "zip_file": "build/htd-ai-seo-writer-pro-0.9.0-dev.zip",
    "zip_sha256": zip_sha256,
    "zip_size_bytes": zip_size,
    "original_source_sha256": "fb423524b51bc057a8fb5babad8eda744d4d3d91d57620226fe44f328beb3b10",
    "automated_tests": {"total": 48, "pass": 48, "fail": 0},
    "php_lint": {"files": 98, "errors": 0},
    "blockers_open": ["BLK-001", "BLK-002", "BLK-004", "BLK-005", "BLK-006"],
    "generated_at_utc": "2026-11-25T10:03:00Z",
}
(WS / "build" / "RELEASE_MANIFEST.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
(WS / "docs" / "RELEASE_MANIFEST.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")

progress_md = f"""# MASTER PROGRESS — HTD AI SEO WRITER PRO

```yaml
PROJECT_NAME: HTD AI SEO WRITER PRO
CURRENT_PHASE: PROMPT_09_10_COMPLETE
FINAL_STATUS: NO_GO (0.9.0-dev; blockers BLK-001, BLK-002, BLK-004, BLK-005, BLK-006 open)
SOURCE_SHA256: fb423524b51bc057a8fb5babad8eda744d4d3d91d57620226fe44f328beb3b10
FINAL_DEV_ZIP: build/htd-ai-seo-writer-pro-0.9.0-dev.zip
FINAL_DEV_ZIP_SHA256: {zip_sha256}
FINAL_DEV_ZIP_SIZE: {zip_size}
HTD_PHP_FILES_LINTED: 98
AUTOMATED_TESTS_PASS: 48
AUTOMATED_TESTS_FAIL: 0
QA_PASS: 4
QA_PARTIAL: 10
QA_FAIL: 0
QA_NOT_VERIFIED: 1
PRODUCTION_WRITE_COUNT: 0
ORIGINAL_SOURCE_MODIFIED: FALSE
```
"""
(WS / "MASTER_PROGRESS.md").write_text(progress_md, encoding="utf-8")
print(f"Packaged {BUILD_ZIP.name} ({zip_size} bytes), SHA256={zip_sha256}")
