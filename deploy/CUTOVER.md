# Phantasos cutover runbook

Moves `phantasos.mesd.k12.or.us` from the legacy server (198.236.68.237) to the new host
(`phantasos.apps.cascadetech.org`, 198.236.76.21). Host prerequisites: `deploy/HOST-SETUP.md`.
Background: `AI.md` §13–14.

**Plan as of 2026-11-21: switch the apps, not DNS.** A DNS change can't be guaranteed on a weekend,
so each consumer is republished with `MEDIASERVER_HOST=https://phantasos.apps.cascadetech.org` (§2
step 5). Keys and media IDs stay the same, and the apps read the host at runtime, so nothing stored
needs rewriting. Pointing `phantasos.mesd.k12.or.us` at .21 follows later as a safety net (§4).

Expected downtime: about an hour, from stopping the old Apache until the apps are republished.
During that window uploads and downloads fail. Apps must not switch before the final import
(step 4): it replaces each client's media, so uploads made on the new host before it are lost.

## 1. Before cutover day

**Deploy and test on `phantasos.apps.cascadetech.org`.** Test writes are disposable: the final
re-import replaces each client's media rows, and the final sync with `--delete` removes their files.

- [ ] `bin/deploy.sh` (or `--load` while .21 can't reach Harbor). Its smoke tests must pass.
- [ ] Initial media copy is done (cluster side), and `migration/verify_media_copy.py` reports OK.
- [ ] Parity against legacy with real keys:
      `cd migration && ../.venv/bin/python parity_check.py --new https://phantasos.apps.cascadetech.org`
- [ ] Management site: sign in from the admin networks, check the dashboard, open a media item,
      and confirm a request from outside the allowlist gets 403.
- [ ] Each consumer team tests against the test name (an upload and a playback each):
      Online Training, HealthEd Trainings MESD and NWRESD, SubDocs (its callback arrives), DocGuard.
- [ ] Network-team ACL for inbound 443 is open, and the API answers from outside the network.
- [ ] Production Google OAuth redirect URI for `phantasos.mesd.k12.or.us` is registered.

**DNS (for the later change in §4, not needed on cutover day).** The name is split-horizon:

| View | Where | Today |
|---|---|---|
| Public | Route53 (`mesd.k12.or.us` zone) | A 198.236.68.237, TTL 3600 |
| Internal | MESD DNS (`dns1i`/`dns2i`) | A 198.236.68.237, TTL 86400 |

- [ ] Optional: lower both TTLs to 300 a day before the change, so a mistake is quick to undo.
- [ ] Confirm internal clients (SubDocs on `ortrac`, the HealthEd and Training servers) can reach
      198.236.76.21:443. The internet ACL doesn't cover internal paths.
- [ ] Tell the consumer teams the window.

## 2. Cutover

Have open: an SSH session to each host, `journalctl -fu phantasos-worker` on .21, and the
management dashboard.

1. **Stop new work on the old server:**
   ```
   ssh root@phantasos.mesd.k12.or.us systemctl stop httpd
   ```
2. **Let in-flight processing finish.** Wait until the RabbitMQ `process_media` queue on the legacy
   broker is empty and the appdev-video workers are idle. Media still mid-encode would be imported
   unfinished. Check for anything still processing:
   ```
   cd migration && ../.venv/bin/python -c "from _conn import get_client; c,d=get_client(); \
     print(c[d].media.count_documents({'ready': False, 'uploaded': True}))"
   ```
   A few long-stuck items are normal (they fail on the old server too); new ones should not appear.
3. **Final media sync** (cluster side): push with `--delete`, then verify:
   ```
   cd migration && ../.venv/bin/python verify_media_copy.py --no-old    # DB vs new host
   ```
   Run it without `--no-old` after the re-import (step 4) for the full old-vs-new size check.
4. **Final import** (full re-import of every client, one transaction each). It replaces the five
   legacy clients' media and resets their name, key, description, contact and active flag to the
   legacy values: test uploads, key rotations and edits made during testing are undone, which is
   intended. It does **not** touch clients created in the admin site during testing. Their rows
   would survive with their files gone, so delete them first (on appdev-pg-prod; media files cascade):
   ```sql
   DELETE FROM media      WHERE client_id IN (SELECT id FROM api_client WHERE legacy_name IS NULL);
   DELETE FROM api_client WHERE legacy_name IS NULL;
   ```
   Then:
   ```
   cd migration && ../.venv/bin/python import_to_pg.py --target prod            # dry run: check counts
   cd migration && ../.venv/bin/python import_to_pg.py --target prod --apply
   cd migration && ../.venv/bin/python verify_media_copy.py                     # must say OK
   ```
5. **Switch the apps:** set `MEDIASERVER_HOST: "https://phantasos.apps.cascadetech.org"` in each
   php-env configmap, apply it, and restart the php pods. All API calls come from the app pods
   (old kube nodes 198.236.68.83/.84), never from browsers, apart from uploads to the
   `upload_route` the ticket returns, which already names the new host.
   - training-api: `training-api`, `training-demo-api`
   - healthed-api: `healthed-api`, `healthed-nwresd-api`, `healthed-demo-api`
   - healthed-training: `healthed-api`, `healthed-nwresd-api`, `healthed-demo-api`, `healthed-qa`,
     `healthed-nwresd-qa` (whichever are deployed)
   - SubDocs (config not in this workspace)
   - DocGuard (`docguard-api-php-env`): it's off and only runs on demand, but switch it now so it
     works the next time it's turned on.
6. **Check:** each app's API traffic shows up in `journalctl -u phantasos-web` on .21, and its
   upload and playback work.

**Go/no-go:** if steps 3–4 don't verify, start the old Apache again (`systemctl start httpd`)
before switching any app. Nothing has changed for consumers yet.

## 3. First hour

- [ ] Traffic arrives from each consumer: dashboard "downloads per client", and `media.served`
      in the audit log.
- [ ] A new upload from each app reaches **Ready**. The worker log shows each job, and the
      Processing page has no failures.
- [ ] SubDocs callbacks go out (worker log: callback to `ortrac.mesd.k12.or.us`).
- [ ] No 5xx in `journalctl -u phantasos-web`, and no PHP errors in `journalctl -u phantasos-app`.
- [ ] Watch for 403s from consumers. That would mean a key-mapping problem in the import.

**Rollback (first hour only):** set the apps' `MEDIASERVER_HOST` back and start the old Apache. Anything uploaded to the new host after the flip is not on the old server and is lost to
consumers, so roll back only for a fault that blocks consumers.

## 4. After

- [ ] Point `phantasos.mesd.k12.or.us` at 198.236.76.21 in both Route53 and the internal MESD DNS,
      so anything still using the old name keeps working. The certificate already covers it.
- [ ] Remove the push key from .21 (`authorized_keys`). It lives on the vulnerable server.
- [ ] Keep the old server powered off but intact for 30 days, then decommission it, along with the
      MD3200i, appdev-video1/2/3 and RabbitMQ.
- [ ] Remove the legacy Mongo and RabbitMQ credentials (`migration/secrets/legacy-parameters.yml`).
