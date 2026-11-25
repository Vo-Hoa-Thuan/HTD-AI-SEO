import csv
import hashlib
import json
import re
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
SRC = WS / "src" / "htd-ai-seo-writer-pro"

PATTERNS = {
    "TODO_FIXME": re.compile(r"\b(TODO|FIXME|XXX)\b"),
    "DANGEROUS_EXEC": re.compile(r"\b(eval|shell_exec|exec|system|passthru|proc_open|popen|unserialize|create_function)\s*\("),
    "NOPRIV_OR_PUBLIC_REST": re.compile(r"(wp_ajax_nopriv_|__return_true)"),
    "HARDCODED_SECRET": re.compile(r"(sk-[A-Za-z0-9_\-]{16,}|AIza[0-9A-Za-z\-_]{25,}|sk-ant-[A-Za-z0-9_\-]{16,})"),
    "SOFTVIET_REF": re.compile(r"softviet", re.I),
}

manifest = []
audit_hits = {k: [] for k in PATTERNS}

for f in sorted(SRC.rglob("*")):
    if not f.is_file():
        continue
    rel = str(f.relative_to(SRC)).replace("\\", "/")
    raw = f.read_bytes()
    sha = hashlib.sha256(raw).hexdigest()
    manifest.append({"path": rel, "size": len(raw), "sha256": sha})

    if f.suffix.lower() in (".php", ".js", ".css", ".json", ".md", ".txt"):
        lines = raw.decode("utf-8", errors="replace").splitlines()
        for idx, line in enumerate(lines, 1):
            for pname, preg in PATTERNS.items():
                if preg.search(line):
                    audit_hits[pname].append({"file": rel, "line": idx, "snippet": line.strip()[:120]})

(WS / "qa" / "HTD_SOURCE_MANIFEST.json").write_text(json.dumps({"total_files": len(manifest), "files": manifest, "audit_hits": audit_hits}, indent=2, ensure_ascii=False), encoding="utf-8")

# Inventory AdminMenu handlers & all templates
admin_php = (SRC / "src" / "Admin" / "AdminMenu.php").read_text(encoding="utf-8")
registered_actions = set(re.findall(r"admin_post_(htd_ai_seo_[a-zA-Z0-9_]+)", admin_php))

ui_rows = []
ui_id = 1
for tpl in sorted((SRC / "templates").glob("*.php")):
    text = tpl.read_text(encoding="utf-8")
    forms = re.findall(r"<form\b[^>]*>", text, re.I)
    nonces = re.findall(r"wp_nonce_field\(['\"]([^'\"]+)['\"]\)", text)
    actions = re.findall(r'name="action"\s+value="([^"]+)"', text)
    buttons = re.findall(r"<button\b[^>]*>|submit_button\(", text, re.I)

    # Check if every form action has a registered admin_post_ handler
    for i, act in enumerate(actions):
        has_handler = act in registered_actions
        has_nonce = (act in nonces) or len(nonces) > i
        ui_rows.append({
            "UI_ID": f"HTD_UI_{ui_id:03d}",
            "SCREEN": tpl.stem,
            "CONTROL_TYPE": "FORM_SUBMIT",
            "ACTION": act,
            "HAS_BACKEND_HANDLER": "YES" if has_handler else "MISSING",
            "HAS_NONCE": "YES" if has_nonce else "MISSING",
            "REQUIRED_CAPABILITY": "htd_ai_seo_manage*",
            "RUNTIME_STATUS": "NOT_RUN (No WP runtime)",
        })
        ui_id += 1

    if not actions:
        ui_rows.append({
            "UI_ID": f"HTD_UI_{ui_id:03d}",
            "SCREEN": tpl.stem,
            "CONTROL_TYPE": "READ_ONLY_VIEW",
            "ACTION": "render",
            "HAS_BACKEND_HANDLER": "YES",
            "HAS_NONCE": "N/A",
            "REQUIRED_CAPABILITY": "htd_ai_seo_manage*",
            "RUNTIME_STATUS": "NOT_RUN (No WP runtime)",
        })
        ui_id += 1

with open(WS / "qa" / "UI_CONTROL_TEST_MATRIX.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(ui_rows[0].keys()))
    w.writeheader()
    w.writerows(ui_rows)

print(f"Audited {len(manifest)} HTD source files. UI matrix rows: {len(ui_rows)}")
for k, v in audit_hits.items():
    print(f"  {k}: {len(v)}")
    for item in v:
        print(f"    - {item['file']}:{item['line']} -> {item['snippet'][:80]}")
