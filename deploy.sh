#!/bin/sh
set -eu

echo "==> multilingual-seo-audit deploy"

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

echo "==> Restarting Node.js application"
mkdir -p tmp
touch tmp/restart.txt

echo "==> Deploy complete"
