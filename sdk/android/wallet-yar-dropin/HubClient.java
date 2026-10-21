package ir.ghahremani.wallet;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.math.BigDecimal;
import java.math.BigInteger;
import java.math.RoundingMode;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Arrays;
import java.util.Locale;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

import ir.ghahremani.wallet.core.Bip32;
import ir.ghahremani.wallet.core.Coins;
import ir.ghahremani.wallet.core.Hex;
import ir.ghahremani.wallet.core.Keccak;
import ir.ghahremani.wallet.core.Wallet;

/**
 * Companion Non-Custodial Client SDK connecting Wallet Yar (Android) to YarToken PHP 8 + MySQL Server.
 * Private keys remain encrypted in Android Keystore and sign challenges locally via RFC 6979 ECDSA.
 * 100% self-contained: requires zero modifications to core/ or Net.java.
 */
public final class HubClient {
    public interface JsonCb { void done(JSONObject data, String error); }

    private static final ExecutorService POOL = Executors.newFixedThreadPool(3);
    private static final Handler UI = new Handler(Looper.getMainLooper());

    private static final BigInteger P = new BigInteger("fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f", 16);
    private static final BigInteger N = new BigInteger("fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141", 16);
    private static final BigInteger GX = new BigInteger("79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798", 16);
    private static final BigInteger GY = new BigInteger("483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8", 16);
    private static final BigInteger THREE = BigInteger.valueOf(3);

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
        if (base.isEmpty()) throw new IllegalStateException("ابتدا آدرس سرور توکن را در کادر بالا وارد کنید");
        return base + "/api/v1/?action=" + action;
    }

    private static byte[] hmacSha256(byte[] key, byte[] data) {
        try {
            Mac m = Mac.getInstance("HmacSHA256");
            m.init(new SecretKeySpec(key, "HmacSHA256"));
            return m.doFinal(data);
        } catch (Exception e) { throw new RuntimeException(e); }
    }

    private static byte[] concat(byte[]... parts) {
        ByteArrayOutputStream b = new ByteArrayOutputStream();
        for (byte[] p : parts) b.write(p, 0, p.length);
        return b.toByteArray();
    }

    private static void writeFixed(BigInteger v, byte[] dst, int off, int len) {
        byte[] b = v.toByteArray();
        int src = b[0] == 0 ? 1 : 0;
        int n = b.length - src;
        Arrays.fill(dst, off, off + len - n, (byte) 0);
        System.arraycopy(b, src, dst, off + len - n, n);
    }

    private static BigInteger gMulX(BigInteger k) {
        k = k.mod(N);
        BigInteger rx = BigInteger.ZERO, ry = BigInteger.ZERO, rz = BigInteger.ZERO;
        for (int i = k.bitLength() - 1; i >= 0; i--) {
            if (rz.signum() != 0 && ry.signum() != 0) {
                BigInteger ysq = ry.multiply(ry).mod(P);
                BigInteger s = rx.multiply(ysq).shiftLeft(2).mod(P);
                BigInteger m = rx.multiply(rx).multiply(THREE).mod(P);
                BigInteger x3 = m.multiply(m).subtract(s.shiftLeft(1)).mod(P);
                BigInteger y3 = m.multiply(s.subtract(x3)).subtract(ysq.multiply(ysq).shiftLeft(3)).mod(P);
                rz = ry.multiply(rz).shiftLeft(1).mod(P);
                rx = x3; ry = y3;
            }
            if (k.testBit(i)) {
                if (rz.signum() == 0) {
                    rx = GX; ry = GY; rz = BigInteger.ONE;
                } else {
                    BigInteger z1z1 = rz.multiply(rz).mod(P);
                    BigInteger u2 = GX.multiply(z1z1).mod(P);
                    BigInteger s2 = GY.multiply(rz).multiply(z1z1).mod(P);
                    BigInteger h = u2.subtract(rx).mod(P);
                    BigInteger r = s2.subtract(ry).mod(P);
                    BigInteger h2 = h.multiply(h).mod(P);
                    BigInteger h3 = h2.multiply(h).mod(P);
                    BigInteger u1h2 = rx.multiply(h2).mod(P);
                    BigInteger x3 = r.multiply(r).subtract(h3).subtract(u1h2.shiftLeft(1)).mod(P);
                    ry = r.multiply(u1h2.subtract(x3)).subtract(ry.multiply(h3)).mod(P);
                    rx = x3;
                    rz = rz.multiply(h).mod(P);
                }
            }
        }
        BigInteger zInv = rz.modInverse(P);
        return rx.multiply(zInv.multiply(zInv).mod(P)).mod(P);
    }

    /** Deterministic RFC 6979 low-S secp256k1 personal_sign (64-byte hex r||s). */
    public static String signPersonalMessage(byte[] priv32, String message) throws Exception {
        byte[] msgBytes = message.getBytes("UTF-8");
        byte[] prefix = ("\u0019Ethereum Signed Message:\n" + msgBytes.length).getBytes("UTF-8");
        byte[] digest32 = Keccak.hash256(concat(prefix, msgBytes));

        BigInteger d = new BigInteger(1, priv32);
        BigInteger z = new BigInteger(1, digest32);
        BigInteger halfN = N.shiftRight(1);

        byte[] v = new byte[32];
        Arrays.fill(v, (byte) 0x01);
        byte[] k = new byte[32];
        k = hmacSha256(k, concat(v, new byte[]{0x00}, priv32, digest32));
        v = hmacSha256(k, v);
        k = hmacSha256(k, concat(v, new byte[]{0x01}, priv32, digest32));
        v = hmacSha256(k, v);

        while (true) {
            v = hmacSha256(k, v);
            BigInteger kNum = new BigInteger(1, v);
            if (kNum.signum() > 0 && kNum.compareTo(N) < 0) {
                BigInteger r = gMulX(kNum).mod(N);
                if (r.signum() > 0) {
                    BigInteger s = kNum.modInverse(N).multiply(z.add(r.multiply(d))).mod(N);
                    if (s.signum() > 0) {
                        if (s.compareTo(halfN) > 0) s = N.subtract(s);
                        byte[] out = new byte[64];
                        writeFixed(r, out, 0, 32);
                        writeFixed(s, out, 32, 32);
                        return Hex.enc(out);
                    }
                }
            }
            k = hmacSha256(k, concat(v, new byte[]{0x00}));
            v = hmacSha256(k, v);
        }
    }

    private static String httpRequest(String url, String method, String jsonBody, String bearerToken, String idemKey) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod(method);
        conn.setConnectTimeout(10000);
        conn.setReadTimeout(15000);
        conn.setRequestProperty("Accept", "application/json");
        conn.setRequestProperty("User-Agent", "WalletYar-Connected/2.0.1");
        if (bearerToken != null && !bearerToken.isEmpty()) {
            conn.setRequestProperty("Authorization", "Bearer " + bearerToken);
        }
        if (idemKey != null && !idemKey.isEmpty()) {
            conn.setRequestProperty("X-Idempotency-Key", idemKey);
        }
        if (jsonBody != null) {
            conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
            conn.setDoOutput(true);
            try (OutputStream os = conn.getOutputStream()) {
                os.write(jsonBody.getBytes("UTF-8"));
            }
        }
        int code = conn.getResponseCode();
        InputStream is = (code >= 200 && code < 300) ? conn.getInputStream() : conn.getErrorStream();
        if (is == null) throw new RuntimeException("خطای ارتباط با سرور (HTTP " + code + ")");
        StringBuilder sb = new StringBuilder();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(is, "UTF-8"))) {
            String line;
            while ((line = r.readLine()) != null) sb.append(line);
        }
        String raw = sb.toString();
        if (code < 200 || code >= 300) {
            try {
                JSONObject errJson = new JSONObject(raw);
                if (errJson.has("error")) throw new RuntimeException(errJson.optString("error"));
            } catch (RuntimeException re) {
                throw re;
            } catch (Exception ignored) {}
            throw new RuntimeException("خطای سرور (HTTP " + code + ")");
        }
        return raw;
    }

    public static void connectWallet(final Context ctx, final Wallet wallet, final String agentCode, final JsonCb cb) {
        POOL.execute(() -> {
            try {
                Bip32.Node node = wallet.nodeFor(Coins.ETH, 0);
                String address = wallet.address(Coins.ETH, 0);
                String pubHex = Hex.enc(node.publicKeyCompressed());

                JSONObject chReq = new JSONObject().put("address", address);
                JSONObject chRes = new JSONObject(httpRequest(apiEndpoint(ctx, "auth.challenge"), "POST", chReq.toString(), null, null));
                if (!chRes.optBoolean("ok")) throw new RuntimeException(chRes.optString("error", "خطا در چالش سرور"));

                String sigHex = signPersonalMessage(node.key, chRes.getString("message"));

                JSONObject verReq = new JSONObject()
                        .put("address", address)
                        .put("nonce", chRes.getString("nonce"))
                        .put("public_key", pubHex)
                        .put("signature", sigHex)
                        .put("device_name", "Wallet Yar Android v2.0.1");
                if (agentCode != null && !agentCode.trim().isEmpty()) {
                    verReq.put("agent_code", agentCode.trim());
                }

                JSONObject verRes = new JSONObject(httpRequest(apiEndpoint(ctx, "auth.verify_signature"), "POST", verReq.toString(), null, null));
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
                JSONObject res = new JSONObject(httpRequest(apiEndpoint(ctx, "wallet.me"), "GET", null, bearer(ctx), null));
                if (!res.optBoolean("ok")) throw new RuntimeException(res.optString("error", "نشست منقضی است"));
                JSONObject w = res.optJSONObject("wallet");
                if (w != null) Store.put(ctx, "hub_cached_balance", w.optString("balance", "0"));
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
                JSONObject res = new JSONObject(httpRequest(apiEndpoint(ctx, "wallet.quote"), "POST", req.toString(), bearer(ctx), null));
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
                String sigHex = signPersonalMessage(node.key, canonical);

                JSONObject req = new JSONObject()
                        .put("to", to.trim())
                        .put("amount", normAmt)
                        .put("memo", memo)
                        .put("tx_nonce", txNonce)
                        .put("public_key", Hex.enc(node.publicKeyCompressed()))
                        .put("signature", sigHex);

                JSONObject res = new JSONObject(httpRequest(
                        apiEndpoint(ctx, "wallet.transfer"), "POST", req.toString(), bearer(ctx), "android-" + UUID.randomUUID()
                ));
                if (!res.optBoolean("ok")) throw new RuntimeException(res.optString("error", "خطا در انتقال توکن"));
                UI.post(() -> cb.done(res, null));
            } catch (Exception e) {
                UI.post(() -> cb.done(null, e.getMessage()));
            }
        });
    }

    public static void getPublic(final Context ctx, final String actionAndQuery, final JsonCb cb) {
        POOL.execute(() -> {
            try {
                JSONObject res = new JSONObject(httpRequest(apiEndpoint(ctx, actionAndQuery), "GET", null, bearer(ctx), null));
                if (!res.optBoolean("ok")) throw new RuntimeException(res.optString("error", "خطا در دریافت اطلاعات"));
                UI.post(() -> cb.done(res, null));
            } catch (Exception e) {
                UI.post(() -> cb.done(null, e.getMessage()));
            }
        });
    }

    public static void postAction(final Context ctx, final String action, final JSONObject body, final JsonCb cb) {
        POOL.execute(() -> {
            try {
                JSONObject res = new JSONObject(httpRequest(
                        apiEndpoint(ctx, action), "POST", body.toString(), bearer(ctx), "act-" + UUID.randomUUID()
                ));
                if (!res.optBoolean("ok")) throw new RuntimeException(res.optString("error", "عملیات ناموفق"));
                UI.post(() -> cb.done(res, null));
            } catch (Exception e) {
                UI.post(() -> cb.done(null, e.getMessage()));
            }
        });
    }
}
