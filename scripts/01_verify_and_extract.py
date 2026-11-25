import hashlib
import json
import os
import shutil
import stat
import zipfile
from pathlib import Path

ROOT = Path(r"D:\HTD-AI-SEO")
ZIP_PATH = ROOT / "softviet-ai-seo-writer.zip"
WS = ROOT / "HTD-AI-SEO-WRITER-PRO"

DIRS = [
    WS / "reference" / "original",
    WS / "reference" / "extracted",
    WS / "discovery",
    WS / "architecture",
    WS / "security",
    WS / "compliance",
    WS / "qa",
    WS / "reports",
    WS / "scripts",
    WS / "src",
    WS / "tests",
    WS / "build",
]

for d in DIRS:
    d.mkdir(parents=True, exist_ok=True)

# 1. SHA-256 of original ZIP
h = hashlib.sha256()
with open(ZIP_PATH, "rb") as f:
    for chunk in iter(lambda: f.read(65536), b""):
        h.update(chunk)
zip_sha256 = h.hexdigest()
zip_size = ZIP_PATH.stat().st_size

# Copy to reference/original as read-only if not already there
ref_zip = WS / "reference" / "original" / "softviet-ai-seo-writer.zip"
if not ref_zip.exists():
    shutil.copy2(ZIP_PATH, ref_zip)
    os.chmod(ref_zip, stat.S_IREAD)

# Also set original ZIP read-only attribute? Wait: "Không sửa, xóa hoặc ghi đè file ZIP gốc." Let's not modify its attributes if not needed, or just leave it untouched.

report = {
    "zip_path": str(ZIP_PATH),
    "zip_size_bytes": zip_size,
    "zip_sha256": zip_sha256,
    "testzip_bad_file": None,
    "total_entries": 0,
    "dir_entries": 0,
    "file_entries": 0,
    "total_uncompressed_bytes": 0,
    "compression_ratio": 0.0,
    "path_traversal_issues": [],
    "symlink_entries": [],
    "duplicate_entries": [],
    "nested_archives_or_executables": [],
    "unusual_paths": [],
}

seen_names = {}
EXEC_EXTS = {".exe", ".dll", ".bat", ".cmd", ".ps1", ".sh", ".bin", ".so", ".dylib", ".phar"}
ARCHIVE_EXTS = {".zip", ".tar", ".gz", ".bz2", ".7z", ".rar", ".tgz"}

with zipfile.ZipFile(ZIP_PATH, "r") as zf:
    bad = zf.testzip()
    report["testzip_bad_file"] = bad
    infos = zf.infolist()
    report["total_entries"] = len(infos)

    for info in infos:
        name = info.filename
        lower_name = name.lower()
        if lower_name in seen_names:
            report["duplicate_entries"].append(name)
        seen_names[lower_name] = seen_names.get(lower_name, 0) + 1

        # Path traversal check
        if name.startswith("/") or name.startswith("\\") or ".." in Path(name).parts or ":" in name:
            report["path_traversal_issues"].append(name)

        # Symlink check (Unix mode in external_attr >> 16)
        unix_mode = info.external_attr >> 16
        if stat.S_ISLNK(unix_mode):
            report["symlink_entries"].append(name)

        if info.is_dir():
            report["dir_entries"] += 1
        else:
            report["file_entries"] += 1
            report["total_uncompressed_bytes"] += info.file_size
            ext = Path(name).suffix.lower()
            if ext in EXEC_EXTS or ext in ARCHIVE_EXTS:
                report["nested_archives_or_executables"].append({"name": name, "ext": ext, "size": info.file_size})

    if zip_size > 0:
        report["compression_ratio"] = round(report["total_uncompressed_bytes"] / zip_size, 2)

    # Safe extraction check
    if not bad and not report["path_traversal_issues"] and not report["symlink_entries"] and report["total_uncompressed_bytes"] < 100 * 1024 * 1024:
        extract_dir = WS / "reference" / "extracted"
        zf.extractall(extract_dir)
        # Set extracted files read-only
        for root_dir, dirs, files in os.walk(extract_dir):
            for fname in files:
                fpath = Path(root_dir) / fname
                os.chmod(fpath, stat.S_IREAD)
        report["extracted"] = True
    else:
        report["extracted"] = False

out_path = WS / "discovery" / "ZIP_INTEGRITY_REPORT.json"
out_path.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
print(json.dumps(report, indent=2, ensure_ascii=False))
