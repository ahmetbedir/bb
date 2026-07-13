#!/usr/bin/env bash
#
# Build bbcli into a single-file PHAR and install it onto your PATH.
#
#   ./install.sh                 # installs to ~/.local/bin (may prompt for sudo)
#   PREFIX=~/.local/bin ./install.sh   # installs somewhere without sudo
#
set -euo pipefail

cd "$(dirname "$0")"

PREFIX="${PREFIX:-$HOME/.local/bin}"
# Expand a leading ~ in case PREFIX was passed quoted (the shell won't).
PREFIX="${PREFIX/#\~/$HOME}"
echo "==> Installing bbcli to ${PREFIX}"
VERSION="${VERSION:-$(git describe --tags --always 2>/dev/null || echo dev)}"

echo "==> Building bbcli ${VERSION}"
php bbcli app:build bbcli --build-version="${VERSION}" --no-interaction >/dev/null

mkdir -p "${PREFIX}"
TARGET="${PREFIX}/bbcli"

if [ -w "${PREFIX}" ]; then
    install -m 0755 builds/bbcli "${TARGET}"
else
    echo "==> ${PREFIX} is not writable, using sudo"
    sudo install -m 0755 builds/bbcli "${TARGET}"
fi

echo "==> Installed to ${TARGET}"
"${TARGET}" --version

cat <<'EOF'

Next steps:
  bbcli configure          # or: bbcli auth login   (OAuth in the browser)
  bbcli auth setup-git     # let git push/pull authenticate via bbcli
  bbcli completion bash    # shell completion (also: zsh, fish)
EOF
