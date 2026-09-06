#!/bin/bash
# ============================================================
# IPMS Turnstile Solver — One-Command Linux VPS Setup
# ============================================================
# Run on a fresh Ubuntu 22.04/24.04 VPS as root:
#
#   curl -fsSL https://raw.githubusercontent.com/your-repo/setup.sh | bash
#
# Or copy this file to your VPS and run:
#   bash setup_vps.sh
#
# What it does:
#   1. Installs Node.js 22, Puppeteer, Chrome
#   2. Downloads the solver script from GitHub
#   3. Creates systemd service (auto-restart on boot)
#   4. Opens firewall port 8788 (if ufw is active)
#   5. Prints the node key for portal registration
# ============================================================

set -e

# --- Configuration ---
SOLVER_PORT=8788
INSTALL_DIR="/opt/ipms-captcha"
NODE_USER="root"
REPO_URL="https://raw.githubusercontent.com/kamalsvp247/ipms_web/main"

echo ""
echo "╔═══════════════════════════════════════════════════════════╗"
echo "║   IPMS Turnstile Solver — VPS Setup                     ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo ""

# --- Step 1: System packages ---
echo "[1/7] Installing system dependencies..."
apt-get update -qq
apt-get install -y -qq curl wget git build-essential python3 > /dev/null 2>&1
echo "  ✓ System packages installed"

# --- Step 2: Node.js 22 ---
echo "[2/7] Installing Node.js 22..."
if ! command -v node &> /dev/null || [[ $(node --version | cut -d. -f1 | tr -d v) -lt 22 ]]; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - > /dev/null 2>&1
    apt-get install -y -qq nodejs > /dev/null 2>&1
fi
echo "  ✓ Node $(node --version), npm $(npm --version)"

# --- Step 3: Create install directory ---
echo "[3/7] Creating install directory..."
mkdir -p "$INSTALL_DIR"/{storage/app/puppeteer,storage/app/captcha}
cd "$INSTALL_DIR"

# --- Step 4: Install Puppeteer + Chrome ---
echo "[4/7] Installing Puppeteer and Chrome (this takes ~2 minutes)..."
cat > package.json << 'PKGJSON'
{
  "name": "ipms-captcha-solver",
  "version": "1.0.0",
  "private": true,
  "dependencies": {
    "puppeteer": "^24.0.0"
  }
}
PKGJSON
npm install --loglevel=error 2>&1 | tail -1
PUPPETEER_CACHE_DIR="$INSTALL_DIR/storage/app/puppeteer" npx puppeteer browsers install chrome 2>&1 | tail -1
echo "  ✓ Chrome installed"

# --- Step 5: Download solver script ---
echo "[5/7] Downloading solver script..."
# Try GitHub first, fallback to direct
SOLVER_URL="https://raw.githubusercontent.com/kamalsvp247/ipms_web/main/app/Scripts/in_house_captcha_solver.cjs"
if curl -fsSL "$SOLVER_URL" -o in_house_captcha_solver.cjs 2>/dev/null; then
    echo "  ✓ Downloaded from GitHub"
else
    echo "  ⚠ Could not download from GitHub."
    echo "  → Copy in_house_captcha_solver.cjs to $INSTALL_DIR manually"
    echo "  → Then re-run this script"
    exit 1
fi

# --- Step 6: Generate node API key ---
echo "[6/7] Generating node API key..."
NODE_KEY=$(openssl rand -hex 32)
echo "$NODE_KEY" > .node_key
chmod 600 .node_key
echo "  ✓ Node key: $NODE_KEY"

# --- Step 7: Create systemd service ---
echo "[7/7] Creating systemd service..."
cat > /etc/systemd/system/ipms-in-house-captcha.service << SERVICEEOF
[Unit]
Description=IPMS In-House Turnstile Solver
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/node in_house_captcha_solver.cjs
Restart=always
RestartSec=5
Environment=NODE_ENV=production
Environment=CAPTCHA_SOLVER_HOST=0.0.0.0
Environment=CAPTCHA_SOLVER_PORT=$SOLVER_PORT
Environment=CAPTCHA_SOLVER_CONCURRENCY=9
Environment=CAPTCHA_SOLVER_BROWSERS=4
Environment=CAPTCHA_SOLVER_IDLE_MS=300000
Environment=CAPTCHA_SOLVER_STORAGE_DIR=$INSTALL_DIR/storage/app/puppeteer
Environment=CAPTCHA_SOLVER_CAPTCHA_DIR=$INSTALL_DIR/storage/app/captcha
CPUQuota=200%
MemoryMax=2G

[Install]
WantedBy=multi-user.target
SERVICEEOF

systemctl daemon-reload
systemctl enable ipms-in-house-captcha
systemctl start ipms-in-house-captcha
echo "  ✓ Service started"

# --- Open firewall ---
if command -v ufw &> /dev/null; then
    ufw allow $SOLVER_PORT/tcp > /dev/null 2>&1 || true
fi

# --- Verify ---
echo ""
echo "Waiting for solver to start..."
sleep 5

PUBLIC_IP=$(curl -s ifconfig.me 2>/dev/null || curl -s icanhazip.com 2>/dev/null || echo "YOUR_VPS_IP")

if curl -s "http://127.0.0.1:$SOLVER_PORT/health" | python3 -m json.tool 2>/dev/null; then
    echo ""
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║   ✓ DEPLOYMENT SUCCESSFUL                               ║"
    echo "╠═══════════════════════════════════════════════════════════╣"
    echo "║                                                         ║"
    echo "║   Solver URL:  http://$PUBLIC_IP:$SOLVER_PORT           ║"
    echo "║   Health:      http://$PUBLIC_IP:$SOLVER_PORT/health    ║"
    echo "║   Node Key:    $NODE_KEY"
    echo "║                                                         ║"
    echo "║   Next steps:                                           ║"
    echo "║   1. Go to /captcha-nodes in the portal                ║"
    echo "║   2. Click 'Add Node'                                  ║"
    echo "║   3. Enter name + this IP + key                        ║"
    echo "║   4. The solver will appear as 'online'                ║"
    echo "║                                                         ║"
    echo "║   Or set in .env:                                       ║"
    echo "║   CAPTCHA_SOLVER_URL=http://$PUBLIC_IP:$SOLVER_PORT    ║"
    echo "║                                                         ║"
    echo "╚═══════════════════════════════════════════════════════════╝"
else
    echo ""
    echo "⚠ Health check failed. Check logs:"
    echo "  journalctl -u ipms-in-house-captcha -f"
fi
