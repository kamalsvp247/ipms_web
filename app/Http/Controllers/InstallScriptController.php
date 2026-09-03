<?php

namespace App\Http\Controllers;

use App\Models\AgentSlot;
use Illuminate\Http\Response;

class InstallScriptController extends Controller
{
    /**
     * GET /install/{apiKey}
     * Returns a personalized bash install script for the given agent slot.
     * The api_key itself is the credential — no session auth required.
     */
    public function show(string $apiKey): Response
    {
        $slot = AgentSlot::where('api_key', $apiKey)->first();

        if (! $slot) {
            abort(404);
        }

        $portalUrl = rtrim(config('app.url'), '/');
        $script = $this->buildScript($apiKey, $portalUrl, $slot->name);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'inline; filename="ipms-bot-install.sh"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function buildScript(string $apiKey, string $portalUrl, string $slotName): string
    {
        // Indentation intentionally stripped — heredoc content is the script itself.
        return <<<BASH
#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  IPMS Bot Worker — VPS Install / Update Script
#  Slot   : {$slotName}
#  Portal : {$portalUrl}
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SLOT_API_KEY="{$apiKey}"
PORTAL_URL="{$portalUrl}"
INSTALL_DIR="/opt/ipms-bot"
SERVICE_NAME="ipms-bot"
JAVA_VER="25"

log() { echo "  ▶ \$1"; }

echo ""
echo "  IPMS Bot Worker — Installing slot: {$slotName}"
echo ""

# ── Root check ─────────────────────────────────────────────────────────────
[[ \$EUID -eq 0 ]] || { echo "  Error: run as root — use: curl ... | sudo bash"; exit 1; }

# ── Prerequisites ──────────────────────────────────────────────────────────
log "Updating package lists..."
apt-get update -qq
apt-get install -y -qq wget apt-transport-https gnupg curl

# ── Java 25 via Eclipse Temurin ────────────────────────────────────────────
if java -version 2>&1 | grep -q '"25\.'; then
    log "Java \$JAVA_VER already installed — skipping."
else
    log "Installing Java \$JAVA_VER (Eclipse Temurin)..."
    wget -qO /tmp/adoptium.gpg https://packages.adoptium.net/artifactory/api/gpg/key/public
    gpg --dearmor < /tmp/adoptium.gpg > /usr/share/keyrings/adoptium.gpg
    rm /tmp/adoptium.gpg
    . /etc/os-release
    echo "deb [signed-by=/usr/share/keyrings/adoptium.gpg] https://packages.adoptium.net/artifactory/deb \${VERSION_CODENAME} main" \\
        > /etc/apt/sources.list.d/adoptium.list
    apt-get update -qq
    apt-get install -y -qq "temurin-\${JAVA_VER}-jdk"
fi

# ── Install directory ──────────────────────────────────────────────────────
log "Preparing \$INSTALL_DIR..."
mkdir -p "\$INSTALL_DIR/logs"

# ── Download JAR from portal ───────────────────────────────────────────────
log "Downloading bot JAR..."
curl -fsSL \\
    -H "Authorization: Bearer \$SLOT_API_KEY" \\
    "\$PORTAL_URL/api/bot/jar" \\
    -o "\$INSTALL_DIR/ivac-booking.jar"

# ── Environment config ─────────────────────────────────────────────────────
log "Writing .env..."
cat > "\$INSTALL_DIR/.env" <<'ENV'
PORTAL_URL={$portalUrl}
SLOT_API_KEY={$apiKey}
ENV
chmod 600 "\$INSTALL_DIR/.env"

# ── Systemd service ────────────────────────────────────────────────────────
log "Installing systemd service..."
cat > "/etc/systemd/system/\$SERVICE_NAME.service" <<EOF
[Unit]
Description=IPMS Bot Worker ({$slotName})
After=network-online.target
Wants=network-online.target

[Service]
User=root
WorkingDirectory=\$INSTALL_DIR
EnvironmentFile=\$INSTALL_DIR/.env
ExecStart=/usr/bin/java -jar \$INSTALL_DIR/ivac-booking.jar
Restart=on-failure
RestartSec=15
StandardOutput=journal
StandardError=journal
SyslogIdentifier=\$SERVICE_NAME

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable "\$SERVICE_NAME"
systemctl restart "\$SERVICE_NAME"

echo ""
echo "  ✓ Done! IPMS Bot Worker installed and started."
echo ""
echo "    Status  : systemctl status \$SERVICE_NAME"
echo "    Logs    : journalctl -u \$SERVICE_NAME -f"
echo "    Restart : systemctl restart \$SERVICE_NAME"
echo "    Update  : curl -fsSL \$PORTAL_URL/install/\$SLOT_API_KEY | sudo bash"
echo ""
BASH;
    }
}
