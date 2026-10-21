#!/usr/bin/env bash
# Drop-in installer to attach HubClient + HubActivity into a local private clone of Wallet Yar.
set -euo pipefail

if [ $# -lt 1 ]; then
  echo "Usage: bash sdk/android/apply_to_wallet_yar.sh /path/to/wallet-yar-src"
  exit 1
fi

TARGET="$1"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cp "$SCRIPT_DIR/wallet-yar-dropin/HubClient.java" "$TARGET/app/src/ir/ghahremani/wallet/HubClient.java"
cp "$SCRIPT_DIR/wallet-yar-dropin/HubActivity.java" "$TARGET/app/src/ir/ghahremani/wallet/HubActivity.java"
echo "✅ Installed HubClient.java & HubActivity.java into $TARGET/app/src/ir/ghahremani/wallet/"
echo "See README.md for 1-step APK signing with app/build.sh."
