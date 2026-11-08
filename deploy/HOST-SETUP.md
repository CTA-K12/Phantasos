# Phantasos host setup — phantasos.apps.cascadetech.org (198.236.76.21)

Handoff for the host and infrastructure side. Covers everything the host must provide before the
first deploy. The application side (images, Quadlet units, data copy, cutover) is in this repo;
background is in `AI.md` §13–14.

## Status (2026-11-07)

The host side is built (k8s-cluster repo, `docs/07`, role `phantasos_host`). As built:
- **`/media`:** LUKS, opened and mounted by `luks-unlock-media.service`. `/media/phantasos`
  (0755) and `/media/.upload-tmp` (0700) are owned by 65534.
- **SELinux:** a persistent fcontext rule labels `/media` and `/etc/phantasos/tls` as
  `container_file_t`.
- **TLS:** the certificate for both names is in `/etc/phantasos/tls/`. The certbot deploy hook
  copies renewals there and runs `podman exec phantasos-web nginx -s reload`.
- **Unit drop-in:** the host adds `10-host.conf` to the `phantasos-app`, `-worker` and `-web`
  services with `RequiresMountsFor=/media`, `After=luks-unlock-media.service` and
  `REGISTRY_AUTH_FILE=/etc/containers/auth.json`. The units in `deploy/quadlet/` don't repeat
  these.
- **Firewall:** 443 only. The web unit publishes 443 only (§5).
- **Database:** the `phantasos` database and role exist on appdev-pg-prod. Verified from .21 on
  2026-11-07: TLS 1.3 with the certificate verified against the system CAs, `pg_hba` accepts .21,
  and the SCRAM login works. The app image connects with `sslmode=verify-full&sslrootcert=system`.

Still open:
- [ ] **Network-team ACL: .21 → Harbor (.6:443).** Requested 2026-10-27; still timing out as of
      2026-11-07. This blocks the Harbor robot login (`/etc/containers/auth.json`) and the first
      deploy.
- [ ] **Network-team ACL: inbound 443 from the internet to .21**, needed before testing.
- [ ] `/etc/phantasos/phantasos.env`: placed by hand from `deploy/phantasos.env.example`.
- [ ] Media copy (§6), which waits on `/media` backups and auditd.
- [ ] `/media` backups (§7), auditd, monitoring (§8).

## What runs here

| Container | Image (Harbor `appdev-phi`) | Runs as | Role |
|---|---|---|---|
| `phantasos-web` | `appdev-registry.apps.cascadetech.org/appdev-phi/phantasos-web:<tag>` | uid 100 / gid 101 (`nginx`) | TLS on 443, static files, file delivery |
| `phantasos-app` | `…/appdev-phi/phantasos-app:<tag>` | uid 65534 (`nobody`) | PHP-FPM: the API and the management site |
| `phantasos-worker` | same image as app | uid 65534 | Messenger worker: ffmpeg/GD processing, callbacks |

- **Runtime:** rootful Podman with Quadlet. The units are in `deploy/quadlet/`
  (`phantasos.network`, `phantasos-{app,worker,web}.container`) and are installed to
  `/etc/containers/systemd/`.
- **Database:** `phantasos` on appdev-pg-prod; it already exists, with the schema and data loaded.
- **Not on the host:** nothing else (no PHP, no nginx, no cron). All config comes from the
  three host paths below.

## 1. Storage: `/media` (LUKS)

The ~419 GiB volume, currently plain XFS. It must be encrypted while it is still empty: it will
hold student health information (DocGuard now, ORMed later).

1. **Encrypt:** LUKS2 on the volume, XFS on top, mounted at `/media`.
   - It must unlock unattended at boot (clevis + TPM2, or Tang, or your standard), and the key must
     not sit on the same disk in the clear.
   - Keep a recovery key or passphrase in Vault.
2. **Create the directories:**
   ```
   install -d -o 65534 -g 65534 -m 0755 /media/phantasos      # media store (app + worker write; nginx reads)
   install -d -o 65534 -g 65534 -m 0700 /media/.upload-tmp    # PHP upload temp, kept on the encrypted volume
   ```
3. **SELinux:** label the volume once, with a persistent rule.
   ```
   semanage fcontext -a -t container_file_t '/media(/.*)?'
   restorecon -R /media
   ```
   **Never use `:Z`/`:z` on these volumes.** The units deliberately don't, because relabelling
   100+ GB on every start is slow and pointless.
