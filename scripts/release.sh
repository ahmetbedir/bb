#!/usr/bin/env sh
#
# Maintainer release script. Builds the PHAR, cross-compiles standalone
# binaries for every platform with phpacker, ad-hoc signs the macOS ones, and
# publishes them as a GitHub release on this repo. Then updates the Homebrew
# tap so `brew upgrade` picks up the new version.
#
#   scripts/release.sh v1.0.2
#
# Release notes come from notes/<version>.md when it exists; otherwise they
# are generated from the commit subjects since the previous tag, grouped by
# Conventional Commit type.
#
# Requires: php, ./vendor/bin/phpacker, gh (authenticated: `gh auth login`),
# run inside the repo with its GitHub remote configured.

set -eu

VERSION="${1:-}"
[ -n "$VERSION" ] || { echo "usage: scripts/release.sh vX.Y.Z" >&2; exit 1; }

cd "$(dirname "$0")/.."

REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner)"

# phpacker downloads PHP binaries through the GitHub API. Unauthenticated
# that is 60 requests an hour, easily used up; authenticate with gh's token.
GITHUB_TOKEN="${GITHUB_TOKEN:-$(gh auth token)}"
export GITHUB_TOKEN
ver_no_v="${VERSION#v}"
echo "==> Releasing $VERSION to $REPO"

echo "==> Building PHAR"
# Bake the v-prefixed tag (e.g. v1.0.4) so `bb --version` matches the git tag
# and the local-install build. The Homebrew formula still uses the v-less form.
# Without dev dependencies; dev ones (incl. phpacker below) are restored after.
scripts/build-phar.sh bb.phar "$VERSION"

echo "==> Cross-building all platforms with phpacker"
./vendor/bin/phpacker build all --no-interaction >/dev/null

echo "==> Packaging release assets"
# Each binary ships compressed (34 MB -> ~15 MB), holding a single "bb" (or
# "bb.exe"): half the upload and half of every user's download. Homebrew
# unpacks archives itself; install.sh/install.ps1 unpack them too.
dist="builds/dist"
rm -rf "$dist"; mkdir -p "$dist"

pack() { # pack <phpacker output> <asset name>
    stage="$(mktemp -d)"
    case "$2" in
        *windows*)
            cp "$1" "$stage/bb.exe"
            (cd "$stage" && zip -q -9 "bb.zip" bb.exe)
            mv "$stage/bb.zip" "$dist/$2.zip" ;;
        *)
            cp "$1" "$stage/bb"
            chmod +x "$stage/bb"
            # Ad-hoc sign macOS binaries (required to run on Apple Silicon).
            case "$2" in *macos*) codesign --force --sign - "$stage/bb" 2>/dev/null || true ;; esac
            tar -C "$stage" -czf "$dist/$2.tar.gz" bb ;;
    esac
    rm -rf "$stage"
}

pack builds/build/mac/mac-arm             bb-macos-arm64
pack builds/build/mac/mac-x64             bb-macos-x86_64
pack builds/build/linux/linux-arm         bb-linux-arm64
pack builds/build/linux/linux-x64         bb-linux-x86_64
pack builds/build/windows/windows-x64.exe bb-windows-x86_64

# Bake the real repo slug into the installers so a plain curl of them works.
sed "s|@@REPO@@|$REPO|g" install.sh  > "$dist/install.sh"
sed "s|@@REPO@@|$REPO|g" install.ps1 > "$dist/install.ps1"

echo "==> Preparing release notes"
notes="$dist/notes.md"
if [ -f "notes/$VERSION.md" ]; then
    cp "notes/$VERSION.md" "$notes"
    echo "    using notes/$VERSION.md"
else
    prev="$(git describe --tags --abbrev=0 2>/dev/null || true)"
    range="${prev:+$prev..}HEAD"
    subjects="$(git log --no-merges --format='%s (%h)' "$range")"

    section() { # section <title> <regex on the commit type>
        lines="$(printf '%s\n' "$subjects" | grep -E "$2" | sed 's/^/- /' || true)"
        [ -n "$lines" ] && printf '## %s\n\n%s\n\n' "$1" "$lines"
        return 0
    }

    {
        [ -z "$subjects" ] && printf 'Maintenance release.\n\n'
        section "Features" '^feat(\(|!|:)'
        section "Fixes" '^fix(\(|!|:)'
        section "Other changes" '^(build|chore|ci|docs|perf|refactor|revert|style|test)(\(|!|:)'
        printf '%s\n' "$subjects" | grep -qE '^[a-z]+(\([^)]*\))?!:' \
            && printf '## ⚠️ Breaking changes\n\nSee the entries marked with "!" above.\n\n'
        [ -n "$prev" ] && printf '**Full changelog:** https://github.com/%s/compare/%s...%s\n' "$REPO" "$prev" "$VERSION"
        true
    } > "$notes"
    echo "    generated from ${range} (add notes/$VERSION.md to write them by hand)"
fi

echo "==> Publishing GitHub release"
# As a draft first, so nobody sees a "latest" release with missing assets
# while they upload; then upload in parallel (one connection doesn't fill the
# uplink) and publish once all are there.
gh release create "$VERSION" --draft --title "$VERSION" --notes-file "$notes"

pids=""
for asset in "$dist"/bb-* "$dist/install.sh" "$dist/install.ps1"; do
    gh release upload "$VERSION" "$asset" --clobber &
    pids="$pids $!"
done

failed=0
for pid in $pids; do
    wait "$pid" || failed=1
done

if [ "$failed" -ne 0 ]; then
    echo "!! Upload failed; release $VERSION left as a draft. Fix and re-run the upload, or delete it with: gh release delete $VERSION" >&2
    exit 1
fi

gh release edit "$VERSION" --draft=false --latest

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
      url "$base/bb-macos-arm64.tar.gz"
      sha256 "$(sha "$dist/bb-macos-arm64.tar.gz")"
    end
    on_intel do
      url "$base/bb-macos-x86_64.tar.gz"
      sha256 "$(sha "$dist/bb-macos-x86_64.tar.gz")"
    end
  end

  on_linux do
    on_arm do
      url "$base/bb-linux-arm64.tar.gz"
      sha256 "$(sha "$dist/bb-linux-arm64.tar.gz")"
    end
    on_intel do
      url "$base/bb-linux-x86_64.tar.gz"
      sha256 "$(sha "$dist/bb-linux-x86_64.tar.gz")"
    end
  end

  def install
    bin.install "bb"
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
