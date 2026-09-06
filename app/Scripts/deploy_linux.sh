#!/bin/bash
# Deploy in_house_captcha_solver.cjs to a Linux VPS
# Run this on the Linux server as root or www-data
#
# Usage:
#   scp -r app/Scripts/in_house_captcha_solver.cjs user@vps:/opt/ipms-captcha/
#   scp deploy_linux.sh user@vps:/opt/ipms-captcha/
#   ssh user@vps 'cd /opt/ipms-captcha && bash deploy_linux.sh'

set -e

INSTALL_DIR="/opt/ipms-captcha"
SERVICE_NAME="ipms-in-house-captcha"
NODE_USER="www-data"

echo "=== In-House Turnstile Solver — Linux Deployment ==="

# 1. Create directories
echo "[1/6] Creating directories..."
mkdir -p "$INSTALL_DIR"/{storage/app/puppeteer,storage/app/captcha}
chown -R $NODE_USER:$NODE_USER "$INSTALL_DIR"

# 2. Install Node.js if not present
echo "[2/6] Checking Node.js..."
if ! command -v node &> /dev/null; then
    echo "  Installing Node.js 22.x..."
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi
echo "  Node: $(node --version)"
echo "  npm: $(npm --version)"

# 3. Install Puppeteer + Chrome
echo "[3/6] Installing Puppeteer and Chrome..."
cd "$INSTALL_DIR"
if [ ! -d "node_modules/puppeteer" ]; then
    npm init -y 2>/dev/null || true
    npm install puppeteer
fi
# Install Chrome binary into Puppeteer cache
PUPPETEER_CACHE_DIR="$INSTALL_DIR/storage/app/puppeteer" npx puppeteer browsers install chrome
echo "  Chrome installed."

# 4. Copy solver script
echo "[4/6] Copying solver script..."
if [ -f "app/Scripts/in_house_captcha_solver.cjs" ]; then
    cp app/Scripts/in_house_captcha_solver.cjs "$INSTALL_DIR/in_house_captcha_solver.cjs"
elif [ -f "in_house_captcha_solver.cjs" ]; then
    cp in_house_captcha_solver.cjs "$INSTALL_DIR/in_house_captcha_solver.cjs"
fi
chown $NODE_USER:$NODE_USER "$INSTALL_DIR/in_house_captcha_solver.cjs"

# 5. Create systemd service
echo "[5/6] Creating systemd service..."
cat > /etc/systemd/system/${SERVICE_NAME}.service << 'EOF'
[Unit]
Description=IPMS In-House Turnstile Solver
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/ipms-captcha
ExecStart=/usr/bin/node in_house_captcha_solver.cjs
Restart=always
RestartSec=5
Environment=NODE_ENV=production
Environment=CAPTCHA_SOLVER_HOST=127.0.0.1
Environment=CAPTCHA_SOLVER_PORT=8788
Environment=CAPTCHA_SOLVER_CONCURRENCY=9
Environment=CAPTCHA_SOLVER_BROWSERS=4
Environment=CAPTCHA_SOLVER_IDLE_MS=300000
Environment=CAPTCHA_SOLVER_STORAGE_DIR=/opt/ipms-captcha/storage/app/puppeteer
Environment=CAPTCHA_SOLVER_CAPTCHA_DIR=/opt/ipms-captcha/storage/app/captcha
CPUQuota=200%
MemoryMax=2G

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable ${SERVICE_NAME}
systemctl start ${SERVICE_NAME}

# 6. Verify
echo "[6/6] Verifying..."
sleep 3
if curl -s http://127.0.0.1:8788/health | python3 -m json.tool; then
    echo ""
    echo "=== DEPLOYMENT SUCCESSFUL ==="
    echo "  Solver: http://127.0.0.1:8788"
    echo "  Health: http://127.0.0.1:8788/health"
    echo "  Solve:  POST http://127.0.0.1:8788/solve"
    echo "  Service: systemctl status ${SERVICE_NAME}"
else
    echo "  WARNING: Health check failed. Check logs:"
    echo "  journalctl -u ${SERVICE_NAME} -f"
fi
