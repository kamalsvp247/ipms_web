#!/usr/bin/env bash
# =============================================================================
#  IPMS Bot Worker — VPS Install / Update Script
#
#  Usage:
#    curl -fsSL https://ipms.senda.fit/install.sh | sudo bash -s -- <SLOT_API_KEY>
#
#  What this does:
#    1. Installs Java 26 (OpenJDK, fresh download from Adoptium) if not present
#       or removes older Java and installs Java 26 fresh
#    2. Creates /opt/ipms-bot/ and downloads the bot JAR from the portal
#    3. Installs and starts a systemd service (ipms-bot)
#       The API key is passed as a command-line arg — no .env file needed.
#
#  Re-run the same command to update (re-downloads JAR, restarts service).
# =============================================================================
set -euo pipefail

SLOT_API_KEY="${1:-}"
PORTAL_URL="https://ipms.senda.fit"
INSTALL_DIR="/opt/ipms-bot"
SERVICE_NAME="ipms-bot"

# ── Colours ───────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}  ▶${NC} $1"; }
warn() { echo -e "${YELLOW}  ⚠${NC}  $1"; }
die()  { echo -e "${RED}  ✗${NC}  $1" >&2; exit 1; }

# ── Validation ────────────────────────────────────────────────────────────────
echo ""
echo "  IPMS Bot Worker — VPS Installer"
echo "  Portal : $PORTAL_URL"
echo ""

[[ -n "$SLOT_API_KEY" ]] || die "Missing API key.\n\n  Usage: curl -fsSL $PORTAL_URL/install.sh | sudo bash -s -- <SLOT_API_KEY>"
[[ $EUID -eq 0 ]]        || die "Must run as root.\n\n  Try: curl -fsSL $PORTAL_URL/install.sh | sudo bash -s -- $SLOT_API_KEY"

# ── Prerequisites ─────────────────────────────────────────────────────────────
if ! command -v curl &>/dev/null || ! command -v wget &>/dev/null; then
    log "Installing prerequisites..."
    # A broken third-party source makes apt-get update exit non-zero even when every
    # Ubuntu source refreshed fine; under `set -e` that would kill the installer.
    apt-get update -qq || warn "apt-get update reported errors (continuing)."
    apt-get install -y -qq curl wget || die "Could not install curl/wget. Check the apt output above."
fi

# ── Java 26 ───────────────────────────────────────────────────────────────────
JAVA_26_HOME="/usr/lib/jvm/jdk-26"

# Check if Java 26 is already installed (matches "26", "26.0.1", etc.)
if [[ -x "$JAVA_26_HOME/bin/java" ]] && "$JAVA_26_HOME/bin/java" -version 2>&1 | grep -qE '"26[".]'; then
    log "Java 26 already installed at $JAVA_26_HOME — skipping download."
else
    # Clean up old manual JDK extractions (not apt packages — purging those removes Maven)
    log "Cleaning old JDK extractions..."
    rm -rf /usr/lib/jvm/jdk-2[0-5]* 2>/dev/null || true

    # Download JDK 26 from Adoptium (latest stable)
    JDK_DOWNLOAD_URL="https://github.com/adoptium/temurin26-binaries/releases/download/jdk-26%2B35/OpenJDK26U-jdk_x64_linux_hotspot_26_35.tar.gz"
    JDK_TEMP="/tmp/jdk-26.tar.gz"

    if [[ -s "$JDK_TEMP" ]]; then
        PARTIAL_MB=$(du -m "$JDK_TEMP" | cut -f1)
        log "Resuming Java 26 download (already have ${PARTIAL_MB} MB)..."
    else
        log "Downloading Java 26 from Adoptium..."
    fi

    # Resume partial downloads (-C -); retry on transient failures.
    # Total budget 15 min; partial file at $JDK_TEMP is kept across runs so
    # re-running the installer continues from where the previous attempt failed.
    if ! curl -fL --retry 5 --retry-delay 5 --retry-all-errors \
            --connect-timeout 30 --max-time 900 \
            -C - -o "$JDK_TEMP" "$JDK_DOWNLOAD_URL"; then
        die "Failed to download JDK 26. Partial file at $JDK_TEMP — re-run the installer to resume."
    fi

    # Extract to /usr/lib/jvm/
    log "Installing Java 26..."
    mkdir -p /usr/lib/jvm
    tar -xzf "$JDK_TEMP" -C /usr/lib/jvm/

    # Rename extracted directory to jdk-26
    EXTRACTED_DIR=$(ls -d /usr/lib/jvm/jdk-* 2>/dev/null | grep -v "^$JAVA_26_HOME$" | head -1)
    if [[ -n "$EXTRACTED_DIR" && "$EXTRACTED_DIR" != "$JAVA_26_HOME" ]]; then
        rm -rf "$JAVA_26_HOME" 2>/dev/null || true
        mv "$EXTRACTED_DIR" "$JAVA_26_HOME"
    fi

    # Clean up
    rm -f "$JDK_TEMP"

    # Set up java alternatives
    update-alternatives --install /usr/bin/java java "$JAVA_26_HOME/bin/java" 100 2>/dev/null || true
    update-alternatives --install /usr/bin/javac javac "$JAVA_26_HOME/bin/javac" 100 2>/dev/null || true
