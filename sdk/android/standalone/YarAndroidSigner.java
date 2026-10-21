import java.io.ByteArrayOutputStream;
import java.math.BigInteger;
import java.nio.charset.StandardCharsets;
import java.text.Normalizer;
import java.util.Arrays;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/**
 * Clean-room zero-dependency Android / JVM non-custodial signer for YarToken Platform.
 * Derives standard BIP-39 -> BIP-32/44 m/44'/60'/0'/0/0 EIP-55 wallet keys
 * and produces deterministic RFC 6979 low-S secp256k1 personal_sign signatures.
 */
public final class YarAndroidSigner {

    public static final BigInteger P = new BigInteger("fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f", 16);
    public static final BigInteger N = new BigInteger("fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141", 16);
    private static final BigInteger GX = new BigInteger("79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798", 16);
    private static final BigInteger GY = new BigInteger("483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8", 16);
    private static final BigInteger THREE = BigInteger.valueOf(3);

    private static final long[] KECCAK_RC = {
        0x0000000000000001L, 0x0000000000008082L, 0x800000000000808AL, 0x8000000080008000L,
        0x000000000000808BL, 0x0000000080000001L, 0x8000000080008081L, 0x8000000000008009L,
        0x000000000000008AL, 0x0000000000000088L, 0x0000000080008009L, 0x000000008000000AL,
        0x000000008000808BL, 0x800000000000008BL, 0x8000000000008089L, 0x8000000000008003L,
        0x8000000000008002L, 0x8000000000000080L, 0x000000000000800AL, 0x800000008000000AL,
        0x8000000080008081L, 0x8000000000008080L, 0x0000000080000001L, 0x8000000080008008L
    };
    private static final int[] KECCAK_ROTC = {1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 2, 14, 27, 41, 56, 8, 25, 43, 62, 18, 39, 61, 20, 44};
    private static final int[] KECCAK_PILN = {10, 7, 11, 17, 18, 3, 5, 16, 8, 21, 24, 4, 15, 23, 19, 13, 12, 2, 20, 14, 22, 9, 6, 1};

    private YarAndroidSigner() {}

    public static byte[] keccak256(byte[] in) {
        long[] st = new long[25];
        byte[] block = new byte[136];
        int pos = 0;
        for (byte b : in) {
            block[pos++] = b;
            if (pos == 136) { absorb(st, block); pos = 0; }
        }
        Arrays.fill(block, pos, 136, (byte) 0);
        block[pos] = 0x01;
        block[135] |= (byte) 0x80;
        absorb(st, block);
        byte[] out = new byte[32];
        for (int i = 0; i < 4; i++) {
            long v = st[i];
            for (int j = 0; j < 8; j++) out[i * 8 + j] = (byte) (v >>> (8 * j));
        }
        return out;
    }

    private static void absorb(long[] st, byte[] block) {
        for (int i = 0; i < 136; i++) st[i >> 3] ^= ((long) (block[i] & 0xff)) << (8 * (i & 7));
        long[] bc = new long[5];
        for (int r = 0; r < 24; r++) {
            for (int i = 0; i < 5; i++) bc[i] = st[i] ^ st[i + 5] ^ st[i + 10] ^ st[i + 15] ^ st[i + 20];
            for (int i = 0; i < 5; i++) {
                long t = bc[(i + 4) % 5] ^ Long.rotateLeft(bc[(i + 1) % 5], 1);
                for (int j = 0; j < 25; j += 5) st[j + i] ^= t;
            }
            long t = st[1];
            for (int i = 0; i < 24; i++) {
                int j = KECCAK_PILN[i];
                long tmp = st[j];
                st[j] = Long.rotateLeft(t, KECCAK_ROTC[i]);
                t = tmp;
            }
            for (int j = 0; j < 25; j += 5) {
                for (int i = 0; i < 5; i++) bc[i] = st[j + i];
                for (int i = 0; i < 5; i++) st[j + i] ^= (~bc[(i + 1) % 5]) & bc[(i + 2) % 5];
            }
            st[0] ^= KECCAK_RC[r];
        }
    }

