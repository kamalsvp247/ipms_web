#!/usr/bin/env bash
# =============================================================================
#  IPMS Captcha Solver Node — VPS Install / Update Script
#
#  Usage:
#    curl -fsSL https://ipms.senda.fit/captcha-install.sh | sudo bash -s -- <NODE_API_KEY>
#    curl -fsSL https://ipms.senda.fit/captcha-install.sh | sudo bash -s -- <NODE_API_KEY> --profile shared
#
#  Profiles:
#    dedicated (default) — the box does nothing but solve captchas
#    shared              — the box also runs ipms-bot; sizes down and yields CPU to it
#
#  What this does:
#    1. Installs the shared libraries headless Chrome needs
#    2. Installs Node.js 22 LTS (pinned tarball) if not present
#    3. Creates /opt/ipms-captcha/, installs puppeteer (downloads Chrome)
#    4. Downloads the solver script from the portal
#    5. Sizes concurrency from the core count and installs systemd ipms-captcha-node
#
#  Re-run the same command to update (re-downloads the script, restarts the service).
#  A running node can also be updated from the portal with no SSH: Fleet -> Update.
# =============================================================================
set -euo pipefail

NODE_API_KEY="${1:-}"
PROFILE="dedicated"
PORTAL_URL="https://ipms.senda.fit"
INSTALL_DIR="/opt/ipms-captcha"
SERVICE_NAME="ipms-captcha-node"
PUPPETEER_VERSION="24.43.1"
NODE_VERSION="22.14.0"

# ── Colours ───────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}  ▶${NC} $1"; }
warn() { echo -e "${YELLOW}  ⚠${NC}  $1"; }
die()  { echo -e "${RED}  ✗${NC}  $1" >&2; exit 1; }

# ── Arguments ─────────────────────────────────────────────────────────────────
shift || true
while [[ $# -gt 0 ]]; do
    case "$1" in
        --profile) PROFILE="${2:-dedicated}"; shift 2 ;;
        --profile=*) PROFILE="${1#*=}"; shift ;;
        *) warn "Ignoring unknown argument: $1"; shift ;;
    esac
done

[[ "$PROFILE" == "dedicated" || "$PROFILE" == "shared" ]] || die "Unknown profile '$PROFILE' (expected: dedicated | shared)"

echo ""
echo "  IPMS Captcha Solver Node — Installer"
echo "  Portal  : $PORTAL_URL"
echo "  Profile : $PROFILE"
echo ""

[[ -n "$NODE_API_KEY" ]] || die "Missing API key.\n\n  Usage: curl -fsSL $PORTAL_URL/captcha-install.sh | sudo bash -s -- <NODE_API_KEY>"
[[ $EUID -eq 0 ]]        || die "Must run as root.\n\n  Try: curl -fsSL $PORTAL_URL/captcha-install.sh | sudo bash -s -- $NODE_API_KEY"

# ── Prerequisites ─────────────────────────────────────────────────────────────
log "Installing prerequisites and Chrome libraries..."
export DEBIAN_FRONTEND=noninteractive

# A broken third-party source (speedtest-cli, docker, ... on a rented VPS image) makes
# apt-get update exit non-zero even though every Ubuntu source refreshed fine. Under
# `set -e` that killed the installer before it did anything, so failures here are only
# reported — the package install below is the real test of whether the lists are usable.
APT_LOG="$(mktemp)"
if ! apt-get update -qq >"$APT_LOG" 2>&1; then
    warn "apt-get update reported errors (continuing — usually an unrelated third-party repo):"
    sed 's/^/      /' "$APT_LOG" >&2 || true
fi

# Chrome will not start without these, and puppeteer does not install them for you
# (that is a Playwright feature). A missing one shows up as a launch timeout with no
# useful error, so they go in explicitly.
CHROME_DEPS="ca-certificates curl xz-utils fonts-liberation libnss3 libnspr4 \
libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 \
libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 libvulkan1 libxss1"

