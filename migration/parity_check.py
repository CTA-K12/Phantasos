"""Compare mediaInfo (and optionally requestMedia) between the legacy server and a new instance
for random imported media.

Read-only on both sides. Keys come from Mongo, are used in-process and never printed.

mediaInfo is compared byte for byte, except `owner`: the new system deliberately reports the
current client name (RENAMES in import_to_pg.py, e.g. Events → HealthEdTrainingsMESD).

--files N also downloads one file of N sampled media per app from both servers and compares the
status, Content-Type, Content-Length, Content-Disposition and the first 64 KB of the body.
(Cache-Control differs on purpose: the new system sends `private`.)

Usage: ../.venv/bin/python parity_check.py --new https://phantasos.apps.cascadetech.org [--per-app 25] [--files 5]
"""
import argparse
import hashlib
import json
import random
import ssl
import urllib.error
import urllib.parse
import urllib.request

from _conn import get_client
from import_to_pg import RENAMES

LEGACY = "https://phantasos.mesd.k12.or.us"
CTX = ssl.create_default_context()


def fetch(base, path, params, key):
    url = f"{base}{path}?{urllib.parse.urlencode({**params, 'apikey': key})}"
    try:
        with urllib.request.urlopen(url, context=CTX if url.startswith("https") else None, timeout=60) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def fetch_file(base, file_id, key):
    """(status, selected headers, sha256 of the first 64 KB) without downloading the whole file."""
    url = f"{base}/api/requestMedia?{urllib.parse.urlencode({'media_file_id': file_id, 'apikey': key})}"
    try:
        r = urllib.request.urlopen(url, context=CTX if url.startswith("https") else None, timeout=60)
    except urllib.error.HTTPError as e:
        r = e
    with r:
        head = r.read(65536)
        hdrs = {h: r.headers.get(h) for h in ("Content-Type", "Content-Length", "Content-Disposition")}
        return r.status if hasattr(r, "status") else r.code, hdrs, hashlib.sha256(head).hexdigest()[:16]


def normalise_owner(body, legacy_name):
    """Legacy reports the old client name; the new system the current one (deliberate)."""
    new_name = RENAMES.get(legacy_name, legacy_name)
    old = b'{"owner":' + json.dumps(legacy_name).encode()
    return body.replace(old, b'{"owner":' + json.dumps(new_name).encode(), 1) if body.startswith(old) else body


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--new", required=True)
    ap.add_argument("--per-app", type=int, default=25)
    ap.add_argument("--files", type=int, default=0, help="also compare one download for N media per app")
    args = ap.parse_args()

    client, dbname = get_client()
    db = client[dbname]
    failures = 0
    for user in db.apiUsers.find({"active": True}):
        app, key = user["applicationName"], user["apiKey"]
        ids = [m["_id"] for m in db.media.find({"applicationName": app, "files.0": {"$exists": True}}, {"_id": 1})]
        sample = random.sample(ids, min(args.per_app, len(ids)))
        same = files_same = files_checked = 0
        for n, mid in enumerate(sample):
            a = fetch(LEGACY, "/api/mediaInfo", {"media_id": str(mid)}, key)
            b = fetch(args.new, "/api/mediaInfo", {"media_id": str(mid)}, key)
            a = (a[0], normalise_owner(a[1], app))
            if a == b:
                same += 1
            else:
                failures += 1
                print(f"  DIFF {app} {mid}: legacy {a[0]} {a[1][:200]!r}\n{'':>30} new {b[0]} {b[1][:200]!r}")
                continue
            if n < args.files and a[0] == 200:
                files = json.loads(a[1]).get("files") or []
                if files:
                    f = random.choice(files)
                    fa = fetch_file(LEGACY, f["media_file_id"], key)
                    fb = fetch_file(args.new, f["media_file_id"], key)
                    files_checked += 1
                    if fa == fb:
                        files_same += 1
                    else:
                        failures += 1
                        print(f"  FILE DIFF {app} {mid} {f['file_name']}:\n    legacy {fa}\n    new    {fb}")
        line = f"{app:24s} mediaInfo {same}/{len(sample)} identical"
        if args.files:
            line += f", downloads {files_same}/{files_checked} identical"
        print(line)
    raise SystemExit(1 if failures else 0)


if __name__ == "__main__":
    main()
