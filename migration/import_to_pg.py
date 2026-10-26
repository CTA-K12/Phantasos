"""Import API clients, media and media files from legacy MongoDB into the new PostgreSQL schema.

Dry run by default: reads Mongo, builds the rows, reports what it would do. --apply writes.

Per app, in one transaction: upsert the api_client row, DELETE that client's media (files
cascade), then insert the current Mongo state. That makes every run a full, idempotent
refresh — re-run it as often as needed, and once more at cutover after the final rsync.
Anything written to the new system for an imported app (test uploads) is discarded.

What is imported:
  - every active key in RENAMES; the key itself is stored only as SHA-256 (never printed);
  - media that have at least one file. Never-uploaded tickets (no files) are skipped.
  - media IDs and media-file IDs unchanged (they are the public IDs and the disk layout);
  - file order as in Mongo (`position`), since consumers rely on it.

Timestamps: Mongo stores UTC; the app stores naive Pacific time (APP_TIMEZONE), so they
are converted here.

Usage (from migration/):
  ../.venv/bin/python import_to_pg.py --target local            # dry run against local dev DB
  ../.venv/bin/python import_to_pg.py --target local --apply
  ../.venv/bin/python import_to_pg.py --target prod --apply      # appdev-pg-prod (new-pg.env)
  ... --apps Training Events                                       # subset (legacy names)
"""
import argparse
import hashlib
import os
import re
import sys
import uuid
from datetime import timezone
from urllib.parse import unquote, urlsplit
from zoneinfo import ZoneInfo

import psycopg2
from psycopg2.extras import execute_values

from _conn import REPO_ROOT, get_client

# Legacy applicationName -> new client name.
RENAMES = {
    "Training": "Training",
    "Events": "HealthEdTrainingsMESD",
    "HealthedTrainingsNWRESD": "HealthedTrainingsNWRESD",
    "NurseDocsDev": "DocGuard",
    "SubDocs": "SubDocs",
}
APP_TZ = ZoneInfo("America/Los_Angeles")
OBJECT_ID = re.compile(r"^[0-9a-f]{24}$")


def local_naive(dt):
    """Mongo datetime (naive UTC) -> naive Pacific, matching how the app stores times."""
    if dt is None:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(APP_TZ).replace(tzinfo=None)


def uuid7_from(dt):
    """UUID v7 whose timestamp is `dt` (aware or naive-UTC), so ids sort by creation time."""
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    ms = int(dt.timestamp() * 1000)
    rand = int.from_bytes(os.urandom(10), "big")
    value = (ms & ((1 << 48) - 1)) << 80
    value |= 0x7 << 76
    value |= (rand >> 68 & 0xFFF) << 64
    value |= 0b10 << 62
    value |= rand & ((1 << 62) - 1)
    return uuid.UUID(int=value)


def read_env_value(path, key):
    with open(path) as fh:
        for line in fh:
            m = re.match(rf'^{key}="?(.*?)"?\s*$', line)
            if m:
                return m.group(1)
    raise SystemExit(f"{key} not found in {path}")


def pg_connect(target):
    if target == "prod":
        url = read_env_value(os.path.join(REPO_ROOT, "migration", "secrets", "new-pg.env"), "PG_URL")
    else:
        url = read_env_value(os.path.join(REPO_ROOT, ".env"), "DATABASE_URL")
    u = urlsplit(url)
    kwargs = dict(host=u.hostname, port=u.port or 5432, dbname=u.path.lstrip("/"),
                  user=unquote(u.username), password=unquote(u.password or ""),
                  application_name="phantasos-import")
    if target == "prod":
        # psycopg2-binary's bundled libpq can't read the macOS trust store, so
        # sslrootcert=system fails there; certifi's Mozilla bundle verifies the same chain.
        import certifi
        kwargs.update(sslmode="verify-full", sslrootcert=certifi.where(), gssencmode="disable")
    return psycopg2.connect(**kwargs)


