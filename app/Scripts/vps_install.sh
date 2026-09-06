#!/bin/bash
# Paste this entire script into the VPS SSH terminal
# It installs Node.js, Puppeteer, Chrome, and the Turnstile solver

set -e

echo "=== IPMS Turnstile Solver Setup ==="

# 1. Install Node.js 22
echo "[1/6] Installing Node.js 22..."
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - > /dev/null 2>&1
apt-get install -y -qq nodejs > /dev/null 2>&1
echo "  Node: $(node --version)"

# 2. Install dependencies
echo "[2/6] Installing build tools..."
apt-get install -y -qq curl wget build-essential python3 > /dev/null 2>&1

# 3. Create install directory
echo "[3/6] Setting up /opt/ipms-captcha..."
mkdir -p /opt/ipms-captcha/storage/app/puppeteer
mkdir -p /opt/ipms-captcha/storage/app/captcha
cd /opt/ipms-captcha

# 4. Install Puppeteer + Chrome
echo "[4/6] Installing Puppeteer + Chrome (takes ~2 min)..."
cat > package.json << 'EOF'
{"name":"ipms-captcha","version":"1.0.0","private":true,"dependencies":{"puppeteer":"^24.0.0"}}
EOF
npm install --loglevel=error 2>&1 | tail -2
PUPPETEER_CACHE_DIR=/opt/ipms-captcha/storage/app/puppeteer npx puppeteer browsers install chrome 2>&1 | tail -2
echo "  Chrome installed"

# 5. Download solver
echo "[5/6] Downloading solver script..."
curl -fsSL "https://raw.githubusercontent.com/kamalsvp247/ipms_web/main/app/Scripts/in_house_captcha_solver.cjs" -o in_house_captcha_solver.cjs
if [ ! -s in_house_captcha_solver.cjs ]; then
    echo "  Download failed. Trying alternative..."
    curl -fsSL "https://raw.githubusercontent.com/kamalsvp247/ipms_web/master/app/Scripts/in_house_captcha_solver.cjs" -o in_house_captcha_solver.cjs
fi
echo "  Solver: $(wc -c < in_house_captcha_solver.cjs) bytes"

# 6. Create systemd service
echo "[6/6] Creating systemd service..."
cat > /etc/systemd/system/ipms-in-house-captcha.service << 'SVCEOF'
[Unit]
Description=IPMS Turnstile Solver
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/ipms-captcha
ExecStart=/usr/bin/node in_house_captcha_solver.cjs
Restart=always
RestartSec=5
Environment=NODE_ENV=production
Environment=CAPTCHA_SOLVER_HOST=0.0.0.0
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
SVCEOF

systemctl daemon-reload
systemctl enable ipms-in-house-captcha
systemctl start ipms-in-house-captcha

echo ""
echo "Waiting for solver to start..."
sleep 5

# Test
echo "=== Health Check ==="
curl -s http://127.0.0.1:8788/health | python3 -m json.tool 2>/dev/null || echo "Checking logs..."
echo ""
echo "=== DONE ==="
echo "Solver running on port 8788"
echo "Test: curl http://127.0.0.1:8788/health"
echo ""
echo "Public IP check:"
curl -s ifconfig.me 2>/dev/null || curl -s icanhazip.com 2>/dev/null || echo "Could not determine"
