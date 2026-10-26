"""Compare mediaInfo between the legacy server and a new instance for random imported media.

Read-only on both sides. Keys come from Mongo, are used in-process and never printed.

Usage: ../.venv/bin/python parity_check.py --new http://127.0.0.1:8941 [--per-app 25]
"""
import argparse
import random
import ssl
import urllib.error
import urllib.parse
import urllib.request

from _conn import get_client

LEGACY = "https://phantasos.mesd.k12.or.us"
CTX = ssl.create_default_context()


def fetch(base, path, params, key):
    url = f"{base}{path}?{urllib.parse.urlencode({**params, 'apikey': key})}"
    try:
        with urllib.request.urlopen(url, context=CTX if url.startswith("https") else None, timeout=60) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--new", required=True)
    ap.add_argument("--per-app", type=int, default=25)
    args = ap.parse_args()

    client, dbname = get_client()
    db = client[dbname]
    failures = 0
    for user in db.apiUsers.find({"active": True}):
        app, key = user["applicationName"], user["apiKey"]
        ids = [m["_id"] for m in db.media.find({"applicationName": app, "files.0": {"$exists": True}}, {"_id": 1})]
        sample = random.sample(ids, min(args.per_app, len(ids)))
        same = 0
        for mid in sample:
            a = fetch(LEGACY, "/api/mediaInfo", {"media_id": str(mid)}, key)
            b = fetch(args.new, "/api/mediaInfo", {"media_id": str(mid)}, key)
            if a == b:
                same += 1
            else:
                failures += 1
                print(f"  DIFF {app} {mid}: legacy {a[0]} {a[1][:200]!r}\n{'':>30} new {b[0]} {b[1][:200]!r}")
        print(f"{app:24s} {same}/{len(sample)} identical")
    raise SystemExit(1 if failures else 0)


if __name__ == "__main__":
    main()