4. **Sizing:** about 113 GB at cutover, expected to grow towards about 1 TB with ORMed.

## 2. Podman and Quadlet

- Install `podman` (Rocky 10 includes the Quadlet generator).
- **Harbor robot account** with **pull** on `appdev-phi`, logged in as root so systemd can pull:
  `podman login --authfile /etc/containers/auth.json appdev-registry.apps.cascadetech.org`
  (the file must be 0600).
- The units are installed during the first deploy; nothing to do here yet.

## 3. TLS certificate

- **One Let's Encrypt certificate** (Route53 DNS-01) covering both names:
  - `phantasos.apps.cascadetech.org` — the test name, used now;
  - `phantasos.mesd.k12.or.us` — the production name, after the DNS flip.
- **Confirm that Route53 hosts `mesd.k12.or.us`.** If it doesn't, that name needs an
  `_acme-challenge` CNAME delegation into a zone you control.
- **Where to put it:**
  ```
  /etc/phantasos/tls/fullchain.pem   root:101 0644
  /etc/phantasos/tls/privkey.pem     root:101 0640   # gid 101 = nginx inside the container
  semanage fcontext -a -t container_file_t '/etc/phantasos/tls(/.*)?' && restorecon -R /etc/phantasos/tls
  ```
- **Renewal deploy hook:** copy the files into place, then run
  `podman exec phantasos-web nginx -s reload`.
- nginx will not start without both files, so they must exist before the first deploy.

## 4. Runtime configuration (secrets)

`/etc/phantasos/phantasos.env`, `root:root`, mode `0600`. It is read by Podman and not mounted
into the containers. Template: `deploy/phantasos.env.example`.

| Variable | Value |
|---|---|
| `APP_SECRET` | new: `openssl rand -hex 32` |
| `DATABASE_URL` | `postgresql://phantasos:<password>@appdev-pg-prod.apps.cascadetech.org:5432/phantasos?serverVersion=17&charset=utf8&sslmode=verify-full&sslrootcert=system&gssencmode=disable&application_name=phantasos` — the existing `phantasos` role |
| `PGCONNECT_TIMEOUT` | `10` |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | the **production** OAuth client for the management site (see below) |
| `FPM_MAX_CHILDREN` | `8` (each worker holds one connection on appdev-pg-prod) |

**Google OAuth client.** Use a new client, or the existing one with these redirect URIs added:
- `https://phantasos.apps.cascadetech.org/connect/google/check`
- `https://phantasos.mesd.k12.or.us/connect/google/check`

Sign-in is restricted to pre-created users. The four CTA admins already exist in the database.

## 5. Network

**Inbound to .21:**
- **443/tcp from the internet.** This needs the **network-team ACL** to open 443 to .21.
- **No port 80** (changed 2026-11-07). The web unit publishes 443 only, because
  podman-published ports can bypass firewalld. The old server's port-80 logs from 2026-10-04 to
  2026-11-07 contain no API calls, only scanners, so no consumer depends on plain HTTP.
- firewalld: `https` only. SSH per your standard.
- **Do not put a proxy or load balancer in front.** nginx's management-site allowlist relies on
  seeing real client IPs. The allowlist is the admin tier, baked into the image
  (`.docker/nginx/admin-access.conf`). Deliberately excluded: 198.236.76.0/28, the PG subnet,
  10.5.0.0/16 and the Support-team VPN.

**Outbound from .21:**

| Destination | Port | Why |
|---|---|---|
| appdev-pg-prod (198.236.76.20) | 5432 | database. **`pg_hba` must allow `hostssl phantasos phantasos 198.236.76.21/32 scram-sha-256`.** |
| `appdev-registry.apps.cascadetech.org` | 443 | image pulls |
| `accounts.google.com`, `oauth2.googleapis.com`, `www.googleapis.com` | 443 | management-site sign-in |
| `ortrac.mesd.k12.or.us` | **80** | SubDocs "media ready" callbacks: legacy, plain HTTP, the URLs are stored without a scheme |
| Route53 / Let's Encrypt | 443 | certificate issuance and renewal (if certbot runs on this host) |

## 6. Data copy (owned by the k8s-cluster side)