fi

"$JAVA_26_HOME/bin/java" -version 2>&1 | head -1 | sed 's/^/  Java: /'

# ── Install directory ─────────────────────────────────────────────────────────
log "Preparing $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR/logs"

# ── Download JAR ──────────────────────────────────────────────────────────────
log "Downloading bot JAR from portal..."
# Note: no -f flag — we check the HTTP status manually so we can show a clear error message.
HTTP_STATUS=$(curl -sSL \
    --write-out "%{http_code}" \
    -H "Authorization: Bearer $SLOT_API_KEY" \
    "$PORTAL_URL/api/bot/jar" \
    -o "$INSTALL_DIR/ivac-booking.jar.tmp")

if [[ "$HTTP_STATUS" == "401" ]]; then
    rm -f "$INSTALL_DIR/ivac-booking.jar.tmp"
    die "Invalid API key (HTTP 401). Check that the slot API key is correct."
fi

if [[ "$HTTP_STATUS" == "404" ]]; then
    rm -f "$INSTALL_DIR/ivac-booking.jar.tmp"
    die "JAR not found (HTTP 404). Build it first:\n  Bot Control → VPS Setup → Build JAR"
fi

if [[ "$HTTP_STATUS" != "200" ]]; then
    rm -f "$INSTALL_DIR/ivac-booking.jar.tmp"
    die "JAR download failed (HTTP $HTTP_STATUS). Check the portal logs for details."
fi

mv "$INSTALL_DIR/ivac-booking.jar.tmp" "$INSTALL_DIR/ivac-booking.jar"
JAR_SIZE=$(du -sh "$INSTALL_DIR/ivac-booking.jar" | cut -f1)
log "Downloaded ivac-booking.jar ($JAR_SIZE)"

# ── Systemd service ───────────────────────────────────────────────────────────
# Heap is sized from this host's RAM rather than hardcoded: the same script installs onto
# 2 GB VPS workers and the 31 GB portal host. Default ergonomics start the heap at 8 MB and
# let G1 grow it repeatedly through the window-open burst, which is exactly the wrong moment.
# Written as if-blocks, not `[ test ] && assign`: under `set -e` a false test makes the
# compound return non-zero and kills the installer, which would fire on every host below
# the clamp — that is, on all the small VPS workers this script mostly targets.
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
HEAP_MAX_MB=$(( MEM_MB * 40 / 100 ))
if [ "$HEAP_MAX_MB" -gt 10240 ]; then
  HEAP_MAX_MB=10240
fi
if [ "$HEAP_MAX_MB" -lt 512 ]; then
  HEAP_MAX_MB=512
fi
HEAP_MIN_MB=$(( HEAP_MAX_MB / 2 ))
log "Sizing JVM heap: ${HEAP_MIN_MB}m initial / ${HEAP_MAX_MB}m max (host has ${MEM_MB}m)"

log "Installing systemd service ($SERVICE_NAME)..."
cat > "/etc/systemd/system/$SERVICE_NAME.service" <<EOF
[Unit]
Description=IPMS Bot Worker
After=network-online.target
Wants=network-online.target

[Service]
User=root
WorkingDirectory=$INSTALL_DIR
ExecStart=$JAVA_26_HOME/bin/java \\
  -Xms${HEAP_MIN_MB}m -Xmx${HEAP_MAX_MB}m \\
  -XX:+AlwaysPreTouch \\
  -XX:MaxGCPauseMillis=50 \\
  -XX:+UseStringDeduplication \\
  -XX:+ExitOnOutOfMemoryError \\
  -XX:+HeapDumpOnOutOfMemoryError \\
  -XX:HeapDumpPath=$INSTALL_DIR/logs \\
  -jar $INSTALL_DIR/ivac-booking.jar $SLOT_API_KEY
# One account is ~7 platform threads plus its own keepalive connections; 500 accounts on one
# worker blows through the default fd ceiling long before it runs out of memory.
LimitNOFILE=1048576
# The portal host also runs the captcha solver (CPUWeight=50). Booking must win that contest.
CPUWeight=200
IOWeight=200
Restart=on-failure
RestartSec=15
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
echo ""
echo -e "  ${GREEN}✓ Done!${NC} IPMS Bot Worker installed and running."
echo ""
echo "    Status  : systemctl status $SERVICE_NAME"
echo "    Logs    : journalctl -u $SERVICE_NAME -f"
echo "    Restart : systemctl restart $SERVICE_NAME"
echo "    Update  : curl -fsSL $PORTAL_URL/install.sh | sudo bash -s -- $SLOT_API_KEY"
echo ""
