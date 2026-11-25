import json
import os
import re
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
EXTRACTED = WS / "reference" / "extracted"
SOURCE_INDEX = json.loads((WS / "discovery" / "SOURCE_INDEX.json").read_text(encoding="utf-8"))

audit_data = {
    "license_headers": {},
    "third_party_mentions": [],
    "external_urls": {},
    "db_tables": [],
    "options": set(),
    "post_meta": set(),
    "transients": set(),
    "cron_hooks": set(),
    "capabilities": set(),
    "nonces": [],
    "ajax_actions": [],
    "rest_routes": [],
    "security_hits": [],
    "ui_screens": [],
    "softviet_brand_refs": [],
}

URL_RE = re.compile(r"https?://[A-Za-z0-9\.\-_:/\?\=\&\%\#]+")
COPYRIGHT_RE = re.compile(r"(copyright|license|@license|gpl|mit|apache|bsd|proprietary|all rights reserved)", re.I)
THIRD_PARTY_RE = re.compile(r"(composer|vendor|guzzle|curl|jwt|firebase|phpseclib|parsedown|dompdf|phpmailer|select2|sweetalert|chart\.js|bootstrap|tailwind)", re.I)

DANGEROUS_PATTERNS = [
    ("EVAL", re.compile(r"\beval\s*\(", re.I)),
    ("ASSERT_DYNAMIC", re.compile(r"\bassert\s*\(\s*\$", re.I)),
    ("SHELL_EXEC", re.compile(r"\b(shell_exec|exec|system|passthru|proc_open|popen)\s*\(", re.I)),
    ("UNSERIALIZE", re.compile(r"\bunserialize\s*\(", re.I)),
    ("BASE64_DECODE", re.compile(r"\bbase64_decode\s*\(", re.I)),
    ("GZINFLATE", re.compile(r"\b(gzinflate|gzuncompress|gzdecode)\s*\(", re.I)),
    ("CREATE_FUNCTION", re.compile(r"\bcreate_function\s*\(", re.I)),
    ("PREG_REPLACE_E", re.compile(r"preg_replace\s*\(\s*['\"][^'\"]*/e['\"]", re.I)),
    ("DYNAMIC_INCLUDE", re.compile(r"\b(include|include_once|require|require_once)\s*\(?\s*\$", re.I)),
    ("SQL_RAW_VAR", re.compile(r"\$wpdb->(query|get_results|get_row|get_var|get_col)\s*\(\s*[\"'][^\"']*\$", re.I)),
    ("RAW_SUPERGLOBAL_ECHO", re.compile(r"\becho\s+\$_(GET|POST|REQUEST|SERVER|COOKIE)\b", re.I)),
    ("SSLVERIFY_FALSE", re.compile(r"['\"]sslverify['\"]\s*=>\s*false", re.I)),
    ("CURLOPT_SSL_FALSE", re.compile(r"CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0)", re.I)),
    ("UNLINK_VAR", re.compile(r"\bunlink\s*\(\s*\$", re.I)),
    ("FILE_PUT_CONTENTS", re.compile(r"\bfile_put_contents\s*\(", re.I)),
    ("NOPRIV_AJAX", re.compile(r"wp_ajax_nopriv_", re.I)),
]

