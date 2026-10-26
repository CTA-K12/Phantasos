"""Shared MongoDB connection helper for Phantasos migration tooling.

Reads connection settings from migration/secrets/legacy-parameters.yml so no secrets live in
these scripts. Read-only by default; nothing here writes or deletes.

Server is MongoDB 3.0.2 (2015), so we rely on pymongo 3.x (in ../.venv).
"""
import os
import sys
import yaml
from pymongo import MongoClient

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# Legacy prod connection settings (gitignored; copied from the old server).
PARAMS_PATH = os.path.join(REPO_ROOT, "migration", "secrets", "legacy-parameters.yml")


def load_params():
    with open(PARAMS_PATH) as fh:
        data = yaml.safe_load(fh)
    return data["parameters"]


def get_client(timeout_ms=5000):
    """Return (client, dbname). Auth against the app's database (authSource)."""
    p = load_params()
    host = p["mongodb_host"]
    port = int(p["mongodb_port"])
    user = p["mongodb_username"]
    pwd = p["mongodb_password"]
    dbname = p["mongodb_database"]

    client = MongoClient(
        host=host,
        port=port,
        username=user,
        password=pwd,
        authSource=dbname,          # app authenticates against its own db
        serverSelectionTimeoutMS=timeout_ms,
        connectTimeoutMS=timeout_ms,
        socketTimeoutMS=20000,      # fail fast instead of hanging on a dead socket
        connect=True,
    )
    # Force a round-trip so connection/auth errors surface immediately.
    client.admin.command("ping") if False else client[dbname].command("ping")
    return client, dbname


if __name__ == "__main__":
    try:
        client, dbname = get_client()
        info = client.server_info()
        print("Connected OK. MongoDB server version:", info.get("version"))
        print("Database:", dbname)
    except Exception as e:
        print("CONNECTION FAILED:", type(e).__name__, e, file=sys.stderr)
        sys.exit(1)