# libasound2 was renamed libasound2t64 in Ubuntu 24.04. Try the new name, fall back to
# the old one, and only fail if neither resolves.
if ! apt-get install -y -qq $CHROME_DEPS libasound2t64 >"$APT_LOG" 2>&1; then
    if ! apt-get install -y -qq $CHROME_DEPS libasound2 >"$APT_LOG" 2>&1; then
        sed 's/^/      /' "$APT_LOG" >&2 || true
        rm -f "$APT_LOG"
        die "Could not install Chrome's shared library dependencies. Check the apt output above."
    fi
fi
rm -f "$APT_LOG"

# ── Node.js 22 LTS ────────────────────────────────────────────────────────────
NODE_HOME="/usr/local/lib/nodejs/node-22"

node_is_recent() {
    local bin="$1"
    [[ -x "$bin" ]] || return 1
    local major
    major=$("$bin" -v 2>/dev/null | sed 's/^v\([0-9]*\).*/\1/')
    [[ -n "$major" && "$major" -ge 20 ]]
}

if node_is_recent "$NODE_HOME/bin/node"; then
    log "Node.js already installed at $NODE_HOME — skipping download."
elif node_is_recent "$(command -v node || echo /nonexistent)"; then
    NODE_HOME="$(dirname "$(dirname "$(command -v node)")")"
    log "Using existing Node.js at $NODE_HOME ($(node -v))."
else
    NODE_TARBALL="node-v${NODE_VERSION}-linux-x64.tar.xz"
    NODE_TEMP="/tmp/${NODE_TARBALL}"

    if [[ -s "$NODE_TEMP" ]]; then
        log "Resuming Node.js download (already have $(du -m "$NODE_TEMP" | cut -f1) MB)..."
    else
        log "Downloading Node.js ${NODE_VERSION}..."
    fi

    # Resumable (-C -) so a re-run continues rather than restarting, same as the bot installer.
    if ! curl -fL --retry 5 --retry-delay 5 --retry-all-errors \
            --connect-timeout 30 --max-time 600 \
            -C - -o "$NODE_TEMP" "https://nodejs.org/dist/v${NODE_VERSION}/${NODE_TARBALL}"; then
        die "Failed to download Node.js. Partial file at $NODE_TEMP — re-run the installer to resume."
    fi

    log "Installing Node.js ${NODE_VERSION}..."
    mkdir -p /usr/local/lib/nodejs
    rm -rf "$NODE_HOME"
    tar -xJf "$NODE_TEMP" -C /usr/local/lib/nodejs/
    mv "/usr/local/lib/nodejs/node-v${NODE_VERSION}-linux-x64" "$NODE_HOME"
    rm -f "$NODE_TEMP"

    update-alternatives --install /usr/bin/node node "$NODE_HOME/bin/node" 100 2>/dev/null || true
    update-alternatives --install /usr/bin/npm npm "$NODE_HOME/bin/npm" 100 2>/dev/null || true
fi

NODE_BIN="$NODE_HOME/bin/node"
NPM_BIN="$NODE_HOME/bin/npm"
"$NODE_BIN" -v | sed 's/^/  Node: /'

# ── Install directory + puppeteer ─────────────────────────────────────────────
log "Preparing $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR/storage" "$INSTALL_DIR/.cache"

# Chrome derives its crashpad database path from HOME and dies on a CHECK (SIGTRAP)
# when it is not writable, so the service points HOME here.
export HOME="$INSTALL_DIR/storage"
export PUPPETEER_CACHE_DIR="$INSTALL_DIR/.cache"

INSTALLED_PUPPETEER=$("$NODE_BIN" -e "try{console.log(require('$INSTALL_DIR/node_modules/puppeteer/package.json').version)}catch(e){console.log('')}" 2>/dev/null || echo "")

if [[ "$INSTALLED_PUPPETEER" == "$PUPPETEER_VERSION" ]]; then
    log "puppeteer $PUPPETEER_VERSION already installed — skipping."
else
    log "Installing puppeteer $PUPPETEER_VERSION and Chrome (~500 MB, this takes a few minutes)..."
    cd "$INSTALL_DIR"
    [[ -f package.json ]] || echo '{"name":"ipms-captcha-node","private":true}' > package.json

    "$NPM_BIN" install --no-audit --no-fund --loglevel=error "puppeteer@$PUPPETEER_VERSION" \
        || die "puppeteer install failed. Check disk space (needs ~1 GB free) and network access to npmjs.org."
