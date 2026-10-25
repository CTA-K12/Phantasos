"""Read-only survey of the Phantasos MongoDB.

Prints: server version, db stats, collection counts, per-application media
breakdown, and the apiUsers (consuming apps) list. Used to plan the migration
and the decommissioned-app purge. Writes/deletes nothing.

Usage:  ../.venv/bin/python inspect_db.py
"""
import sys
from collections import OrderedDict
from _conn import get_client


def human(n):
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if abs(n) < 1024.0:
            return f"{n:3.1f} {unit}"
        n /= 1024.0
    return f"{n:.1f} PB"


def main():
    client, dbname = get_client()
    db = client[dbname]

    print("=" * 70)
    print("PHANTASOS MONGODB SURVEY")
    print("=" * 70)
    print("Server version :", client.server_info().get("version"))
    print("Database       :", dbname)

    stats = db.command("dbstats")
    print("Data size      :", human(stats.get("dataSize", 0)))
    print("Storage size   :", human(stats.get("storageSize", 0)))
    print("Index size     :", human(stats.get("indexSize", 0)))
    print("Collections    :", stats.get("collections"))

    print("\n--- Collection counts ---")
    for coll in sorted(db.list_collection_names()):
        print(f"  {coll:20s} {db[coll].count_documents({}):>12,}")

    # Per-application media breakdown
    print("\n--- media by applicationName ---")
    pipeline = [
        {"$group": {
            "_id": "$applicationName",
            "count": {"$sum": 1},
            "ready": {"$sum": {"$cond": ["$ready", 1, 0]}},
        }},
        {"$sort": {"count": -1}},
    ]
    total = 0
    for row in db.media.aggregate(pipeline):
        name = row["_id"] if row["_id"] is not None else "(none)"
        total += row["count"]
        print(f"  {name:30s} media={row['count']:>8,}  ready={row['ready']:>8,}")
    print(f"  {'TOTAL':30s} media={total:>8,}")

    # media by type (helps size the transcode/migration effort)
    print("\n--- media by mediaType ---")
    for row in db.media.aggregate([
        {"$group": {"_id": "$mediaType", "count": {"$sum": 1}}},
        {"$sort": {"count": -1}},
    ]):
        print(f"  {str(row['_id']):20s} {row['count']:>10,}")

    # Consuming apps
    print("\n--- apiUsers (consuming apps) ---")
    for u in db.apiUsers.find({}, {
        "applicationName": 1, "active": 1, "lastUse": 1,
        "contactEmail": 1, "applicationUri": 1,
    }).sort("applicationName", 1):
        print(f"  {str(u.get('applicationName')):30s} "
              f"active={str(u.get('active')):5s} "
              f"lastUse={str(u.get('lastUse'))[:19]:19s} "
              f"{u.get('contactEmail') or ''}")

    print("\nDone (read-only).")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("ERROR:", type(e).__name__, e, file=sys.stderr)
        sys.exit(1)
