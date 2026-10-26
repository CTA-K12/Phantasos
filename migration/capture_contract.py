"""Record real responses from the legacy API as contract fixtures for the rebuild.

Read-only: only GET/HEAD/OPTIONS requests, plus POSTs to uploadMedia with tickets that
are rejected before anything is written (nonexistent, expired, or already used).

API keys are read from Mongo and used in-process; they are never printed or written.
Fixtures record the request with the key replaced by `<key:AppName>`.

requestMedia uses HEAD (headers only) so no document contents are downloaded; one
GET on a Training image confirms the body is the raw file.

Usage: ../.venv/bin/python capture_contract.py  →  tests/Contract/fixtures/legacy/*.json
"""
import hashlib
import json
import os
import ssl
import urllib.error
import urllib.parse
import urllib.request

from _conn import REPO_ROOT, get_client

BASE = "https://phantasos.mesd.k12.or.us"
OUT = os.path.join(REPO_ROOT, "tests", "Contract", "fixtures", "legacy")
KEEP_HEADERS = {"content-type", "content-length", "content-disposition", "accept-ranges",
                "cache-control", "last-modified", "etag", "allow"}
CTX = ssl.create_default_context()


def call(method, path, params=None, key=None, data=None, headers=None):
    q = dict(params or {})
    if key is not None:
        q["apikey"] = key
    url = BASE + path + ("?" + urllib.parse.urlencode(q) if q else "")
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, method=method, headers=headers or {})
    try:
        resp = urllib.request.urlopen(req, context=CTX, timeout=60)
        status, hdrs, raw = resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as e:
        status, hdrs, raw = e.code, e.headers, e.read()
    out = {
        "status": status,
        "headers": {k.lower(): v for k, v in hdrs.items()
                    if k.lower() in KEEP_HEADERS or k.lower().startswith("access-control")},
    }
    ctype = hdrs.get("Content-Type", "")
    if "json" in ctype:
        out["json"] = json.loads(raw)
        out["raw"] = raw.decode()
    elif method == "HEAD":
        pass
    elif ctype.startswith("text/") or len(raw) < 512:
        out["text"] = raw.decode(errors="replace")
    else:
        out["body_sha256"] = hashlib.sha256(raw).hexdigest()
        out["body_length"] = len(raw)
    return out


def save(name, request, response):
    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, name + ".json"), "w") as fh:
        json.dump({"request": request, "response": response}, fh, indent=2, sort_keys=True)
    print(f"{name:55s} {response['status']}")


def main():
    client, dbname = get_client()
    db = client[dbname]
    keys = {u["applicationName"]: u["apiKey"] for u in db.apiUsers.find({"active": True})}
    apps = sorted(keys)

    # One ready media per (app, type), newest first, plus one never-uploaded ticket per app.
    samples = []
    for app in apps:
        for mtype in db.media.distinct("mediaType", {"applicationName": app, "ready": True}):
            m = db.media.find_one({"applicationName": app, "mediaType": mtype, "ready": True},
                                  sort=[("_id", -1)])
            samples.append((app, mtype, m))
        t = db.media.find_one({"applicationName": app, "uploaded": False}, sort=[("_id", -1)])
        if t:
            samples.append((app, "ticket", t))

    for app, mtype, m in samples:
        mid = str(m["_id"])
        req = {"method": "GET", "path": "/api/mediaInfo", "params": {"media_id": mid}, "key": f"<key:{app}>"}
        save(f"mediaInfo_{app}_{mtype}", req, call("GET", "/api/mediaInfo", {"media_id": mid}, keys[app]))
        for ref in (m.get("files") or []):
            fid = str(ref.id)
            req = {"method": "HEAD", "path": "/api/requestMedia", "params": {"media_file_id": fid}, "key": f"<key:{app}>"}
            mf = db.mediaFile.find_one({"_id": ref.id}, {"fileName": 1})
            stem = mf["fileName"].replace(".", "_") if mf else fid
            save(f"requestMedia_{app}_{mtype}_{stem}", req,
                 call("HEAD", "/api/requestMedia", {"media_file_id": fid}, keys[app]))

    # Body check on a non-sensitive file.
    tr = next((m for app, t, m in samples if app == "Training" and t == "image"), None)
    if tr:
        fid = str(tr["files"][-1].id)
        save("requestMedia_Training_image_GET", {"method": "GET", "path": "/api/requestMedia",
             "params": {"media_file_id": fid}, "key": "<key:Training>"},
             call("GET", "/api/requestMedia", {"media_file_id": fid}, keys["Training"]))

    # Error and edge cases.
    k = keys["Training"]
    other = next(m for app, t, m in samples if app == "Events" and t != "ticket")
    missing = "0" * 24
    cases = [
        ("mediaInfo_missing_param", "GET", "/api/mediaInfo", {}, k),
        ("mediaInfo_nonexistent", "GET", "/api/mediaInfo", {"media_id": missing}, k),
        ("mediaInfo_malformed_id", "GET", "/api/mediaInfo", {"media_id": "zzz"}, k),
        ("mediaInfo_other_app", "GET", "/api/mediaInfo", {"media_id": str(other["_id"])}, k),
        ("mediaInfo_no_key", "GET", "/api/mediaInfo", {"media_id": missing}, None),
        ("mediaInfo_bad_key", "GET", "/api/mediaInfo", {"media_id": missing}, "not-a-real-key"),
        ("requestMedia_missing_param", "GET", "/api/requestMedia", {}, k),
        ("requestMedia_nonexistent", "HEAD", "/api/requestMedia", {"media_file_id": missing}, k),
        ("requestMedia_other_app", "HEAD", "/api/requestMedia", {"media_file_id": str(other["files"][0].id)}, k),
        ("requestUploadTicket_GET", "GET", "/api/requestUploadTicket", {}, k),
        ("mediaInfo_POST", "POST", "/api/mediaInfo", {}, k),
    ]
    for name, method, path, params, key in cases:
        req = {"method": method, "path": path, "params": params,
               "key": None if key is None else ("<key:Training>" if key == k else "<invalid>")}
        save(name, req, call(method, path, params, key, data={} if method == "POST" else None))

    # uploadMedia rejections (no key on this route; nothing is written).
    used = str(other["_id"])
    expired = next(m for app, t, m in samples if t == "ticket")
    up = [
        ("uploadMedia_OPTIONS", "OPTIONS", missing),
        ("uploadMedia_nonexistent", "POST", missing),
        ("uploadMedia_used", "POST", used),
        ("uploadMedia_expired", "POST", str(expired["_id"])),
        ("uploadMedia_GET", "GET", missing),
    ]
    for name, method, tid in up:
        path = f"/api/uploadMedia/{tid}"
        save(name, {"method": method, "path": path, "params": {}, "key": None},
             call(method, path, data={} if method == "POST" else None,
                  headers={"Origin": "https://example.org"}))


if __name__ == "__main__":
    main()
