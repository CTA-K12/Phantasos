"""Verify the media store copy: every media_file row in PostgreSQL has its file on the new host,
with the same size as on the old server, and the right ownership and modes.

Read-only everywhere. File listings come from `find` over SSH (paths and sizes only).

Usage: ../.venv/bin/python verify_media_copy.py [--target prod|local] [--no-old]
                                                [--old-listing FILE] [--save-old FILE]

Listing the old server takes ~20 minutes (slow iSCSI storage), so --save-old writes its listing
to a file and --old-listing reuses it. Legacy files never change once written, so a listing taken
earlier the same day is fine, except for media uploaded since.

Exit status 0 when every DB file is present on the new host with a matching size and correct
ownership; 1 otherwise. Extra files on disk that the DB doesn't know about are reported but
don't fail the check (e.g. leftovers from purged apps or failed legacy jobs).
"""
import argparse
import subprocess
import sys
from collections import Counter

from import_to_pg import pg_connect

NEW_HOST = "root@phantasos.apps.cascadetech.org"
OLD_HOST = "root@phantasos.mesd.k12.or.us"
ROOT = "/media/phantasos"
OWNER = "65534:65534"


def listing(host, kind, save=None):
    """{relative path: (size, owner, mode)} for files ('f') or directories ('d') under ROOT."""
    cmd = f"find {ROOT} -mindepth 1 -type {kind} -printf '%P\\t%s\\t%U:%G\\t%m\\n'"
    out = subprocess.run(["ssh", "-o", "BatchMode=yes", "-o", "LogLevel=ERROR", host, cmd],
                         capture_output=True, text=True, check=True).stdout
    if save:
        with open(save, "w") as fh:
            fh.write(out)
    return parse(out)


def parse(out):
    result = {}
    for line in out.splitlines():
        path, size, owner, mode = line.split("\t")
        result[path] = (int(size), owner, mode)
    return result


def gib(n):
    return f"{n / 1024 ** 3:.1f} GiB"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--target", choices=["prod", "local"], default="prod")
    ap.add_argument("--no-old", action="store_true", help="skip the old-server size comparison")
    ap.add_argument("--old-listing", help="reuse an old-server listing saved with --save-old")
    ap.add_argument("--save-old", help="save the old-server listing to this file")
    args = ap.parse_args()

    conn = pg_connect(args.target)
    with conn, conn.cursor() as cur:
        cur.execute("SELECT m.id, f.file_name FROM media_file f JOIN media m ON m.id = f.media_id")
        expected = {f"{i[0:5]}/{i[5:10]}/{i[10:15]}/{i}/{name}" for i, name in cur.fetchall()}
    conn.close()

    new = listing(NEW_HOST, "f")
    new_dirs = listing(NEW_HOST, "d")
    if args.no_old:
        old = {}
    elif args.old_listing:
        with open(args.old_listing) as fh:
            old = parse(fh.read())
    else:
        old = listing(OLD_HOST, "f", save=args.save_old)

    missing_new = sorted(p for p in expected if p not in new)
    missing_old = sorted(p for p in expected if old and p not in old)
    size_diff = sorted(p for p in expected if p in new and p in old and new[p][0] != old[p][0])
    extra_new = sorted(p for p in new if p not in expected)
    not_on_new_from_old = sorted(p for p in old if p not in new)
    bad_owner = Counter(v[1] for v in list(new.values()) + list(new_dirs.values()) if v[1] != OWNER)
    bad_fmode = Counter(v[2] for v in new.values() if v[2] != "644")
    bad_dmode = Counter(v[2] for v in new_dirs.values() if v[2] != "755")

    print(f"DB media_file rows ({args.target}): {len(expected):,}")
    print(f"new host: {len(new):,} files, {len(new_dirs):,} dirs, {gib(sum(v[0] for v in new.values()))}")
    if old:
        print(f"old host: {len(old):,} files, {gib(sum(v[0] for v in old.values()))}")
    print()
    print(f"DB files missing on new host:      {len(missing_new):,}")
    if old:
        print(f"  ...of which also missing on old: {len(set(missing_new) & set(missing_old)):,}"
              "  (pre-existing gaps, not copy errors)")
        print(f"size differs old vs new:           {len(size_diff):,}")
        print(f"on old but not on new (any file):  {len(not_on_new_from_old):,}")
    print(f"on new but not in DB (extras):     {len(extra_new):,}")
    print(f"ownership not {OWNER}:       {sum(bad_owner.values()):,} {dict(bad_owner) or ''}")
    print(f"file mode not 644:                 {sum(bad_fmode.values()):,} {dict(bad_fmode) or ''}")
    print(f"dir mode not 755:                  {sum(bad_dmode.values()):,} {dict(bad_dmode) or ''}")

    for title, items in [("missing on new", missing_new), ("size differs", size_diff),
                         ("on old, not on new", not_on_new_from_old), ("extras on new", extra_new)]:
        if items:
            print(f"\nfirst {min(10, len(items))} {title}:")
            for p in items[:10]:
                print("  ", p)

    copy_errors = (set(missing_new) - set(missing_old)) if old else set(missing_new)
    ok = not copy_errors and not size_diff and not bad_owner
    print("\nRESULT:", "OK" if ok else "PROBLEMS FOUND")
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
