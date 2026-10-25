"""Build a deletion MANIFEST for an application's media (privacy wipe).

DRY-RUN / read-only by default: it queries Mongo and writes three manifest
files describing exactly what a purge WOULD remove. It deletes nothing here.
The Mongo deletion (--apply) and the on-disk file deletion (delete_files.sh,
run on the server) both consume these manifests, so the list of victims is
decided once, reviewed, and frozen.

Safety model:
  * Apps are matched EXACTLY (no substring) — 'ORSkills' never touches
    'ORSkills-Dev' / 'oforral-orskills' unless you list them explicitly.
  * Only the per-media LEAF dir is recorded:
        <id[0:5]>/<id[5:10]>/<id[10:15]>/<fullid>
    The parent chunk dirs are timestamp-prefixed and SHARED across every app,
    so they are never deleted.

Usage:
  ../.venv/bin/python purge_app.py --apps ORSkills              # dry-run manifest
  ../.venv/bin/python purge_app.py --apps ORSkills --apply      # delete Mongo docs (prompts)
"""
import argparse
import os
import sys
from _conn import get_client

OUTDIR_FMT = "manifest_{tag}"


def base_file_key(media_id):
    """Mirror of LocalFileKey::getBaseFileKey (PHP). Returns relative leaf dir (no trailing /)."""
    s = str(media_id)
    return f"{s[0:5]}/{s[5:10]}/{s[10:15]}/{s}"


def build_manifest(db, apps, outdir):
    os.makedirs(outdir, exist_ok=True)
    media_ids, mediafile_ids, leaf_dirs = [], [], []

    cur = db.media.find({"applicationName": {"$in": apps}}, {"_id": 1, "files": 1})
    for doc in cur:
        mid = doc["_id"]
        media_ids.append(str(mid))
        leaf_dirs.append(base_file_key(mid))
        for ref in (doc.get("files") or []):
            # files entries are bson DBRef objects
            rid = getattr(ref, "id", None)
            if rid is None and isinstance(ref, dict):
                rid = ref.get("$id")
            if rid is not None:
                mediafile_ids.append(str(rid))

    # dedupe mediafile ids (defensive)
    mediafile_ids = sorted(set(mediafile_ids))

    def write(name, rows):
        path = os.path.join(outdir, name)
        with open(path, "w") as fh:
            fh.write("\n".join(rows) + ("\n" if rows else ""))
        return path

    p1 = write("media_ids.txt", media_ids)
    p2 = write("mediafile_ids.txt", mediafile_ids)
    p3 = write("leaf_dirs.txt", leaf_dirs)

    print("Apps (exact)      :", ", ".join(apps))
    print("media docs        :", f"{len(media_ids):,}")
    print("mediaFile docs    :", f"{len(mediafile_ids):,}  (unique, referenced)")
    print("leaf dirs listed  :", f"{len(leaf_dirs):,}  (one per media; many may not exist on disk)")
    print("\nManifests written to:", outdir)
    for p in (p1, p2, p3):
        print("  ", p)
    return media_ids, mediafile_ids


def apply_mongo(db, apps, media_ids, mediafile_ids):
    # Delete child mediaFile docs in batches, then media docs, then deactivate apiUsers.
    from bson import ObjectId

    def to_oids(strs):
        out = []
        for s in strs:
            try:
                out.append(ObjectId(s))
            except Exception:
                out.append(s)  # tolerate non-ObjectId ids
        return out

    print("\nDeleting mediaFile docs...")
    n_mf = 0
    oids = to_oids(mediafile_ids)
    for i in range(0, len(oids), 1000):
        res = db.mediaFile.delete_many({"_id": {"$in": oids[i:i + 1000]}})
        n_mf += res.deleted_count
    print("  mediaFile deleted:", f"{n_mf:,}")

    print("Deleting media docs...")
    res = db.media.delete_many({"applicationName": {"$in": apps}})
    print("  media deleted    :", f"{res.deleted_count:,}")

    print("Deactivating apiUsers...")
    res = db.apiUsers.update_many({"applicationName": {"$in": apps}}, {"$set": {"active": False}})
    print("  apiUsers deactivated:", res.modified_count)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--apps", nargs="+", required=True, help="EXACT applicationName(s)")
    ap.add_argument("--apply", action="store_true", help="perform Mongo deletions (prompts)")
    ap.add_argument("--out", default=None, help="manifest output dir")
    args = ap.parse_args()

    outdir = args.out or OUTDIR_FMT.format(tag="_".join(args.apps))
    client, dbname = get_client()
    db = client[dbname]

    media_ids, mediafile_ids = build_manifest(db, args.apps, outdir)

    if not args.apply:
        print("\nDRY-RUN complete. No data was modified. Re-run with --apply to delete Mongo docs.")
        print("On-disk files are deleted separately on the server via delete_files.sh + leaf_dirs.txt.")
        return

    print("\n*** --apply: this will permanently delete the above Mongo documents. ***")
    confirm = input(f"Type the app name(s) to confirm [{' '.join(args.apps)}]: ").strip()
    if confirm != " ".join(args.apps):
        print("Confirmation mismatch. Aborting. Nothing deleted.")
        sys.exit(1)
    apply_mongo(db, args.apps, media_ids, mediafile_ids)
    print("\nMongo deletion done. Now run delete_files.sh on the server for on-disk files.")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("ERROR:", type(e).__name__, e, file=sys.stderr)
        sys.exit(1)
