import csv
import hashlib
import json
import os
import re
import subprocess
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
EXTRACTED = WS / "reference" / "extracted"
PHP_HELPER = WS / "scripts" / "php_ast_helper.php"
ZIP_REPORT = json.loads((WS / "discovery" / "ZIP_INTEGRITY_REPORT.json").read_text(encoding="utf-8"))

def sha256_file(p: Path) -> str:
    h = hashlib.sha256()
    with open(p, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def classify_module(rel_path: str) -> str:
    p = rel_path.lower().replace("\\", "/")
    if "admin" in p:
        return "ADMIN"
    if "license" in p:
        return "LICENSE"
    if "ai" in p or "openai" in p or "gemini" in p or "claude" in p or "deepseek" in p or "chatgpt" in p or "oauth" in p:
        return "AI_PROVIDERS"
    if "keyword" in p:
        return "KEYWORDS"
    if "rewrite" in p:
        return "POST_REWRITE"
    if "product" in p or "woo" in p:
        return "WOOCOMMERCE"
    if "image" in p or "media" in p or "video" in p:
        return "MEDIA"
    if "seo" in p or "rank" in p or "schema" in p or "link" in p:
        return "SEO"
    if "cron" in p or "queue" in p or "schedule" in p or "log" in p:
        return "AUTOMATION"
    if "assets/" in p or p.endswith((".css", ".js", ".png", ".jpg", ".svg", ".webp", ".gif")):
        return "ASSETS"
    if p.endswith((".txt", ".md", ".pot", ".po", ".mo")):
        return "DOCS_I18N"
    return "CORE"

def analyze_js(text: str) -> dict:
    funcs = re.findall(r"(?:function\s+([A-Za-z0-9_$]+)\s*\(|([A-Za-z0-9_$]+)\s*[:=]\s*(?:async\s+)?function\s*\()", text)
    func_names = sorted(set([a or b for a, b in funcs if (a or b)]))
    events = re.findall(r"\.(on|click|change|submit|keyup|keydown|addEventListener)\s*\(\s*['\"]([^'\"]+)['\"]", text)
    ajax = re.findall(r"(\$\.ajax|\$\.post|\$\.get|wp\.ajax\.post|jQuery\.ajax|jQuery\.post)", text)
    ajax_actions = re.findall(r"action\s*:\s*['\"]([A-Za-z0-9_\-]+)['\"]", text)
    fetches = re.findall(r"\bfetch\s*\(\s*([^\)]+)", text)
    dom_ops = sorted(set(re.findall(r"\.(html|text|val|append|prepend|remove|empty|addClass|removeClass|attr|prop|show|hide|fadeIn|fadeOut|innerHTML)\b", text)))
    form_handlers = re.findall(r"\$\(['\"]([^'\"]+)['\"]\)\.on\(\s*['\"]submit['\"]", text)
    error_handlers = re.findall(r"\.(fail|catch|error)\s*\(", text)
    ext_urls = sorted(set(re.findall(r"https?://[^\s'\"`<>]+", text)))
    return {
        "functions": func_names,
        "event_handlers": [{"method": m, "event": e} for m, e in events],
        "ajax_calls": sorted(set(ajax)),
        "ajax_actions": sorted(set(ajax_actions)),
        "fetch_calls": [f.strip()[:120] for f in fetches],
        "dom_manipulations": dom_ops,
        "form_handlers": form_handlers,
        "error_handlers_count": len(error_handlers),
        "external_urls": ext_urls,
    }

all_files = []
all_dirs = []
for root, dirs, files in os.walk(EXTRACTED):
    for d in sorted(dirs):
        all_dirs.append(Path(root) / d)
    for f in sorted(files):
        all_files.append(Path(root) / f)

inventory_rows = []
source_index = {
    "meta": {
        "zip_sha256": ZIP_REPORT["zip_sha256"],
        "archive_entries": ZIP_REPORT["total_entries"],
        "actual_dirs": len(all_dirs),
        "actual_files": len(all_files),
    },
    "files": {},
}

php_lint_errors = []

for idx, fpath in enumerate(all_files, 1):
    rel = str(fpath.relative_to(EXTRACTED)).replace("\\", "/")
    ext = fpath.suffix.lower().lstrip(".") or "no_ext"
    size = fpath.stat().st_size
    fhash = sha256_file(fpath)
    file_id = f"F{idx:03d}"
    module = classify_module(rel)

    entry = {
        "file_id": file_id,
        "relative_path": rel,
        "file_type": ext,
        "size_bytes": size,
        "sha256": fhash,
        "module": module,
        "analysis_status": "ANALYZED",
    }

    if ext == "php":
        # PHP lint
        lint_proc = subprocess.run(["php", "-l", str(fpath)], capture_output=True, text=True)
        lint_ok = (lint_proc.returncode == 0)
        if not lint_ok:
            php_lint_errors.append({"file": rel, "error": lint_proc.stdout + lint_proc.stderr})
        # PHP AST/Token parse
        ast_proc = subprocess.run(["php", str(PHP_HELPER), str(fpath)], capture_output=True, text=True)
        ast_data = {}
        if ast_proc.returncode == 0 and ast_proc.stdout.strip():
            try:
                ast_data = json.loads(ast_proc.stdout)
            except json.JSONDecodeError:
                ast_data = {"parse_error": "JSON decode failed"}
        else:
            ast_data = {"parse_error": ast_proc.stderr}
        entry["php_lint_ok"] = lint_ok
        entry["php"] = ast_data
        deps = [inc["expr"] for inc in ast_data.get("includes", [])]
        func_summary = f"classes:{len(ast_data.get('classes',[]))}, methods:{len(ast_data.get('methods',[]))}, funcs:{len(ast_data.get('functions',[]))}, hooks:{len(ast_data.get('hooks',[]))}"
    elif ext == "js":
        text = fpath.read_text(encoding="utf-8", errors="replace")
        js_data = analyze_js(text)
        entry["js"] = js_data
        deps = js_data["external_urls"]
        func_summary = f"funcs:{len(js_data['functions'])}, events:{len(js_data['event_handlers'])}, ajax:{len(js_data['ajax_actions'])}"
    elif ext in ("css", "txt", "md", "json", "pot", "po", "html", "htm", "xml"):
        text = fpath.read_text(encoding="utf-8", errors="replace")
        entry["line_count"] = len(text.splitlines())
        deps = []
        func_summary = f"{ext.upper()} ({entry['line_count']} lines)"
    else:
        deps = []
        func_summary = f"Binary/Asset ({ext}, {size} bytes)"

    source_index["files"][rel] = entry
    inventory_rows.append({
        "FILE_ID": file_id,
        "RELATIVE_PATH": rel,
        "FILE_TYPE": ext,
        "FILE_SIZE": size,
        "SHA256": fhash,
        "MODULE": module,
        "FUNCTION_SUMMARY": func_summary,
        "DEPENDENCY": "; ".join(deps[:5]),
        "LICENSE_STATUS": "PENDING_AUDIT",
        "SECURITY_STATUS": "PENDING_AUDIT",
        "ANALYSIS_STATUS": "ANALYZED",
    })

# Write CSV
csv_path = WS / "discovery" / "SOURCE_INVENTORY.csv"
with open(csv_path, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=list(inventory_rows[0].keys()))
    writer.writeheader()
    writer.writerows(inventory_rows)

# Write JSON
json_path = WS / "discovery" / "SOURCE_INDEX.json"
json_path.write_text(json.dumps(source_index, indent=2, ensure_ascii=False), encoding="utf-8")

# Write Coverage MD
cov_md = f"""# SOURCE COVERAGE REPORT

- **Source Archive**: `softviet-ai-seo-writer.zip`
- **Archive SHA-256**: `{ZIP_REPORT['zip_sha256']}`
- **Archive Entries**: {ZIP_REPORT['total_entries']} (Files: {ZIP_REPORT['file_entries']}, Dir entries: {ZIP_REPORT['dir_entries']})
- **Extracted Actual Directories**: {len(all_dirs)}
- **Extracted Actual Files**: {len(all_files)}
- **Files Analyzed**: {len(inventory_rows)}
- **Source Coverage**: {round(len(inventory_rows) / len(all_files) * 100, 2) if all_files else 0}%
- **PHP Lint Errors**: {len(php_lint_errors)}

## File Type Breakdown
"""
from collections import Counter
ext_counts = Counter(r["FILE_TYPE"] for r in inventory_rows)
for ext, cnt in sorted(ext_counts.items()):
    cov_md += f"- `{ext}`: {cnt}\n"

cov_md += "\n## File List\n"
for r in inventory_rows:
    cov_md += f"- `{r['FILE_ID']}` `{r['RELATIVE_PATH']}` ({r['FILE_TYPE']}, {r['FILE_SIZE']} bytes) — {r['FUNCTION_SUMMARY']}\n"

(WS / "discovery" / "SOURCE_COVERAGE.md").write_text(cov_md, encoding="utf-8")
print(f"Analyzed {len(inventory_rows)}/{len(all_files)} files. PHP lint errors: {len(php_lint_errors)}")
for ext, cnt in sorted(ext_counts.items()):
    print(f"  {ext}: {cnt}")
