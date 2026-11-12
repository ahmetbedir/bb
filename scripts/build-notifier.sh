#!/usr/bin/env sh
#
# Build "bb Notifier.app" (macos/notifier) into resources/notifier/, where the
# PHAR picks it up. It posts desktop notifications as "bb", with bb's icon on
# the left like any native app. Universal (arm64 + x86_64), macOS 13+,
# ad-hoc signed. Needs macOS with the Xcode command line tools (swiftc).
#
#   scripts/build-notifier.sh
#
set -eu

cd "$(dirname "$0")/.."

SRC=macos/notifier
APP="resources/notifier/bb Notifier.app"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"

for arch in arm64 x86_64; do
    swiftc -swift-version 5 -O -target "$arch-apple-macos13" "$SRC/main.swift" -o "$TMP/$arch"
done

lipo -create "$TMP/arm64" "$TMP/x86_64" -output "$APP/Contents/MacOS/bb-notifier"
cp "$SRC/Info.plist" "$APP/Contents/Info.plist"
cp "$SRC/AppIcon.icns" "$APP/Contents/Resources/AppIcon.icns"

codesign --force --sign - "$APP"
echo "Built $APP"
