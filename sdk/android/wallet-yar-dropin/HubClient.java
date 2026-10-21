package ir.ghahremani.wallet;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;

import org.json.JSONObject;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.util.Locale;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import ir.ghahremani.wallet.core.Bip32;
import ir.ghahremani.wallet.core.Coins;
import ir.ghahremani.wallet.core.Hex;
import ir.ghahremani.wallet.core.Secp256k1;
import ir.ghahremani.wallet.core.Wallet;

/**
 * Companion Non-Custodial Client SDK connecting Wallet Yar (Android) to YarToken PHP 8 + MySQL Server.
 * Private keys remain encrypted in Android Keystore and sign challenges locally via RFC 6979 ECDSA.
 */
public final class HubClient {
    public interface JsonCb { void done(JSONObject data, String error); }

    private static final ExecutorService POOL = Executors.newFixedThreadPool(3);
    private static final Handler UI = new Handler(Looper.getMainLooper());

    private HubClient() {}

    public static String serverUrl(Context c) {
        return Store.get(c, "hub_server_url", "").trim();
    }

    public static void setServerUrl(Context c, String url) {
        String clean = url == null ? "" : url.trim();
        while (clean.endsWith("/")) clean = clean.substring(0, clean.length() - 1);
        Store.put(c, "hub_server_url", clean);
    }

    public static String bearer(Context c) {
        return Store.get(c, "hub_bearer_token", "");
    }

    private static String apiEndpoint(Context c, String action) {
        String base = serverUrl(c);
        if (base.isEmpty()) throw new IllegalStateException("ابتدا آدرس سرور توکن را تنظیم کنید");
        return base + "/api/v1/?action=" + action;
    }

    public static void connectWallet(final Context ctx, final Wallet wallet, final String agentCode, final JsonCb cb) {
        POOL.execute(() -> {
            try {
                Bip32.Node node = wallet.nodeFor(Coins.ETH, 0);
                String address = wallet.address(Coins.ETH, 0);
                String pubHex = Hex.enc(node.publicKeyCompressed());

                JSONObject chReq = new JSONObject().put("address", address);
                JSONObject chRes = new JSONObject(Net.postJson(apiEndpoint(ctx, "auth.challenge"), chReq.toString()));
                if (!chRes.optBoolean("ok")) throw new RuntimeException(chRes.optString("error", "خطا در چالش سرور"));

                String sigHex = Secp256k1.signPersonalMessage(node.key, chRes.getString("message"));

                JSONObject verReq = new JSONObject()
                        .put("address", address)
                        .put("nonce", chRes.getString("nonce"))
                        .put("public_key", pubHex)
                        .put("signature", sigHex)
                        .put("device_name", "Wallet Yar Android v2.0");
                if (agentCode != null && !agentCode.trim().isEmpty()) {
                    verReq.put("agent_code", agentCode.trim());
                }

                JSONObject verRes = new JSONObject(Net.postJson(apiEndpoint(ctx, "auth.verify_signature"), verReq.toString()));
                if (!verRes.optBoolean("ok")) throw new RuntimeException(verRes.optString("error", "رد امضای دیجیتال"));

                Store.put(ctx, "hub_bearer_token", verRes.getString("token"));
                UI.post(() -> cb.done(verRes, null));
            } catch (Exception e) {
                UI.post(() -> cb.done(null, e.getMessage()));
            }
        });
    }

    public static void fetchMe(final Context ctx, final JsonCb cb) {
        POOL.execute(() -> {
            try {
                JSONObject res = new JSONObject(Net.getAuth(apiEndpoint(ctx, "wallet.me"), bearer(ctx)));
                if (!res.optBoolean("ok")) throw new RuntimeException(res.optString("error", "نشست منقضی است"));
                Store.put(ctx, "hub_cached_balance", res.getJSONObject("wallet").optString("balance", "0"));
                UI.post(() -> cb.done(res, null));
            } catch (Exception e) {
                UI.post(() -> cb.done(null, e.getMessage()));
            }
        });
    }

    public static void quoteFee(final Context ctx, final String to, final String amount, final JsonCb cb) {
        POOL.execute(() -> {
            try {
                JSONObject req = new JSONObject().put("to", to.trim()).put("amount", amount.trim());
                JSONObject res = new JSONObject(Net.postJsonAuth(apiEndpoint(ctx, "wallet.quote"), req.toString(), bearer(ctx), null));
                if (!res.optBoolean("ok")) throw new RuntimeException(res.optString("error", "خطا در محاسبه کارمزد"));
                UI.post(() -> cb.done(res, null));
            } catch (Exception e) {
                UI.post(() -> cb.done(null, e.getMessage()));
            }
        });
    }

    public static void sendSigned(
            final Context ctx, final Wallet wallet, final String to,
            final String amount, final String memo, final String txNonce, final JsonCb cb) {
        POOL.execute(() -> {
            try {
                Bip32.Node node = wallet.nodeFor(Coins.ETH, 0);
                String fromAddr = wallet.address(Coins.ETH, 0);
                String normAmt = new BigDecimal(amount.trim()).setScale(8, RoundingMode.DOWN).toPlainString();
                String canonical = "YarToken Signed Transfer\nFrom: " + fromAddr.toLowerCase(Locale.ROOT)
                        + "\nTo: " + to.trim().toLowerCase(Locale.ROOT)
                        + "\nAmount: " + normAmt
                        + "\nNonce: " + txNonce;
                String sigHex = Secp256k1.signPersonalMessage(node.key, canonical);

                JSONObject req = new JSONObject()
                        .put("to", to.trim())
                        .put("amount", normAmt)
                        .put("memo", memo)
                        .put("tx_nonce", txNonce)
                        .put("public_key", Hex.enc(node.publicKeyCompressed()))
                        .put("signature", sigHex);

                JSONObject res = new JSONObject(Net.postJsonAuth(
                        apiEndpoint(ctx, "wallet.transfer"), req.toString(), bearer(ctx), "android-" + UUID.randomUUID()
                ));
                if (!res.optBoolean("ok")) throw new RuntimeException(res.optString("error", "خطا در انتقال توکن"));
                UI.post(() -> cb.done(res, null));
            } catch (Exception e) {
                UI.post(() -> cb.done(null, e.getMessage()));
            }
        });
    }

    public static void postAction(final Context ctx, final String action, final JSONObject body, final JsonCb cb) {
        POOL.execute(() -> {
            try {
                JSONObject res = new JSONObject(Net.postJsonAuth(apiEndpoint(ctx, action), body.toString(), bearer(ctx), null));
                if (!res.optBoolean("ok")) throw new RuntimeException(res.optString("error", "عملیات ناموفق"));
                UI.post(() -> cb.done(res, null));
            } catch (Exception e) {
                UI.post(() -> cb.done(null, e.getMessage()));
            }
        });
    }
}
