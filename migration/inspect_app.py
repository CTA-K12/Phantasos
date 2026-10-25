"""Read-only deep look at one application's data, to design the purge safely.

Shows: exact-match media count, the raw structure of a sample media doc's
`files` reference field (so we know how to find/delete child mediaFile docs),
total referenced mediaFile count, a sample referenced mediaFile doc, and the
derived on-disk leaf dir for a sample media id. Writes/deletes nothing.

Usage:  ../.venv/bin/python inspect_app.py ORSkills
"""
import sys
import json
from bson import ObjectId
from _conn import get_client


def base_file_key(media_id):
    """Mirror of LocalFileKey::getBaseFileKey (PHP) — must match exactly."""
    s = str(media_id)
    return f"{s[0:5]}/{s[5:10]}/{s[10:15]}/{s}/"


def jdefault(o):
    return str(o)


def main():
    app = sys.argv[1] if len(sys.argv) > 1 else "ORSkills"
    client, dbname = get_client()
    db = client[dbname]

    print(f"App (exact match): {app!r}")
    n_media = db.media.count_documents({"applicationName": app})
    print("media docs        :", f"{n_media:,}")

    # Show apps whose name CONTAINS the term, to catch lookalikes we must NOT touch
    print("\nLookalike app names (substring match) — confirm scope:")
    for row in db.media.aggregate([
        {"$match": {"applicationName": {"$regex": app, "$options": "i"}}},
        {"$group": {"_id": "$applicationName", "n": {"$sum": 1}}},
        {"$sort": {"n": -1}},
    ]):
        flag = "  <== exact target" if row["_id"] == app else ""
        print(f"  {str(row['_id']):30s} {row['n']:>8,}{flag}")

    # Sample media doc — dump raw so we see how `files` is stored
    sample = db.media.find_one({"applicationName": app})
    print("\nSample media doc (raw):")
    print(json.dumps(sample, indent=2, default=jdefault)[:2000])

    if sample:
        print("\nDerived on-disk leaf dir for sample id:")
        print("  /media/phantasos/" + base_file_key(sample["_id"]))

    # Total referenced mediaFile entries across this app's media
    print("\nTotal referenced file entries across this app's media:")
    agg = list(db.media.aggregate([
        {"$match": {"applicationName": app}},
        {"$project": {"n": {"$size": {"$ifNull": ["$files", []]}}}},
        {"$group": {"_id": None, "total": {"$sum": "$n"}}},
    ]))
    print("  file references   :", f"{(agg[0]['total'] if agg else 0):,}")

    # If files store DBRefs, resolve one to a real mediaFile doc
    files = (sample or {}).get("files") or []
    if files:
        print("\nSample 'files[0]' entry (raw):")
        print(json.dumps(files[0], indent=2, default=jdefault))
        ref = files[0]
        ref_id = ref.get("$id") if isinstance(ref, dict) else ref
        if ref_id is not None:
            mf = db.mediaFile.find_one({"_id": ref_id})
            print("\nResolved mediaFile doc:")
            print(json.dumps(mf, indent=2, default=jdefault) if mf else "  (not found)")

    print("\nDone (read-only).")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("ERROR:", type(e).__name__, e, file=sys.stderr)
        sys.exit(1)