The media store (about 113 GB) is **pushed from the old server** to .21. The k8s-cluster side owns
the copy and starts it only once the `/media` backups and auditd are in place. What the app side
needs from it:
- files land under `/media/phantasos/` with the same relative paths as on the old server
  (`abcde/fghij/klmno/<media id>/…`), owned by `65534:65534`, inheriting `container_file_t`;
- an initial copy while the old server is live, then a **final sync with `--delete`** during the
  cutover window, after the old Apache is stopped (see "After the host is ready" below);
- a file count and total size from both ends after each pass, to check against the import
  (43,350 media files at the last rehearsal).

Checked 2026-11-07:
- **rsync is not installed on .21.** A push needs rsync on both ends; the old server has 3.1.2.
- Old server → .21:22 is open. .21 → old server:22 is blocked, which is why the copy is a push.
- Space: 113 GB on the old volume, 412 GB free on `/media` on .21.
- On the old server the files are `apache:apache`, dirs 0775. On .21 they must be `65534:65534`,
  dirs 0755 and files 0644: use `--chown=65534:65534 --chmod=D0755,F0644`, or `chown -R` afterwards.

**The push key sits on the vulnerable server**, so restrict it on .21. Use a dedicated key, with an
`authorized_keys` entry of the form
`from="<old server IP>",restrict,command="rrsync -wo /media/phantasos" ssh-ed25519 …`, and
remove the key after cutover.

## 7. Backups

- **`/media`:** nightly backup, encrypted, off-host, with your usual retention (restic, borg or
  your standard to the offsite S3). This is the only copy of the files once the old server is
  retired.
- **The database** is already covered by pgBackRest on appdev-pg-prod.
- **A restore test** before ORMed onboarding (HIPAA contingency plan).

## 8. Monitoring

- Node exporter on .21 in the Prometheus targets, with alerts on:
  - `/media` usage over 80%;
  - certificate expiry under 14 days;
  - any of `phantasos-web.service`, `phantasos-app.service` or `phantasos-worker.service` not
    active.
- Logs go to journald (`journalctl -u phantasos-worker`). Keep at least 30 days.

## 9. Report back when done

- [x] `/media` is LUKS-encrypted, unlocks unattended at reboot (test one), and its recovery key is in Vault
- [x] `/media/phantasos` and `/media/.upload-tmp` exist with the ownership and modes above, labelled `container_file_t`
- [ ] Podman is installed; `podman pull` of an `appdev-phi` image works as root (blocked: Harbor ACL)
- [x] The certificate for both names is in `/etc/phantasos/tls/`, and the renewal hook is installed
- [ ] `/etc/phantasos/phantasos.env` is in place (0600)
- [ ] The production OAuth redirect URIs are registered
- [x] `pg_hba` allows .21 (verified 2026-11-07). Test from .21:
      `psql 'host=appdev-pg-prod.apps.cascadetech.org dbname=phantasos user=phantasos sslmode=verify-full sslrootcert=system' -c 'select 1'`
- [ ] Outbound to Harbor, Google and `ortrac.mesd.k12.or.us:80` works
- [ ] The network-team ACL for inbound 443 is requested (it can go live just before testing)
- [ ] The `/media` backup job and monitoring targets are set up

## Open questions

1. **VPN pool sizes:** is the "CTA MESD Outside" pool really 10.0.207.0/24? If it is wider, some
   admins will get intermittent 403s from the allowlist.
2. **Route53 and `mesd.k12.or.us`:** is the zone in Route53, or is a CNAME delegation needed for
   the DNS-01 challenge?
3. **LUKS unlock method:** TPM2 or Tang? Record it in the cluster `AI.md` next to the other hosts.

## After the host is ready (app side, for reference)

`bin/deploy.sh` does steps 1–2; `deploy/CUTOVER.md` is the full runbook for steps 3–4.

1. Build and push `phantasos-app` and `phantasos-web` to Harbor, set the tag in the units, and copy
   them to `/etc/containers/systemd/`.
2. Run `systemctl daemon-reload && systemctl start phantasos-web` (which brings up app and worker),
   then `podman exec phantasos-app php bin/console doctrine:migrations:migrate -n` (a no-op today).
3. Copy `/media/phantasos` (§6, pushed from the old server), re-import, run the parity check against
   `https://phantasos.apps.cascadetech.org`, and have each consumer team test.
4. Cutover: stop the old Apache, run the final push sync with `--delete` (§6), re-import, flip DNS for
   `phantasos.mesd.k12.or.us` to .21, and monitor for 1 h.
