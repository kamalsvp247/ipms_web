#!/bin/bash
set -e
echo "=== IPMS Turnstile Solver Setup ==="

echo "[1/6] Installing Node.js..."
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - > /dev/null 2>&1
apt-get install -y -qq nodejs build-essential python3 > /dev/null 2>&1
echo "  Node: $(node --version)"

echo "[2/6] Creating directories..."
mkdir -p /opt/ipms-captcha/{storage/app/puppeteer,storage/app/captcha}
cd /opt/ipms-captcha

echo "[3/6] Installing Puppeteer + Chrome (~2 min)..."
cat > package.json << 'EOF'
{"name":"ipms-captcha","version":"1.0.0","private":true,"dependencies":{"puppeteer":"^24.0.0"}}
EOF
npm install --loglevel=error 2>&1 | tail -2
PUPPETEER_CACHE_DIR=/opt/ipms-captcha/storage/app/puppeteer npx puppeteer browsers install chrome 2>&1 | tail -2

echo "[4/6] Downloading solver..."
curl -fsSL "https://raw.githubusercontent.com/kamalsvp247/ipms_web/main/app/Scripts/in_house_captcha_solver.cjs" -o in_house_captcha_solver.cjs
echo "  Size: $(wc -c < in_house_captcha_solver.cjs) bytes"

echo "[5/6] Creating service..."
cat > /etc/systemd/system/ipms-in-house-captcha.service << 'SVCEOF'
[Unit]
Description=IPMS Turnstile Solver
After=network.target
[Service]
Type=simple
WorkingDirectory=/opt/ipms-captcha
ExecStart=/usr/bin/node in_house_captcha_solver.cjs
Restart=always
RestartSec=5
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

echo "[6/6] Waiting..."
sleep 5
echo "=== Health Check ==="
curl -s http://127.0.0.1:8788/health | python3 -m json.tool
echo ""
echo "=== PUBLIC IP ==="
curl -s ifconfig.me 2>/dev/null || curl -s icanhazip.com 2>/dev/null
echo ""