fi

# ── Download solver script ────────────────────────────────────────────────────
log "Downloading solver script from portal..."
# No -f: the status is checked manually so the failure message can be specific.
HTTP_STATUS=$(curl -sSL \
    --write-out "%{http_code}" \
    -H "Authorization: Bearer $NODE_API_KEY" \
    "$PORTAL_URL/api/captcha-nodes/script" \
    -o "$INSTALL_DIR/solver.cjs.tmp")

case "$HTTP_STATUS" in
    200) ;;
    401) rm -f "$INSTALL_DIR/solver.cjs.tmp"; die "Invalid API key (HTTP 401). Copy the command again from In-House Captcha → Fleet." ;;
    404) rm -f "$INSTALL_DIR/solver.cjs.tmp"; die "Solver script not found on the portal (HTTP 404)." ;;
    *)   rm -f "$INSTALL_DIR/solver.cjs.tmp"; die "Script download failed (HTTP $HTTP_STATUS). Check the portal logs." ;;
esac

# A truncated download that replaced a working script would take this node off the fleet
# with no way to push a fix back to it.
if [[ ! -s "$INSTALL_DIR/solver.cjs.tmp" ]] || ! grep -q "CAPTCHA_NODE_KEY" "$INSTALL_DIR/solver.cjs.tmp"; then
    rm -f "$INSTALL_DIR/solver.cjs.tmp"
    die "Downloaded script failed its sanity check — refusing to install it."
fi

mv "$INSTALL_DIR/solver.cjs.tmp" "$INSTALL_DIR/solver.cjs"
SCRIPT_VERSION=$(sha256sum "$INSTALL_DIR/solver.cjs" | cut -c1-12)
log "Installed solver.cjs (version $SCRIPT_VERSION)"

# ── Size from core count ──────────────────────────────────────────────────────
CORES=$(nproc)

# nproc reports the host's cores inside some providers' containers, which would size the
# node several times past what it can actually run. cgroup v2 knows the real quota.
if [[ -r /sys/fs/cgroup/cpu.max ]]; then
    read -r CPU_QUOTA CPU_PERIOD < /sys/fs/cgroup/cpu.max || true
    if [[ "${CPU_QUOTA:-max}" != "max" && "${CPU_PERIOD:-0}" -gt 0 ]]; then
        CGROUP_CORES=$(( CPU_QUOTA / CPU_PERIOD ))
        [[ "$CGROUP_CORES" -lt 1 ]] && CGROUP_CORES=1
        [[ "$CGROUP_CORES" -lt "$CORES" ]] && CORES=$CGROUP_CORES
    fi
fi

# A solve costs ~4 CPU-seconds of Chrome, so concurrency tracks cores almost 1:1 and the
# CPU quota is the real ceiling. Measured on the portal host: 9 concurrent over 4 browsers
# at CPUQuota=800% gives 7.9 cores and 2.08 solves/s at 94% first-attempt success.
if [[ "$PROFILE" == "shared" ]]; then
    CONCURRENCY=$(( CORES / 2 )); [[ "$CONCURRENCY" -lt 2 ]] && CONCURRENCY=2
    CPU_QUOTA_PCT=$(( CORES * 40 ))
    # ipms-bot.service runs CPUWeight=200, so under contention the bot always wins.
    CPU_WEIGHT=50
else
    CONCURRENCY=$CORES; [[ "$CONCURRENCY" -lt 2 ]] && CONCURRENCY=2
    CPU_QUOTA_PCT=$(( CORES * 90 ))
    CPU_WEIGHT=100
fi

