#!/bin/sh
set -eu

APP_DIR="${APP_DIR:-$HOME/seo-audit}"
cd "$APP_DIR"

echo "==> multilingual-seo-audit deploy"
echo "==> Working directory: $(pwd)"

NODE_BIN=""

# Prefer current supported Plesk Node handlers.
for VERSION in 24 22 20; do
  CANDIDATE="/opt/plesk/node/$VERSION/bin"
  if [ -x "$CANDIDATE/node" ] && [ -x "$CANDIDATE/npm" ]; then
    NODE_BIN="$CANDIDATE"
    break
  fi
done

# Fallback: use npm already available in PATH.
if [ -z "$NODE_BIN" ] && command -v npm >/dev/null 2>&1 && command -v node >/dev/null 2>&1; then
  NODE_BIN=""
fi

if [ -n "$NODE_BIN" ]; then
  export PATH="$NODE_BIN:$PATH"
fi

if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
  echo "ERROR: No usable Node.js/npm installation was found."
  echo "Expected a Plesk handler under /opt/plesk/node/24, /22 or /20."
  echo "Check Domains > seo-audit.rehmeier.es > Node.js and note the configured Node.js version."
  exit 1
fi

echo "==> Node binary: $(command -v node)"
echo "==> npm binary: $(command -v npm)"
echo "==> Node: $(node --version)"
echo "==> npm: $(npm --version)"

echo "==> Installing dependencies"
npm install --include=dev --no-audit --no-fund

echo "==> Building TypeScript"
npm run build

echo "==> Verifying deployment layout"
test -f _passenger.cjs || { echo "ERROR: _passenger.cjs missing from application root"; exit 1; }
test -f dist/server.js || { echo "ERROR: dist/server.js missing after build"; exit 1; }
test -f public/index.html || { echo "ERROR: public/index.html missing"; exit 1; }
test -f public/app.js || { echo "ERROR: public/app.js missing"; exit 1; }
test -f public/styles.css || { echo "ERROR: public/styles.css missing"; exit 1; }

printf 'ok\n' > public/deploy-status.txt

echo "==> Restarting Node.js application"
mkdir -p tmp
touch tmp/restart.txt

echo "==> Deploy complete"
