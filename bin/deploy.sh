#!/usr/bin/env bash
# deploy.sh — Build, ship and roll out Phantasos to phantasos.apps.cascadetech.org (.21).
#
# Usage:
#   bin/deploy.sh                    # auto-increment patch (v1.0.3 → v1.0.4), push via Harbor
#   bin/deploy.sh --version 1.1.0    # explicit version
#   bin/deploy.sh --load             # ship images over SSH (docker save | podman load), no Harbor
#   bin/deploy.sh --rollback 1.0.2   # no build: point the units at an image already on the host
#   bin/deploy.sh --dry-run          # print what would happen; change nothing
#   bin/deploy.sh --clean            # fresh base image, no layer cache (security refresh)
#   bin/deploy.sh --allow-dirty      # deploy uncommitted code (the version is marked -dirty)
#
# Version source of truth: the image tag in deploy/quadlet/phantasos-app.container. The script
# rewrites the Image= lines in the units; commit them afterwards so git records what is running.
#
# Host prerequisites (deploy/HOST-SETUP.md): /etc/phantasos/phantasos.env (0600), the TLS files,
# /media mounted, and for Harbor mode /etc/containers/auth.json.

set -euo pipefail
cd "$(dirname "$0")/.."

# ── Config ────────────────────────────────────────────────────────────────────

HOST="${PHANTASOS_HOST:-root@phantasos.apps.cascadetech.org}"
SITE="phantasos.apps.cascadetech.org"
REGISTRY="appdev-registry.apps.cascadetech.org/appdev-phi"
APP_IMAGE="${REGISTRY}/phantasos-app"
WEB_IMAGE="${REGISTRY}/phantasos-web"
UNITS_DIR="deploy/quadlet"
VERSION_FILE="${UNITS_DIR}/phantasos-app.container"
SSH=(ssh -o BatchMode=yes -o LogLevel=ERROR "$HOST")

# ── Flags ─────────────────────────────────────────────────────────────────────

VERSION_OVERRIDE=""; ROLLBACK=""; MODE="harbor"; DRY_RUN=false; NO_CACHE=""; ALLOW_DIRTY=false
while [[ $# -gt 0 ]]; do
    case $1 in
        --version|-v)  VERSION_OVERRIDE="${2#v}"; shift 2 ;;
        --rollback)    ROLLBACK="${2#v}"; shift 2 ;;
        --load)        MODE="load"; shift ;;
        --dry-run|-n)  DRY_RUN=true; shift ;;
        --clean)       NO_CACHE="--pull --no-cache"; shift ;;
        --allow-dirty) ALLOW_DIRTY=true; shift ;;
        -h|--help)     sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)             echo "Unknown argument: $1  (use --help)"; exit 1 ;;
    esac
done

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; BOLD='\033[1m'; NC='\033[0m'
info()    { echo -e "${BOLD}▶  $*${NC}"; }
success() { echo -e "${GREEN}✔  $*${NC}"; }
warn()    { echo -e "${YELLOW}⚠  $*${NC}"; }
die()     { echo -e "${RED}✖  $*${NC}" >&2; exit 1; }
run()     { if [[ "$DRY_RUN" == true ]]; then echo -e "${YELLOW}  [dry-run] $*${NC}"; else eval "$@"; fi; }
remote()  { if [[ "$DRY_RUN" == true ]]; then echo -e "${YELLOW}  [dry-run] on host: $*${NC}"; else "${SSH[@]}" "$@"; fi; }

# ── Version ───────────────────────────────────────────────────────────────────

CURRENT_TAG=$(sed -nE 's#^Image=.*/phantasos-app:(.+)$#\1#p' "$VERSION_FILE")
[[ -n "$CURRENT_TAG" ]] || die "No Image= tag found in $VERSION_FILE"

if [[ -n "$ROLLBACK" ]]; then
    NEW_TAG="v${ROLLBACK}"
elif [[ -n "$VERSION_OVERRIDE" ]]; then
    NEW_TAG="v${VERSION_OVERRIDE}"
