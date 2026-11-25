import json
import re
from pathlib import Path

WS = Path(r"D:\HTD-AI-SEO\HTD-AI-SEO-WRITER-PRO")
EXTRACTED = WS / "reference" / "extracted" / "softviet-ai-seo-writer"
SOURCE_INDEX = json.loads((WS / "discovery" / "SOURCE_INDEX.json").read_text(encoding="utf-8"))

# 1. Admin menus & submenus
menu_file = EXTRACTED / "admin" / "class-sv-ai-admin-menu.php"
menu_text = menu_file.read_text(encoding="utf-8", errors="replace")
menus = re.findall(r"(add_menu_page|add_submenu_page)\s*\(([^;]+)\);", menu_text, re.S)

# 2. Scan admin/views/*.php for UI elements
ui_inventory = []
ui_id_counter = 1
for view_path in sorted((EXTRACTED / "admin" / "views").glob("*.php")):
    rel = f"softviet-ai-seo-writer/admin/views/{view_path.name}"
    text = view_path.read_text(encoding="utf-8", errors="replace")
    lines = text.splitlines()
    screen = view_path.stem

    # Tabs, forms, buttons, inputs, selects, tables, modals
    patterns = [
        ("TAB", re.compile(r'class=["\'][^"\']*nav-tab[^"\']*["\']', re.I)),
        ("FORM", re.compile(r'<form\b[^>]*>', re.I)),
        ("BUTTON", re.compile(r'<button\b[^>]*>|type=["\']submit["\']', re.I)),
        ("INPUT", re.compile(r'<input\b[^>]*>', re.I)),
        ("SELECT", re.compile(r'<select\b[^>]*>', re.I)),
        ("TEXTAREA", re.compile(r'<textarea\b[^>]*>', re.I)),
        ("TABLE", re.compile(r'<table\b[^>]*>', re.I)),
        ("MODAL", re.compile(r'class=["\'][^"\']*modal[^"\']*["\']|id=["\'][^"\']*modal[^"\']*["\']', re.I)),
    ]
    for idx, line in enumerate(lines, 1):
        for kind, pat in patterns:
            if pat.search(line):
                # extract id or name if present
                m_id = re.search(r'\bid=["\']([^"\']+)["\']', line)
                m_name = re.search(r'\bname=["\']([^"\']+)["\']', line)
                m_type = re.search(r'\btype=["\']([^"\']+)["\']', line)
                ident = (m_id.group(1) if m_id else "") or (m_name.group(1) if m_name else "")
                if kind == "INPUT" and m_type and m_type.group(1).lower() == "checkbox":
                    kind = "CHECKBOX"
                ui_inventory.append({
                    "UI_ID": f"UI_{ui_id_counter:04d}",
                    "SCREEN": screen,
                    "CONTROL_TYPE": kind,
                    "IDENTIFIER": ident,
                    "SOURCE_FILE": rel,
                    "SOURCE_LINE": idx,
                    "SNIPPET": line.strip()[:120],
                })
                ui_id_counter += 1

# 3. Check SQL calls without prepare
sql_audit = []
for rel, info in SOURCE_INDEX["files"].items():
    if info["file_type"] != "php":
        continue
    fpath = WS / "reference" / "extracted" / rel
    lines = fpath.read_text(encoding="utf-8", errors="replace").splitlines()
    for idx, line in enumerate(lines, 1):
        if re.search(r"\$wpdb->(query|get_results|get_row|get_var|get_col)\s*\(", line):
            has_prepare = ("$wpdb->prepare" in line) or ("prepare(" in line)
            has_var = "$" in line.split("(", 1)[1] if "(" in line else False
            if not has_prepare and has_var:
                sql_audit.append({"file": rel, "line": idx, "snippet": line.strip()[:140]})

# 4. Check admin-post / AJAX handlers for nonce & capability checks
ajax_security = []
for rel, info in SOURCE_INDEX["files"].items():
    if info["file_type"] != "php":
        continue
    fpath = WS / "reference" / "extracted" / rel
    text = fpath.read_text(encoding="utf-8", errors="replace")
    for m in re.finditer(r"public\s+(?:static\s+)?function\s+(ajax_[A-Za-z0-9_]+|handle_[A-Za-z0-9_]+)\s*\(", text):
        fn = m.group(1)
        start = m.start()
        body = text[start:start+1200]
        has_nonce = ("check_ajax_referer" in body) or ("check_admin_referer" in body) or ("wp_verify_nonce" in body)
        has_cap = ("current_user_can" in body) or ("ensure_admin_access" in body)
        line_no = text[:start].count("\n") + 1
        ajax_security.append({"file": rel, "line": line_no, "func": fn, "has_nonce": has_nonce, "has_cap": has_cap})

summary = {
    "ui_controls_count": len(ui_inventory),
    "ui_by_screen": {},
    "sql_unprepared_with_vars": sql_audit,
    "ajax_handler_security": ajax_security,
}
for item in ui_inventory:
    s = item["SCREEN"]
    summary["ui_by_screen"][s] = summary["ui_by_screen"].get(s, 0) + 1

(WS / "discovery" / "UI_RAW.json").write_text(json.dumps(ui_inventory, indent=2, ensure_ascii=False), encoding="utf-8")
(WS / "discovery" / "SECURITY_DEEP_CHECKS.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")

print("UI controls found:", len(ui_inventory))
print("By screen:", summary["ui_by_screen"])
print("SQL unprepared with vars:", len(sql_audit))
for s in sql_audit:
    print("  SQL:", s["file"], s["line"], s["snippet"][:90])
print("AJAX/Handler security checks:", len(ajax_security))
for a in ajax_security:
    if not a["has_nonce"] or not a["has_cap"]:
        print("  MISSING CHECK:", a)
