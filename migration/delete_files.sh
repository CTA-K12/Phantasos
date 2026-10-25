#!/usr/bin/env bash
#
# On-disk file deletion for a Phantasos app purge. RUN ON THE STORAGE SERVER
# (the box that mounts /media/phantasos). Consumes leaf_dirs.txt produced by
# purge_app.py.
#
# Modes:
#   ./delete_files.sh measure leaf_dirs.txt     # report count + bytes of existing dirs (no changes)
#   ./delete_files.sh delete  leaf_dirs.txt     # rm -rf each existing leaf dir (DESTRUCTIVE, prompts)
#
# Safety:
#   * Only deletes paths under $BASE that match the exact leaf pattern
#       <5hex>/<5hex>/<5hex>/<24hex>
#     Any malformed line is refused — no path traversal, never the base/parent dirs.
#   * Skips dirs that don't exist (many media were never uploaded).
#
# Override base dir with:  BASE=/some/path ./delete_files.sh ...
set -euo pipefail

BASE="${BASE:-/media/phantasos}"
MODE="${1:-}"
LIST="${2:-}"
PATTERN='^[0-9a-fA-F]{5}/[0-9a-fA-F]{5}/[0-9a-fA-F]{5}/[0-9a-fA-F]{24}$'

if [[ "$MODE" != "measure" && "$MODE" != "delete" ]] || [[ -z "$LIST" ]]; then
    echo "usage: $0 {measure|delete} leaf_dirs.txt" >&2
    exit 2
fi
if [[ ! -d "$BASE" ]]; then
    echo "ERROR: base dir '$BASE' not found. Run this on the storage server (or set BASE=)." >&2
    exit 1
fi
if [[ ! -f "$LIST" ]]; then
    echo "ERROR: list file '$LIST' not found." >&2
    exit 1
fi

total_lines=0 bad=0 existing=0 missing=0 bytes=0 deleted=0

# Validate every line first; abort entirely if any line is malformed.
while IFS= read -r rel || [[ -n "$rel" ]]; do
    [[ -z "$rel" ]] && continue
    total_lines=$((total_lines+1))
    if [[ ! "$rel" =~ $PATTERN ]]; then
        echo "REFUSING: malformed path on line $total_lines: '$rel'" >&2
        bad=$((bad+1))
    fi
done < "$LIST"
if [[ "$bad" -gt 0 ]]; then
    echo "Aborting: $bad malformed path(s). Nothing touched." >&2
    exit 1
fi

if [[ "$MODE" == "delete" ]]; then
    echo "About to rm -rf existing leaf dirs under $BASE for $total_lines entries."
    read -r -p "Type DELETE to proceed: " ans
    [[ "$ans" == "DELETE" ]] || { echo "Aborted."; exit 1; }
fi

while IFS= read -r rel || [[ -n "$rel" ]]; do
    [[ -z "$rel" ]] && continue
    path="$BASE/$rel"
    if [[ -d "$path" ]]; then
        existing=$((existing+1))
        if [[ "$MODE" == "measure" ]]; then
            sz=$(du -sb "$path" 2>/dev/null | cut -f1 || echo 0)
            bytes=$((bytes + sz))
        else
            rm -rf "$path"
            deleted=$((deleted+1))
        fi
    else
        missing=$((missing+1))
    fi
done < "$LIST"

echo "----------------------------------------"
echo "base dir        : $BASE"
echo "entries in list : $total_lines"
echo "existing on disk: $existing"
echo "missing on disk : $missing"
if [[ "$MODE" == "measure" ]]; then
    echo "bytes on disk   : $bytes  ($(numfmt --to=iec $bytes 2>/dev/null || echo "$bytes B"))"
    echo "(measure only — nothing deleted)"
else
    echo "dirs deleted    : $deleted"
fi