elif [[ "$CURRENT_TAG" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    NEW_TAG="v${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.$((BASH_REMATCH[3] + 1))"
else
    NEW_TAG="v1.0.0"    # first deploy: the units still carry the TAG placeholder
fi

DIRTY=""
if [[ -z "$ROLLBACK" && -n "$(git status --porcelain -- . ':!deploy/quadlet')" ]]; then
    [[ "$ALLOW_DIRTY" == true ]] || die "Working tree has uncommitted changes. Commit first, or pass --allow-dirty."
    DIRTY="-dirty"
    NEW_TAG="${NEW_TAG}${DIRTY}"
fi

echo
echo -e "${BOLD}Phantasos deploy → ${HOST}${NC}"
echo "  Current version : ${CURRENT_TAG}"
echo "  New version     : ${NEW_TAG}"
echo "  Commit          : $(git rev-parse --short HEAD) on $(git rev-parse --abbrev-ref HEAD)${DIRTY:+ (uncommitted changes)}"
echo "  Images via      : $([[ -n "$ROLLBACK" ]] && echo "none (rollback; must already be on the host)" || { [[ "$MODE" == load ]] && echo "SSH (podman load)" || echo "Harbor"; })"
echo
if [[ "$DRY_RUN" == false ]]; then
    read -r -p "Type 'deploy' to roll out to PRODUCTION: " CONFIRM
    [[ "$CONFIRM" == "deploy" ]] || die "Aborted."
fi

# ── Host preflight ────────────────────────────────────────────────────────────

info "Checking host prerequisites"
remote 'set -e
  test -f /etc/phantasos/phantasos.env || { echo "missing /etc/phantasos/phantasos.env"; exit 1; }
  [ "$(stat -c %a /etc/phantasos/phantasos.env)" = 600 ] || { echo "/etc/phantasos/phantasos.env must be mode 600"; exit 1; }
  for k in APP_SECRET DATABASE_URL GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET; do
    grep -q "^$k=." /etc/phantasos/phantasos.env || { echo "phantasos.env: $k is not set"; exit 1; }
  done
  test -f /etc/phantasos/tls/fullchain.pem && test -f /etc/phantasos/tls/privkey.pem || { echo "missing TLS files"; exit 1; }
  mountpoint -q /media || { echo "/media is not mounted"; exit 1; }
  [ "$(stat -c %u:%g /media/phantasos)" = 65534:65534 ] || { echo "/media/phantasos must be owned by 65534:65534"; exit 1; }
  echo ok' || die "Host preflight failed"

# ── Build and ship ────────────────────────────────────────────────────────────

if [[ -z "$ROLLBACK" ]]; then
    info "Building images ${NEW_TAG} (linux/amd64)"
    for target in app web; do
        image="${REGISTRY}/phantasos-${target}"
        run "docker build ${NO_CACHE} --platform linux/amd64 -f .docker/Dockerfile --target ${target} \
            --label org.opencontainers.image.version=${NEW_TAG} \
            --label org.opencontainers.image.revision=$(git rev-parse HEAD) \
            -t ${image}:${NEW_TAG} -t phantasos-${target}:local ."
    done

    if [[ "$MODE" == "load" ]]; then
        info "Shipping images over SSH"
        run "docker save ${APP_IMAGE}:${NEW_TAG} ${WEB_IMAGE}:${NEW_TAG} | gzip -1 | ${SSH[*]} 'gunzip | podman load'"
    else
        info "Pushing to Harbor"
        run "docker push ${APP_IMAGE}:${NEW_TAG}"
        run "docker push ${WEB_IMAGE}:${NEW_TAG}"
        info "Pre-pulling on the host"
        remote "podman pull --authfile /etc/containers/auth.json ${APP_IMAGE}:${NEW_TAG} >/dev/null && \
                podman pull --authfile /etc/containers/auth.json ${WEB_IMAGE}:${NEW_TAG} >/dev/null && echo ok" \
            || die "Host could not pull from Harbor (auth.json, or the .21 → Harbor ACL). Retry with --load."
    fi
else
    info "Rollback: checking ${NEW_TAG} is on the host"
    remote "podman image exists ${APP_IMAGE}:${NEW_TAG} && podman image exists ${WEB_IMAGE}:${NEW_TAG}" \
        || die "${NEW_TAG} images are not on the host"
fi

# ── Units ─────────────────────────────────────────────────────────────────────

info "Setting units to ${NEW_TAG} and installing them"
run "sed -i.bak -E 's#^(Image=.*/phantasos-(app|web)):.*\$#\\1:${NEW_TAG}#' ${UNITS_DIR}/*.container && rm -f ${UNITS_DIR}/*.bak"
run "scp -q -o BatchMode=yes -o LogLevel=ERROR ${UNITS_DIR}/phantasos.network ${UNITS_DIR}/*.container ${HOST}:/etc/containers/systemd/"
remote 'systemctl daemon-reload && /usr/libexec/podman/quadlet -dryrun >/dev/null && echo ok' \
    || die "Quadlet rejected the units"

# ── Roll out ──────────────────────────────────────────────────────────────────

info "Restarting services (the worker may take up to 10 min to finish an encode)"
remote 'systemctl restart phantasos-app.service phantasos-worker.service && systemctl restart phantasos-web.service'

info "Running database migrations"
remote 'podman exec phantasos-app php bin/console doctrine:migrations:migrate -n --allow-no-migration'

# ── Smoke tests ───────────────────────────────────────────────────────────────

info "Smoke tests"
remote "set -e
  for s in phantasos-app phantasos-worker phantasos-web; do
    systemctl is-active -q \$s.service || { echo \"\$s is not active\"; journalctl -u \$s.service -n 30 --no-pager; exit 1; }
  done
  podman exec phantasos-web nginx -t -q
  # A bad key reaches PHP and the database: legacy answers 403 'Authentication Failed. '.
  body=\$(curl -sS --max-time 15 --resolve ${SITE}:443:127.0.0.1 -H 'X-Api-Key: deploy-smoke-test' \
         -w '|%{http_code}' 'https://${SITE}/api/mediaInfo?media_id=000000000000000000000000')
  [ \"\$body\" = 'Authentication Failed. |403' ] || { echo \"API smoke test failed: \$body\"; exit 1; }
  echo 'services active, nginx config ok, API → PHP → database ok'" || die "Smoke tests failed — check journalctl -u phantasos-*"

success "Deployed ${NEW_TAG}"
echo
echo "  Commit the unit changes so git records what is running:  git diff ${UNITS_DIR}"
echo "  Logs:  ssh ${HOST} journalctl -fu phantasos-worker"
echo "  Roll back:  bin/deploy.sh --rollback ${CURRENT_TAG#v}"
