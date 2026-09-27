#!/bin/sh
set -eu

APP_DIR="${APP_DIR:-$HOME/seo-audit}"
cd "$APP_DIR"

echo "==> multilingual-seo-audit deploy"
echo "==> Working directory: $(pwd)"

if [ -x /opt/plesk/node/22/bin/npm ]; then
  export PATH="/opt/plesk/node/22/bin:$PATH"
elif command -v npm >/dev/null 2>&1; then
  :
else
  echo "ERROR: npm not found. The Plesk Git deployment action is probably running inside a chroot."
  echo "Set this subscription's SSH access to non-chrooted /bin/bash, then deploy again."
  exit 1
fi

echo "==> Node: $(node --version)"
echo "==> npm: $(npm --version)"

echo "==> Installing dependencies"
npm install --include=dev --no-audit --no-fund

echo "==> Building TypeScript"
npm run build

echo "==> Verifying deployment layout"
test -f app.js || { echo "ERROR: app.js missing from application root"; exit 1; }
test -f dist/server.js || { echo "ERROR: dist/server.js missing after build"; exit 1; }
test -f public/index.html || { echo "ERROR: public/index.html missing"; exit 1; }
test -f public/app.js || { echo "ERROR: public/app.js missing"; exit 1; }
test -f public/styles.css || { echo "ERROR: public/styles.css missing"; exit 1; }

printf 'ok\n' > public/deploy-status.txt

echo "==> Restarting Node.js application"
mkdir -p tmp
touch tmp/restart.txt

echo "==> Deploy complete"
