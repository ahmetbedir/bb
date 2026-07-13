#!/usr/bin/env bash
#
# Build bb into a single-file PHAR and install it onto your PATH.
#
#   ./install.sh                 # installs to ~/.local/bin (may prompt for sudo)
#   PREFIX=~/.local/bin ./install.sh   # installs somewhere without sudo
#
set -euo pipefail

cd "$(dirname "$0")"

PREFIX="${PREFIX:-$HOME/.local/bin}"
# Expand a leading ~ in case PREFIX was passed quoted (the shell won't).
PREFIX="${PREFIX/#\~/$HOME}"
echo "==> Installing bb to ${PREFIX}"
VERSION="${VERSION:-$(git describe --tags --always 2>/dev/null || echo dev)}"

echo "==> Building bb ${VERSION}"
php bb app:build bb --build-version="${VERSION}" --no-interaction >/dev/null

mkdir -p "${PREFIX}"
TARGET="${PREFIX}/bb"

if [ -w "${PREFIX}" ]; then
    install -m 0755 builds/bb "${TARGET}"
else
    echo "==> ${PREFIX} is not writable, using sudo"
    sudo install -m 0755 builds/bb "${TARGET}"
fi

echo "==> Installed to ${TARGET}"
"${TARGET}" --version

cat <<'EOF'

Next steps:
  bb configure          # or: bb auth login   (OAuth in the browser)
  bb auth setup-git     # let git push/pull authenticate via bb
  bb completion bash    # shell completion (also: zsh, fish)
EOF