def build_rows(db, legacy_name, client_id):
    """Return (media_rows, file_rows, stats) for one app from Mongo."""
    stats = {"media_total": 0, "skipped_no_files": 0, "dangling_file_refs": 0,
             "media": 0, "files": 0, "bad_ids": 0}
    media_rows, file_rows = [], []

    cursor = db.media.find({"applicationName": legacy_name})
    pending = []
    for m in cursor:
        stats["media_total"] += 1
        refs = [r.id for r in (m.get("files") or [])]
        if not refs:
            stats["skipped_no_files"] += 1
            continue
        pending.append((m, refs))

    all_ids = [fid for _, refs in pending for fid in refs]
    files_by_id = {}
    for i in range(0, len(all_ids), 5000):
        for f in db.mediaFile.find({"_id": {"$in": all_ids[i:i + 5000]}}):
            files_by_id[f["_id"]] = f

    for m, refs in pending:
        mid = str(m["_id"])
        if not OBJECT_ID.match(mid):
            stats["bad_ids"] += 1
            continue
        status = m.get("status") or "In queue"
        media_rows.append((
            mid, client_id, bool(m.get("hideToOthers", True)),
            psycopg2.extras.Json(list(m.get("tags") or [])),
            psycopg2.extras.Json(list(m.get("securityTags") or [])),
            bool(m.get("uploaded")), local_naive(m.get("uploadExpiration")) or local_naive(m["_id"].generation_time),
            m.get("mediaType"), m.get("callback") or None, bool(m.get("callbackSent")),
            bool(m.get("ready")), float(m.get("processingPercentage") or 0), status,
            local_naive(m["_id"].generation_time),
        ))
        stats["media"] += 1
        position = 0
        for fid in refs:
            f = files_by_id.get(fid)
            if f is None:
                stats["dangling_file_refs"] += 1
                continue
            file_rows.append((
                str(fid), mid, position, f["fileName"], bool(f.get("original")), f["contentType"],
                None if f.get("bitRate") is None else str(f["bitRate"]),
                f.get("width"), f.get("height"),
            ))
            position += 1
            stats["files"] += 1
    return media_rows, file_rows, stats


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--target", choices=["local", "prod"], required=True)
    ap.add_argument("--apps", nargs="*", default=sorted(RENAMES), help="legacy applicationNames")
    ap.add_argument("--apply", action="store_true", help="write (default: dry run)")
    args = ap.parse_args()

    unknown = set(args.apps) - set(RENAMES)
    if unknown:
        raise SystemExit(f"Not in RENAMES: {sorted(unknown)}")

    mclient, dbname = get_client(timeout_ms=10000)
    db = mclient[dbname]
    conn = pg_connect(args.target)
    conn.autocommit = False
    print(f"target={args.target} db={conn.get_dsn_parameters().get('dbname')} mode={'APPLY' if args.apply else 'dry-run'}")

    total = {"media": 0, "files": 0}
    for legacy in args.apps:
        user = db.apiUsers.find_one({"applicationName": legacy})
        if user is None:
            print(f"  {legacy}: no apiUsers record — skipped")
            continue
        new_name = RENAMES[legacy]
        key_hash = hashlib.sha256(user["apiKey"].encode()).hexdigest()

        with conn.cursor() as cur:
            cur.execute("SELECT id FROM api_client WHERE legacy_name = %s OR name = %s", (legacy, new_name))
            row = cur.fetchone()
            client_id = row[0] if row else str(uuid7_from(user["_id"].generation_time))
            cur.execute("""
                INSERT INTO api_client (id, name, legacy_name, key_hash, description, contact_name,
                                        contact_email, is_active, created_at, last_used_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, legacy_name = EXCLUDED.legacy_name,
                    key_hash = EXCLUDED.key_hash, description = EXCLUDED.description,
                    contact_name = EXCLUDED.contact_name, contact_email = EXCLUDED.contact_email,
                    is_active = EXCLUDED.is_active, last_used_at = GREATEST(api_client.last_used_at, EXCLUDED.last_used_at)
            """, (client_id, new_name, legacy, key_hash, user.get("applicationDescription") or None,
                  user.get("contactName") or None, user.get("contactEmail") or None, bool(user.get("active")),
                  local_naive(user["_id"].generation_time), local_naive(user.get("lastUse"))))

            media_rows, file_rows, stats = build_rows(db, legacy, client_id)

            cur.execute("SELECT count(*) FROM media WHERE client_id = %s", (client_id,))
            existing = cur.fetchone()[0]
            cur.execute("DELETE FROM media WHERE client_id = %s", (client_id,))
            execute_values(cur, """
                INSERT INTO media (id, client_id, hide_to_others, tags, security_tags, uploaded,
                    upload_expiration, media_type, callback, callback_sent, ready,
                    processing_percentage, status, created_at) VALUES %s
            """, media_rows, page_size=2000)
            execute_values(cur, """
                INSERT INTO media_file (id, media_id, position, file_name, original, content_type,
                    bit_rate, width, height) VALUES %s
            """, file_rows, page_size=2000)

            cur.execute("SELECT count(*) FROM media WHERE client_id = %s", (client_id,))
            n_media = cur.fetchone()[0]
            cur.execute("SELECT count(*) FROM media_file f JOIN media m ON m.id = f.media_id WHERE m.client_id = %s",
                        (client_id,))
            n_files = cur.fetchone()[0]

        ok = n_media == stats["media"] and n_files == stats["files"]
        print(f"  {legacy:24s} -> {new_name:24s} key#{key_hash[:12]}  "
              f"mongo={stats['media_total']:6d} skipped(no files)={stats['skipped_no_files']:6d}  "
              f"media {existing:6d} -> {n_media:6d}  files {n_files:6d}  "
              f"dangling={stats['dangling_file_refs']} bad_ids={stats['bad_ids']}  {'OK' if ok else 'MISMATCH'}")
        if not ok:
            conn.rollback()
            raise SystemExit(f"Row counts do not match for {legacy}; rolled back.")
        if args.apply:
            conn.commit()
        else:
            conn.rollback()
        total["media"] += n_media
        total["files"] += n_files

    print(f"total media={total['media']} files={total['files']} ({'committed' if args.apply else 'rolled back — dry run'})")
    conn.close()


if __name__ == "__main__":
    sys.exit(main())