for rel, info in SOURCE_INDEX["files"].items():
    fpath = EXTRACTED / rel
    ext = info["file_type"]
    if ext not in ("php", "js", "css", "txt", "md", "po"):
        continue
    text = fpath.read_text(encoding="utf-8", errors="replace")
    lines = text.splitlines()

    # License / copyright scan in first 40 lines + whole file for copyright
    lic_lines = []
    for idx, line in enumerate(lines[:40], 1):
        if COPYRIGHT_RE.search(line):
            lic_lines.append(f"L{idx}: {line.strip()[:120]}")
    if lic_lines:
        audit_data["license_headers"][rel] = lic_lines

    # Third party mentions
    for idx, line in enumerate(lines, 1):
        m = THIRD_PARTY_RE.search(line)
        if m and ext in ("php", "js", "txt", "md"):
            # avoid noisy css/class hits unless notable
            if m.group(1).lower() in ("jwt", "jwks", "firebase", "phpseclib", "guzzle", "composer", "vendor"):
                audit_data["third_party_mentions"].append({"file": rel, "line": idx, "match": m.group(1), "text": line.strip()[:120]})

    # External URLs
    for idx, line in enumerate(lines, 1):
        for url in URL_RE.findall(line):
            audit_data["external_urls"].setdefault(url, []).append(f"{rel}:L{idx}")

    # SoftViet brand refs count
    sv_hits = len(re.findall(r"softviet", text, re.I))
    if sv_hits > 0:
        audit_data["softviet_brand_refs"].append({"file": rel, "count": sv_hits})

    if ext == "php":
        # DB tables
        for m in re.finditer(r"CREATE TABLE(?:\s+IF NOT EXISTS)?\s+([^\s(]+)", text, re.I):
            audit_data["db_tables"].append({"file": rel, "table": m.group(1)})
        for m in re.finditer(r"\$wpdb->prefix\s*\.\s*['\"]([a-zA-Z0-9_]+)['\"]", text):
            audit_data["db_tables"].append({"file": rel, "table": "prefix." + m.group(1)})

        # Options
        for m in re.finditer(r"\b(?:get_option|update_option|add_option|delete_option)\s*\(\s*['\"]([a-zA-Z0-9_\-]+)['\"]", text):
            audit_data["options"].add(m.group(1))

        # Post meta
        for m in re.finditer(r"\b(?:get_post_meta|update_post_meta|add_post_meta|delete_post_meta)\s*\([^,]+,\s*['\"]([a-zA-Z0-9_\-]+)['\"]", text):
            audit_data["post_meta"].add(m.group(1))

        # Transients
        for m in re.finditer(r"\b(?:get_transient|set_transient|delete_transient)\s*\(\s*['\"]([a-zA-Z0-9_\-]+)['\"]", text):
            audit_data["transients"].add(m.group(1))

        # Cron hooks
        for m in re.finditer(r"\b(?:wp_schedule_event|wp_schedule_single_event|wp_next_scheduled|wp_clear_scheduled_hook)\s*\([^)]*['\"]([a-zA-Z0-9_\-]+)['\"]", text):
            audit_data["cron_hooks"].add(m.group(1))

        # Capabilities
        for m in re.finditer(r"\bcurrent_user_can\s*\(\s*['\"]([a-zA-Z0-9_\-]+)['\"]", text):
            audit_data["capabilities"].add(m.group(1))

        # Nonces
        for m in re.finditer(r"\b(?:check_ajax_referer|wp_verify_nonce|wp_nonce_field|check_admin_referer)\s*\(\s*['\"]([a-zA-Z0-9_\-]+)['\"]", text):
            audit_data["nonces"].append({"file": rel, "nonce": m.group(1)})

        # Security patterns
        for pat_name, pat_re in DANGEROUS_PATTERNS:
            for idx, line in enumerate(lines, 1):
                if pat_re.search(line):
                    audit_data["security_hits"].append({
                        "pattern": pat_name,
                        "file": rel,
                        "line": idx,
                        "snippet": line.strip()[:160],
                    })

        # PHP AST hooks / ajax / rest
        php_ast = info.get("php", {})
        for ax in php_ast.get("ajax_actions", []):
            audit_data["ajax_actions"].append({"file": rel, "line": ax["line"], "args": ax["args"]})
        for rr in php_ast.get("rest_routes", []):
            audit_data["rest_routes"].append({"file": rel, "line": rr["line"], "args": rr["args"]})

# Convert sets to sorted lists
audit_data["options"] = sorted(audit_data["options"])
audit_data["post_meta"] = sorted(audit_data["post_meta"])
audit_data["transients"] = sorted(audit_data["transients"])
audit_data["cron_hooks"] = sorted(audit_data["cron_hooks"])
audit_data["capabilities"] = sorted(audit_data["capabilities"])

out_file = WS / "discovery" / "DEEP_STATIC_AUDIT.json"
out_file.write_text(json.dumps(audit_data, indent=2, ensure_ascii=False), encoding="utf-8")

print("External URLs count:", len(audit_data["external_urls"]))
print("Options count:", len(audit_data["options"]))
print("Post meta count:", len(audit_data["post_meta"]))
print("Transients count:", len(audit_data["transients"]))
print("Cron hooks:", audit_data["cron_hooks"])
print("Capabilities:", audit_data["capabilities"])
print("AJAX actions count:", len(audit_data["ajax_actions"]))
print("REST routes count:", len(audit_data["rest_routes"]))
print("Security pattern hits:", len(audit_data["security_hits"]))
for hit in audit_data["security_hits"][:30]:
    print(f"  [{hit['pattern']}] {hit['file']}:{hit['line']} -> {hit['snippet'][:80]}")
