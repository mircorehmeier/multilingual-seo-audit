#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "==> multilingual-seo-audit deploy"
echo "==> Working directory: $(pwd)"

if command -v npm >/dev/null 2>&1; then
  :
elif [ -x /opt/plesk/node/22/bin/npm ]; then
  export PATH="/opt/plesk/node/22/bin:$PATH"
else
  echo "ERROR: npm not found. Ensure Node.js 22 is enabled for this Plesk subscription."
  exit 1
fi

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