    private static byte[] hmac(String algo, byte[] key, byte[] data) {
        try {
            Mac m = Mac.getInstance(algo);
            m.init(new SecretKeySpec(key, algo));
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

    private static String hex(byte[] b) {
        StringBuilder sb = new StringBuilder(b.length * 2);
        for (byte x : b) sb.append(String.format("%02x", x & 0xff));
        return sb.toString();
    }

    private static BigInteger[] gMul(BigInteger k) {
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
        BigInteger zInv2 = zInv.multiply(zInv).mod(P);
        return new BigInteger[]{rx.multiply(zInv2).mod(P), ry.multiply(zInv2).multiply(zInv).mod(P)};
    }

    public static byte[] bip39Seed(String mnemonic, String passphrase) {
        byte[] pw = Normalizer.normalize(mnemonic, Normalizer.Form.NFKD).getBytes(StandardCharsets.UTF_8);
        byte[] salt = Normalizer.normalize("mnemonic" + passphrase, Normalizer.Form.NFKD).getBytes(StandardCharsets.UTF_8);
        byte[] u = hmac("HmacSHA512", pw, concat(salt, new byte[]{0, 0, 0, 1}));
        byte[] t = u.clone();
        for (int j = 1; j < 2048; j++) {
            u = hmac("HmacSHA512", pw, u);
            for (int k = 0; k < 64; k++) t[k] ^= u[k];
        }
        return t;
    }

    public static byte[] deriveEthKey0(String mnemonic) {
        byte[] i64 = hmac("HmacSHA512", "Bitcoin seed".getBytes(StandardCharsets.UTF_8), bip39Seed(mnemonic, ""));
        byte[] key = Arrays.copyOfRange(i64, 0, 32);
        byte[] chain = Arrays.copyOfRange(i64, 32, 64);
        int[] path = {44 | 0x80000000, 60 | 0x80000000, 0x80000000, 0, 0};
        for (int idx : path) {
            byte[] data = new byte[37];
            if ((idx & 0x80000000) != 0) {
                data[0] = 0;
                System.arraycopy(key, 0, data, 1, 32);
            } else {
                BigInteger[] pt = gMul(new BigInteger(1, key));
                data[0] = (byte) (pt[1].testBit(0) ? 0x03 : 0x02);
                writeFixed(pt[0], data, 1, 32);
            }
            data[33] = (byte) (idx >>> 24);
            data[34] = (byte) (idx >>> 16);
            data[35] = (byte) (idx >>> 8);
            data[36] = (byte) idx;
            byte[] step = hmac("HmacSHA512", chain, data);
            BigInteger child = new BigInteger(1, Arrays.copyOfRange(step, 0, 32)).add(new BigInteger(1, key)).mod(N);
            writeFixed(child, key, 0, 32);
            chain = Arrays.copyOfRange(step, 32, 64);
        }
        return key;
    }

    public static String compressedPubHex(byte[] priv32) {
        BigInteger[] pt = gMul(new BigInteger(1, priv32));
        byte[] out = new byte[33];
        out[0] = (byte) (pt[1].testBit(0) ? 0x03 : 0x02);
        writeFixed(pt[0], out, 1, 32);
        return hex(out);
    }

    public static String eip55Address(byte[] priv32) {
        BigInteger[] pt = gMul(new BigInteger(1, priv32));
        byte[] uncomp = new byte[64];
        writeFixed(pt[0], uncomp, 0, 32);
        writeFixed(pt[1], uncomp, 32, 32);
        String raw = hex(Arrays.copyOfRange(keccak256(uncomp), 12, 32));
        String hash = hex(keccak256(raw.getBytes(StandardCharsets.UTF_8)));
        StringBuilder out = new StringBuilder("0x");
        for (int i = 0; i < 40; i++) {
            char c = raw.charAt(i);
            out.append(Character.digit(hash.charAt(i), 16) >= 8 ? Character.toUpperCase(c) : c);
        }
        return out.toString();
    }

    public static String signPersonalMessage(byte[] priv32, String message) {
        byte[] msgBytes = message.getBytes(StandardCharsets.UTF_8);
        byte[] prefix = ("\u0019Ethereum Signed Message:\n" + msgBytes.length).getBytes(StandardCharsets.UTF_8);
        byte[] digest32 = keccak256(concat(prefix, msgBytes));

        BigInteger d = new BigInteger(1, priv32);
        BigInteger z = new BigInteger(1, digest32);
        BigInteger halfN = N.shiftRight(1);

        byte[] v = new byte[32];
        Arrays.fill(v, (byte) 0x01);
        byte[] k = new byte[32];
        k = hmac("HmacSHA256", k, concat(v, new byte[]{0x00}, priv32, digest32));
        v = hmac("HmacSHA256", k, v);
        k = hmac("HmacSHA256", k, concat(v, new byte[]{0x01}, priv32, digest32));
        v = hmac("HmacSHA256", k, v);

        while (true) {
            v = hmac("HmacSHA256", k, v);
            BigInteger kNum = new BigInteger(1, v);
            if (kNum.signum() > 0 && kNum.compareTo(N) < 0) {
                BigInteger[] pt = gMul(kNum);
                BigInteger r = pt[0].mod(N);
                if (r.signum() > 0) {
                    BigInteger s = kNum.modInverse(N).multiply(z.add(r.multiply(d))).mod(N);
                    if (s.signum() > 0) {
                        if (s.compareTo(halfN) > 0) s = N.subtract(s);
                        byte[] out = new byte[64];
                        writeFixed(r, out, 0, 32);
                        writeFixed(s, out, 32, 32);
                        return hex(out);
                    }
                }
            }
            k = hmac("HmacSHA256", k, concat(v, new byte[]{0x00}));
            v = hmac("HmacSHA256", k, v);
        }
    }

    public static void main(String[] args) {
        String mnemonic = args[0];
        String message = args[1];
        byte[] priv = deriveEthKey0(mnemonic);
        System.out.println(eip55Address(priv) + "|" + compressedPubHex(priv) + "|" + signPersonalMessage(priv, message));
    }
}
