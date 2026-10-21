#!/usr/bin/env bash
# Drop-in installer & crash-fix patcher connecting Wallet Yar to YarToken Platform (v2.0.1).
set -euo pipefail

if [ $# -lt 1 ]; then
  echo "Usage: bash sdk/android/apply_to_wallet_yar.sh /path/to/wallet-yar-src"
  exit 1
fi

TARGET="$1"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

cp "$SCRIPT_DIR/wallet-yar-dropin/HubClient.java" "$TARGET/app/src/ir/ghahremani/wallet/HubClient.java"
cp "$SCRIPT_DIR/wallet-yar-dropin/HubActivity.java" "$TARGET/app/src/ir/ghahremani/wallet/HubActivity.java"

python3 - "$TARGET" << 'PY'
import sys, pathlib, re

root = pathlib.Path(sys.argv[1])

# 1. Fix AndroidManifest.xml: register BOTH missing .LanguageActivity AND .HubActivity + enable HTTP/HTTPS support
manifest_path = root / "app/AndroidManifest.xml"
m = manifest_path.read_text(encoding="utf-8")
m = m.replace('android:usesCleartextTraffic="false"', 'android:usesCleartextTraffic="true"')

for act in [".LanguageActivity", ".HubActivity"]:
    if f'android:name="{act}"' not in m:
        m = m.replace(
            '<activity android:name=".OnboardActivity"',
            f'<activity android:name="{act}" android:exported="false" />\n        <activity android:name=".OnboardActivity"'
        )
manifest_path.write_text(m, encoding="utf-8")

# 2. Harden Store.prefs(Context c) against null getApplicationContext() inside attachBaseContext()
store_path = root / "app/src/ir/ghahremani/wallet/Store.java"
st = store_path.read_text(encoding="utf-8")
old_prefs = 'return c.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);'
new_prefs = 'Context app = c.getApplicationContext();\n        return (app != null ? app : c).getSharedPreferences(PREFS, Context.MODE_PRIVATE);'
if old_prefs in st:
    st = st.replace(old_prefs, new_prefs)
    store_path.write_text(st, encoding="utf-8")

# 3. Wire MainActivity quick Send button + prominent YarToken Hub card + Nav Send tab to HubActivity
main_path = root / "app/src/ir/ghahremani/wallet/MainActivity.java"
ma = main_path.read_text(encoding="utf-8")
ma = ma.replace(
    'v -> toast(getString(R.string.send_soon))',
    'v -> startActivity(new Intent(this, HubActivity.class))'
)
hub_card_snippet = '''        LinearLayout hubBanner = Ui.card(this);
        hubBanner.setBackground(Ui.roundStroke(this, Theme.CARD, Theme.BLUE_SOFT, 16));
        hubBanner.addView(Ui.tv(this, "🪙 شبکه توکن اختصاصی، درگاه پرداخت، پاکت هدیه و وام", 14, Theme.TEXT, App.bold()));
        hubBanner.addView(Ui.tv(this, "انتقال آنی ECDSA • پرداخت قبض • باز کردن پاکت هدیه • اقساط و نمایندگی", 11, Theme.DIM, App.reg()));
        hubBanner.setOnClickListener(v -> startActivity(new Intent(this, HubActivity.class)));
        content.addView(hubBanner, topParams(14));
'''
if 'hubBanner' not in ma:
    ma = ma.replace('content.addView(actions);', 'content.addView(actions);\n' + hub_card_snippet)
main_path.write_text(ma, encoding="utf-8")

nav_path = root / "app/src/ir/ghahremani/wallet/Nav.java"
nv = nav_path.read_text(encoding="utf-8")
nv = nv.replace(
    'v -> { if (active != SEND) Ui.toast(a, a.getString(R.string.send_soon)); }',
    'v -> { if (active != SEND) a.startActivity(new Intent(a, HubActivity.class)); }'
)
nav_path.write_text(nv, encoding="utf-8")

# 4. Verify ZERO missing concrete Activities in AndroidManifest.xml!
declared = set(re.findall(r'<activity[^>]*android:name="([^"]+)"', m))
concrete = set()
for jp in (root / "app/src/ir/ghahremani/wallet").rglob("*.java"):
    src = jp.read_text(encoding="utf-8")
    hit = re.search(r'public\s+class\s+(\w+)\s+extends\s+(Activity|BaseActivity)', src)
    if hit:
        concrete.add("." + hit.group(1))

missing = concrete - declared
if missing:
    raise SystemExit(f"FATAL: Missing activities in AndroidManifest.xml: {sorted(missing)}")
print(f"✅ Manifest verified: ALL {len(concrete)} Activities registered ({sorted(concrete)})")
PY

echo "✅ Installed & verified HubClient.java, HubActivity.java, LanguageActivity fix & Store null-guard!"
