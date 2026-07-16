#!/usr/bin/env sh
#
# Maintainer release script. Builds the PHAR, cross-compiles standalone
# binaries for every platform with phpacker, ad-hoc signs the macOS ones, and
# publishes them as a GitHub release on this repo. Then updates the Homebrew
# tap so `brew upgrade` picks up the new version.
#
#   scripts/release.sh v1.0.2
#
# Requires: php, ./vendor/bin/phpacker, gh (authenticated: `gh auth login`),
# run inside the repo with its GitHub remote configured.

set -eu

VERSION="${1:-}"
[ -n "$VERSION" ] || { echo "usage: scripts/release.sh vX.Y.Z" >&2; exit 1; }

cd "$(dirname "$0")/.."

REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner)"
ver_no_v="${VERSION#v}"
echo "==> Releasing $VERSION to $REPO"

echo "==> Building PHAR"
php bb app:build bb.phar --build-version="$ver_no_v" --no-interaction >/dev/null

echo "==> Cross-building all platforms with phpacker"
./vendor/bin/phpacker build all --no-interaction >/dev/null

echo "==> Packaging release assets"
dist="builds/dist"
rm -rf "$dist"; mkdir -p "$dist"
cp builds/build/mac/mac-arm             "$dist/bb-macos-arm64"
cp builds/build/mac/mac-x64             "$dist/bb-macos-x86_64"
cp builds/build/linux/linux-arm         "$dist/bb-linux-arm64"
cp builds/build/linux/linux-x64         "$dist/bb-linux-x86_64"
cp builds/build/windows/windows-x64.exe "$dist/bb-windows-x86_64.exe"
chmod +x "$dist"/bb-* 2>/dev/null || true

# Ad-hoc sign the macOS binaries (required to run on Apple Silicon).
codesign --force --sign - "$dist/bb-macos-arm64"  2>/dev/null || true
codesign --force --sign - "$dist/bb-macos-x86_64" 2>/dev/null || true

# Bake the real repo slug into the installers so a plain curl of them works.
sed "s|@@REPO@@|$REPO|g" install.sh  > "$dist/install.sh"
sed "s|@@REPO@@|$REPO|g" install.ps1 > "$dist/install.ps1"

echo "==> Publishing GitHub release"
gh release create "$VERSION" \
    "$dist"/bb-* "$dist/install.sh" "$dist/install.ps1" \
    --title "$VERSION" \
    --notes "bb $VERSION" \
    --latest

# --- Homebrew tap ------------------------------------------------------------
OWNER="${REPO%/*}"
TAP_REPO="${BB_TAP_REPO:-$OWNER/homebrew-tap}"

if gh repo view "$TAP_REPO" >/dev/null 2>&1; then
    echo "==> Updating Homebrew tap $TAP_REPO"
    sha() { shasum -a 256 "$1" | awk '{print $1}'; }
    base="https://github.com/$REPO/releases/download/$VERSION"
    tapdir="$(mktemp -d)"
    gh repo clone "$TAP_REPO" "$tapdir" -- --depth=1 --quiet
    mkdir -p "$tapdir/Formula"
    cat > "$tapdir/Formula/bb.rb" <<EOF
class Bb < Formula
  desc "gh-style command-line client for Bitbucket Cloud"
  homepage "https://github.com/$REPO"
  version "$ver_no_v"

  on_macos do
    on_arm do
      url "$base/bb-macos-arm64"
      sha256 "$(sha "$dist/bb-macos-arm64")"
    end
    on_intel do
      url "$base/bb-macos-x86_64"
      sha256 "$(sha "$dist/bb-macos-x86_64")"
    end
  end

  on_linux do
    on_arm do
      url "$base/bb-linux-arm64"
      sha256 "$(sha "$dist/bb-linux-arm64")"
    end
    on_intel do
      url "$base/bb-linux-x86_64"
      sha256 "$(sha "$dist/bb-linux-x86_64")"
    end
  end

  def install
    bin.install Dir["bb-*"].first => "bb"
  end

  test do
    assert_match "Bitbucket CLI Tool", shell_output("#{bin}/bb --version")
  end
end
EOF
    git -C "$tapdir" add Formula/bb.rb
    git -C "$tapdir" commit -q -m "bb $VERSION"
    git -C "$tapdir" push -q -u origin HEAD
    rm -rf "$tapdir"
else
    echo "==> Skipping Homebrew tap ($TAP_REPO not found)."
    echo "    Create it once (public repo named 'homebrew-tap') to enable brew installs."
fi

echo ""
echo "==> Done. Install with:"
echo "    Homebrew:     brew install $OWNER/tap/bb"
echo "    macOS/Linux:  curl -fsSL https://github.com/$REPO/releases/latest/download/install.sh | sh"
echo "    Windows:      irm https://github.com/$REPO/releases/latest/download/install.ps1 | iex"