# An operator-chosen concurrency from the fleet console wins over the core-count sizing.
# The node would pick it up on its first heartbeat anyway, but then CPUQuota, MemoryMax and
# the browser count would stay sized for a number it is not running.
DESIRED_CONCURRENCY=$(curl -fsSL --max-time 20 \
    -H "Authorization: Bearer $NODE_API_KEY" \
    "$PORTAL_URL/api/captcha-nodes/provisioning" 2>/dev/null \
    | sed -n 's/.*"desired_concurrency"[[:space:]]*:[[:space:]]*\([0-9]\{1,\}\).*/\1/p') || true

if [[ "${DESIRED_CONCURRENCY:-}" =~ ^[0-9]+$ ]] && [[ "$DESIRED_CONCURRENCY" -ge 1 ]] && [[ "$DESIRED_CONCURRENCY" -le 64 ]]; then
    log "Portal specifies concurrency $DESIRED_CONCURRENCY for this node (core-count sizing would have been $CONCURRENCY)."
    CONCURRENCY=$DESIRED_CONCURRENCY
    # Quota follows the request, because a solve is ~1 core and starving it would just
    # queue. Still bounded by the box so an over-large number cannot oversubscribe it.
    CPU_QUOTA_PCT=$(( CONCURRENCY * 90 ))
    [[ "$PROFILE" == "shared" ]] && CPU_QUOTA_PCT=$(( CONCURRENCY * 60 ))
    MAX_QUOTA_PCT=$(( CORES * 95 ))
    [[ "$CPU_QUOTA_PCT" -gt "$MAX_QUOTA_PCT" ]] && CPU_QUOTA_PCT=$MAX_QUOTA_PCT
fi

# ── RAM clamp ─────────────────────────────────────────────────────────────────
# CPU is the binding resource on a big box, but on a small co-tenant one RAM runs out
# first: a concurrent solve costs ~220 MB of Chrome (measured 2.0 GB at concurrency 9),
# and these workers already hold a JVM. Sizing on cores alone would let Chrome grow into
# the bot's memory and get one of them OOM-killed.
#
# MemAvailable already discounts whatever the JVM is holding, so budget from that and keep
# a reserve so the box is not driven to the edge.
MEM_PER_SOLVE_MB=250
if [[ "$PROFILE" == "shared" ]]; then RESERVE_MB=512; else RESERVE_MB=256; fi

