#!/usr/bin/env sh
#
# Download the latest standalone bb binary for this machine and install it.
# Published as a release asset, so a plain curl works for everyone:
#
#   curl -fsSL https://github.com/OWNER/REPO/releases/latest/download/install.sh | sh
#
# Override the install directory with BB_INSTALL_DIR (default /usr/local/bin,
# falling back to ~/.local/bin when that isn't writable).

set -eu

REPO="@@REPO@@"

os="$(uname -s)"
arch="$(uname -m)"

case "$os" in
    Darwin) os=macos ;;
    Linux)  os=linux ;;
    *) echo "Unsupported OS: $os" >&2; exit 1 ;;
esac

case "$arch" in
    arm64|aarch64) arch=arm64 ;;
    x86_64|amd64)  arch=x86_64 ;;
    *) echo "Unsupported architecture: $arch" >&2; exit 1 ;;
esac

asset="bb-${os}-${arch}"
url="https://github.com/${REPO}/releases/latest/download/${asset}"

dir="${BB_INSTALL_DIR:-/usr/local/bin}"
[ -d "$dir" ] || dir="$HOME/.local/bin"
mkdir -p "$dir"

tmp="$(mktemp)"
echo "==> Downloading ${asset}"
curl -fsSL "$url" -o "$tmp"
chmod +x "$tmp"

target="${dir}/bb"
if [ -w "$dir" ]; then
    mv "$tmp" "$target"
else
    echo "==> ${dir} is not writable, using sudo"
    sudo mv "$tmp" "$target"
fi

echo "==> Installed to ${target}"
"$target" --version || true

case ":$PATH:" in
    *":$dir:"*) ;;
    *) echo "   Note: add ${dir} to your PATH." ;;
esac

cat <<'EOF'

Next steps:
  bb configure          # or: bb auth login   (OAuth in the browser)
  bb auth setup-git     # let git push/pull authenticate via bb
EOF