MEM_AVAIL_MB=$(awk '/^MemAvailable:/{print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)

if [[ "$MEM_AVAIL_MB" -gt 0 ]]; then
    MEM_BUDGET_MB=$(( MEM_AVAIL_MB - RESERVE_MB ))
    [[ "$MEM_BUDGET_MB" -lt "$MEM_PER_SOLVE_MB" ]] && MEM_BUDGET_MB=$MEM_PER_SOLVE_MB

    MEM_CONCURRENCY=$(( MEM_BUDGET_MB / MEM_PER_SOLVE_MB ))
    [[ "$MEM_CONCURRENCY" -lt 1 ]] && MEM_CONCURRENCY=1

    if [[ "$MEM_CONCURRENCY" -lt "$CONCURRENCY" ]]; then
        warn "Only ${MEM_AVAIL_MB} MB available — capping concurrency $CONCURRENCY → $MEM_CONCURRENCY (RAM, not cores, is the limit here)."
        CONCURRENCY=$MEM_CONCURRENCY
    fi
else
    MEM_BUDGET_MB=$(( CONCURRENCY * MEM_PER_SOLVE_MB ))
fi

# Hard kernel ceiling matching what we sized for, so a Chrome leak can never reach the
# point where the OOM killer picks between this and ipms-bot. Generous over the estimate
# because hitting MemoryMax kills the service, and the idle reaper already keeps the
# steady-state cost near zero.
MEMORY_MAX_MB=$(( CONCURRENCY * MEM_PER_SOLVE_MB + 512 ))

# One browser serialises CDP dispatch for every context it owns, which shows up as latency:
# 16 in flight on one browser is p50 6.1s, on four it is p50 4.4s.
BROWSERS=$(( (CONCURRENCY + 3) / 4 )); [[ "$BROWSERS" -lt 1 ]] && BROWSERS=1

log "Sized for $CORES core(s) / ${MEM_AVAIL_MB} MB available: concurrency $CONCURRENCY over $BROWSERS browser(s), CPUQuota ${CPU_QUOTA_PCT}%, MemoryMax ${MEMORY_MAX_MB}M"

# ── Systemd service ───────────────────────────────────────────────────────────
log "Installing systemd service ($SERVICE_NAME)..."
cat > "/etc/systemd/system/$SERVICE_NAME.service" <<EOF
[Unit]
Description=IPMS Captcha Solver Node ($PROFILE)
After=network-online.target
Wants=network-online.target

[Service]
User=root
WorkingDirectory=$INSTALL_DIR
# Chrome derives its crashpad database path from HOME and dies on a CHECK (SIGTRAP) when
# that path is not writable, so it must point somewhere this service owns.
Environment=HOME=$INSTALL_DIR/storage
Environment=PUPPETEER_CACHE_DIR=$INSTALL_DIR/.cache
Environment=CAPTCHA_SOLVER_STORAGE_DIR=$INSTALL_DIR/storage
Environment=CAPTCHA_SOLVER_CAPTCHA_DIR=$INSTALL_DIR/storage/captcha
Environment=CAPTCHA_SOLVER_HOST=127.0.0.1
Environment=CAPTCHA_SOLVER_PORT=8788
Environment=CAPTCHA_SOLVER_CONCURRENCY=$CONCURRENCY
Environment=CAPTCHA_SOLVER_BROWSERS=$BROWSERS
Environment=CAPTCHA_SOLVER_MAX_QUEUE=32
Environment=CAPTCHA_SOLVER_IDLE_MS=60000
Environment=CAPTCHA_SOLVER_RECYCLE_AFTER=400
Environment=CAPTCHA_NODE_KEY=$NODE_API_KEY
Environment=CAPTCHA_PORTAL_URL=$PORTAL_URL
Environment=CAPTCHA_NODE_SERVICE=$SERVICE_NAME
# Only an installed copy may overwrite itself. The portal's own checkout is the source of
# truth and must never be replaced by a download.
Environment=CAPTCHA_NODE_SELF_UPDATE=1
# Kernel-enforced ceilings, so this can never starve anything else on the box. MemoryMax
# matters most on a shared worker: without it a Chrome leak puts the OOM killer in the
# position of choosing between this service and ipms-bot.
# Hides /dev/dri from the service. On a host with a real GPU, Chrome running as root
# wedges its renderer on the first canvas draw, so Cloudflare's fingerprint never
# completes: zero tokens, no error and no 403, which reads exactly like IP reputation.
# --disable-gpu is not sufficient — Chrome still probes the device nodes.
PrivateDevices=yes
CPUQuota=${CPU_QUOTA_PCT}%
CPUWeight=$CPU_WEIGHT
MemoryMax=${MEMORY_MAX_MB}M
MemoryHigh=$(( MEMORY_MAX_MB * 85 / 100 ))M
ExecStart=$NODE_BIN $INSTALL_DIR/solver.cjs
Restart=on-failure
RestartSec=5
# Give the sidecar time to close Chrome cleanly; the control-group kill then reaps any
# renderer that outlived it.
TimeoutStopSec=20
StandardOutput=journal
StandardError=journal
SyslogIdentifier=$SERVICE_NAME

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable "$SERVICE_NAME" --quiet
systemctl restart "$SERVICE_NAME"

# ── Done ──────────────────────────────────────────────────────────────────────
sleep 3
if systemctl is-active --quiet "$SERVICE_NAME"; then
    echo ""
    echo -e "  ${GREEN}✓ Done!${NC} Captcha solver node installed and running."
else
    echo ""
    warn "Service is not active. Check: journalctl -u $SERVICE_NAME -n 50"
fi

echo ""
echo "    Status  : systemctl status $SERVICE_NAME"
echo "    Logs    : journalctl -u $SERVICE_NAME -f"
echo "    Health  : curl -s localhost:8788/health"
echo "    Restart : systemctl restart $SERVICE_NAME"
echo "    Update  : In-House Captcha → Fleet → Update (no SSH needed)"
echo ""
echo "    The node should appear online in the portal within ~10 seconds."
echo ""
