package ir.ghahremani.wallet;

import android.text.InputType;
import android.view.View;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import ir.ghahremani.wallet.core.Coins;
import ir.ghahremani.wallet.core.Wallet;

/**
 * Companion Android Activity for Wallet Yar (v2.0.1):
 * - Non-Custodial ECDSA Auth & Pair Code
 * - Custom Token Balance & Live Fee Quote Transfers
 * - Token Payment Gateway (Pay INV-... & Create Merchant Invoice)
 * - Group & Web Red Packets (Claim PKT-... with Passcode & Create Lucky/Equal Packet)
 * - Representative Lending Desk & Loan Installment Repayment
 * - One-Click 7-Point Cryptographic Network Audit
 */
public class HubActivity extends BaseActivity {

    private Wallet wallet;
    private JSONObject state;
    private String txNonce = "0";

    @Override
    protected void build() {
        wallet = WalletCache.get(this);
        if (wallet == null) { finish(); return; }
        renderScreen();
        if (!HubClient.serverUrl(this).isEmpty() && !HubClient.bearer(this).isEmpty()) {
            refreshState();
        }
    }

    private void refreshState() {
        HubClient.fetchMe(this, (data, err) -> {
            if (data != null) {
                state = data;
                JSONObject wObj = data.optJSONObject("wallet");
                txNonce = wObj != null ? wObj.optString("tx_nonce", "0") : "0";
                renderScreen();
            } else if (err != null) {
                toast(err);
            }
        });
    }

    private EditText input(String hint, String initial, boolean ltr) {
        EditText e = new EditText(this);
        e.setHint(hint);
        e.setHintTextColor(Theme.DIM2);
        e.setTextColor(Theme.TEXT);
        e.setTypeface(App.med());
        e.setTextSize(14);
        e.setBackground(Ui.roundStroke(this, Theme.CARD2, Theme.LINE, 12));
        e.setPadding(Ui.dp(this, 12), Ui.dp(this, 10), Ui.dp(this, 12), Ui.dp(this, 10));
        if (initial != null) e.setText(initial);
        if (ltr) {
            e.setLayoutDirection(View.LAYOUT_DIRECTION_LTR);
            e.setTextDirection(View.TEXT_DIRECTION_LTR);
            e.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
        }
        return e;
    }

    private void renderScreen() {
        root.removeAllViews();
        header("شبکه توکن اختصاصی، درگاه و وام");

        String evmAddr = wallet.address(Coins.ETH, 0);

        // 1. Server Connection Card (Always reachable right at top if disconnected, or compact status when connected)
        LinearLayout srv = Ui.card(this);
        boolean connected = state != null && state.optJSONObject("wallet") != null;
        srv.addView(Ui.tv(this, connected ? "🟢 متصل به شبکه توکن اختصاصی" : "🌐 گام اول: اتصال غیرحضانتی به سرور توکن", 15,
                connected ? Theme.GREEN : Theme.TEXT, App.bold()));
        Ui.space(srv, this, 6);
        srv.addView(Ui.tv(this, "آدرس امضای کیف پول شما:\n" + evmAddr, 11, Theme.DIM, App.reg()));
        Ui.space(srv, this, 8);
        final EditText urlIn = input("https://your-domain.ir", HubClient.serverUrl(this), true);
        final EditText agCodeIn = input("کد معرف نماینده (اختیاری: AG-...)", "", true);
        srv.addView(urlIn);
        Ui.space(srv, this, 8);
        srv.addView(agCodeIn);
        Ui.space(srv, this, 10);

        TextView connectBtn = primaryButton(connected ? "بروزرسانی و تایید مجدد امضای ECDSA" : "اتصال امن با امضای کلید خصوصی (ECDSA)");
        connectBtn.setOnClickListener(v -> {
            String u = urlIn.getText().toString().trim();
            HubClient.setServerUrl(this, u);
            if (!u.isEmpty()) Store.put(this, "rpc_eth", u + "/rpc.php");
            HubClient.connectWallet(this, wallet, agCodeIn.getText().toString(), (res, err) -> {
                if (err != null) { toast("❌ " + err); return; }
                toast("✅ اتصال غیرحضانتی کیف پول برقرار شد!");
                refreshState();
            });
        });
        srv.addView(connectBtn);
        Ui.space(srv, this, 8);

        TextView pairBtn = ghostButton("کپی کد ۶ رقمی اتصال به ربات بله / تلگرام");
        pairBtn.setOnClickListener(v -> HubClient.postAction(this, "wallet.pair_code", new JSONObject(), (res, err) -> {
            if (err != null) { toast(err); return; }
            String code = res.optString("pair_code");
            Ui.copy(this, "/link " + code);
            toast("کپی شد: /link " + code + " — در ربات بله یا تلگرام ارسال کنید");
        }));
        srv.addView(pairBtn);
        add(root, srv, 8);

        // 2. Live Wallet Summary Card
        if (connected) {
            JSONObject w = state.optJSONObject("wallet");
            JSONObject val = state.optJSONObject("valuation");
            LinearLayout hero = Ui.card(this);
            hero.addView(Ui.label(this, "موجودی تاییدشده زنجیره (Non-Custodial ECDSA)"));
            Ui.space(hero, this, 6);
            hero.addView(Ui.tv(this, w.optString("balance", "0") + " توکن", 24, Theme.GREEN, App.bold()));
            if (val != null) {
                hero.addView(Ui.tv(this, "معادل تومانی: " + Ui.fa(String.valueOf((long) val.optDouble("balance_irt", 0))) + " تومان", 12, Theme.DIM, App.reg()));
            }
            Ui.space(hero, this, 6);
            hero.addView(Ui.tv(this, "کد کیف پول: " + w.optString("wallet_code", "") + "  |  امتیاز اعتباری: " + w.optString("credit_score", "650"), 12, Theme.TEXT, App.med()));
            add(root, hero, 12);
        }

        // 3. ECDSA Signed Transfer Card
        LinearLayout sendCard = Ui.card(this);
        sendCard.addView(Ui.tv(this, "⬆ انتقال آنی توکن (با امضای کلید خصوصی)", 16, Theme.TEXT, App.bold()));
        Ui.space(sendCard, this, 10);
        final EditText toInput = input("آدرس 0x... یا کد YAR-... یا نام کاربری", "", true);
        final EditText amtInput = input("مقدار توکن (مثال: 50)", "", true);
        final EditText memoInput = input("توضیحات تراکنش (اختیاری)", "", false);
        final TextView feePreview = Ui.tv(this, "پیش از ارسال می‌توانید کارمزد دقیق شبکه را استعلام بگیرید.", 12, Theme.AMBER, App.reg());

        sendCard.addView(toInput);
        Ui.space(sendCard, this, 8);
        sendCard.addView(amtInput);
        Ui.space(sendCard, this, 8);
        sendCard.addView(memoInput);
        Ui.space(sendCard, this, 8);
        sendCard.addView(feePreview);
        Ui.space(sendCard, this, 10);

        TextView quoteBtn = ghostButton("استعلام کارمزد شبکه");
        quoteBtn.setOnClickListener(v -> HubClient.quoteFee(this, toInput.getText().toString(), amtInput.getText().toString(), (res, err) -> {
            if (err != null) { toast(err); return; }
            JSONObject q = res.optJSONObject("quote");
            JSONObject rec = res.optJSONObject("recipient");
            if (q != null && rec != null) {
                feePreview.setText("گیرنده: " + rec.optString("full_name") + " | کارمزد: " + q.optString("fee_amount")
                        + " | جمع کسر: " + q.optString("sender_total_debit"));
            }
        }));
        sendCard.addView(quoteBtn);
        Ui.space(sendCard, this, 8);

        TextView sendBtn = primaryButton("امضای دیجیتال ECDSA و انتقال قطعی");
        sendBtn.setOnClickListener(v -> HubClient.sendSigned(
                this, wallet,
                toInput.getText().toString(),
                amtInput.getText().toString(),
                memoInput.getText().toString(),
                txNonce,
                (res, err) -> {
                    if (err != null) { toast(err); return; }
                    toast("✅ انتقال با موفقیت در زنجیره ثبت شد!");
                    refreshState();
                }
        ));
        sendCard.addView(sendBtn);
        add(root, sendCard, 12);

        // 4. Merchant Token Payment Gateway Card (Pay INV-... or Issue New Invoice)
        LinearLayout payCard = Ui.card(this);
        payCard.addView(Ui.tv(this, "💳 درگاه پرداخت هوشمند توکنی (IPG)", 16, Theme.TEXT, App.bold()));
        Ui.space(payCard, this, 8);
        final EditText invCodeIn = input("کد قبض برای پرداخت (INV-...)", "", true);
        payCard.addView(invCodeIn);
        Ui.space(payCard, this, 8);
        TextView payInvBtn = primaryButton("پرداخت آنی قبض فروشگاهی");
        payInvBtn.setOnClickListener(v -> {
            try {
                HubClient.postAction(this, "invoices.pay", new JSONObject().put("invoice_code", invCodeIn.getText().toString().trim()), (r, err) -> {
                    toast(err != null ? err : "✅ قبض فروشگاهی با موفقیت تسویه شد!");
                    if (err == null) refreshState();
                });
            } catch (Exception ignored) {}
        });
        payCard.addView(payInvBtn);
        Ui.space(payCard, this, 10);

        final EditText newInvAmt = input("مبلغ صدور فاکتور جدید فروشگاهی", "", true);
        final EditText newInvTitle = input("عنوان کالا / خدمات", "", false);
        payCard.addView(newInvAmt);
        Ui.space(payCard, this, 8);
        payCard.addView(newInvTitle);
        Ui.space(payCard, this, 8);
        TextView createInvBtn = ghostButton("صدور لینک درگاه پرداخت جدید");
        createInvBtn.setOnClickListener(v -> {
            try {
                JSONObject req = new JSONObject()
                        .put("amount", newInvAmt.getText().toString().trim())
                        .put("title", newInvTitle.getText().toString().trim());
                HubClient.postAction(this, "invoices.create", req, (r, err) -> {
                    if (err != null) { toast(err); return; }
                    JSONObject inv = r.optJSONObject("invoice");
                    String url = r.optString("pay_url", inv != null ? inv.optString("invoice_code") : "");
                    Ui.copy(this, url);
                    toast("✅ لینک درگاه ساخته و کپی شد: " + url);
                });
            } catch (Exception ignored) {}
        });
        payCard.addView(createInvBtn);
        add(root, payCard, 12);

        // 5. Group & Web Red Packets Card (Claim PKT-... or Create New Packet)
        LinearLayout pktCard = Ui.card(this);
        pktCard.addView(Ui.tv(this, "🧧 پاکت هدیه گروهی و شانسی", 16, Theme.AMBER, App.bold()));
        Ui.space(pktCard, this, 8);
        final EditText pktCodeIn = input("کد پاکت هدیه (PKT-...)", "", true);
        final EditText pktPassIn = input("رمز مخفی پاکت (در صورت وجود)", "", false);
        pktCard.addView(pktCodeIn);
        Ui.space(pktCard, this, 8);
        pktCard.addView(pktPassIn);
        Ui.space(pktCard, this, 8);

        TextView claimPktBtn = primaryButton("باز کردن پاکت هدیه و دریافت سهم 🎁");
        claimPktBtn.setOnClickListener(v -> {
            try {
                JSONObject req = new JSONObject()
                        .put("packet_code", pktCodeIn.getText().toString().trim())
                        .put("passcode", pktPassIn.getText().toString());
                HubClient.postAction(this, "packets.claim", req, (r, err) -> {
                    if (err != null) { toast(err); return; }
                    toast("🎉 تبریک! مبلغ " + r.optString("claimed_amount", "") + " توکن به حساب شما واریز شد!");
                    refreshState();
                });
            } catch (Exception ignored) {}
        });
        pktCard.addView(claimPktBtn);
        Ui.space(pktCard, this, 10);

        final EditText newPktAmt = input("مجموع مبلغ پاکت جدید (مثال: 100)", "", true);
        final EditText newPktCnt = input("تعداد نفرات برنده (مثال: 5)", "5", true);
        pktCard.addView(newPktAmt);
        Ui.space(pktCard, this, 8);
        pktCard.addView(newPktCnt);
        Ui.space(pktCard, this, 8);
        TextView createPktBtn = ghostButton("ساخت پاکت هدیه شانسی و کپی لینک");
        createPktBtn.setOnClickListener(v -> {
            try {
                JSONObject req = new JSONObject()
                        .put("total_amount", newPktAmt.getText().toString().trim())
                        .put("max_claims", Integer.parseInt(newPktCnt.getText().toString().trim()))
                        .put("mode", "lucky");
                HubClient.postAction(this, "packets.create", req, (r, err) -> {
                    if (err != null) { toast(err); return; }
                    String url = r.optString("claim_url", r.optString("packet_code"));
                    Ui.copy(this, url);
                    toast("🧧 پاکت هدیه ساخته و کپی شد: " + url);
                    refreshState();
                });
            } catch (Exception e) { toast("لطفاً مبلغ و تعداد معتبر وارد کنید"); }
        });
        pktCard.addView(createPktBtn);
        add(root, pktCard, 12);

        // 6. Active Loans & Representative Desk
        if (state != null) {
            JSONObject agentObj = state.optJSONObject("agent");
            if (agentObj != null) {
                LinearLayout agCard = Ui.card(this);
                agCard.addView(Ui.tv(this, "🤝 میز کار نماینده وام‌دهنده", 16, Theme.GREEN, App.bold()));
                Ui.space(agCard, this, 6);
                agCard.addView(Ui.tv(this, "اعتبار آزاد وام‌دهی: " + agentObj.optString("available_pool", "0")
                        + " | زیرمجموعه‌ها: " + agentObj.optInt("subusers_count", 0) + " نفر", 12, Theme.DIM, App.med()));
                Ui.space(agCard, this, 10);

                final EditText subIdIn = input("کد کیف پول زیرمجموعه (YAR-...)", "", true);
                final EditText loanAmtIn = input("مبلغ اصل وام", "", true);
                final EditText instCountIn = input("تعداد اقساط (مثال: 3)", "3", true);

                agCard.addView(subIdIn);
                Ui.space(agCard, this, 8);
                agCard.addView(loanAmtIn);
                Ui.space(agCard, this, 8);
                agCard.addView(instCountIn);
                Ui.space(agCard, this, 10);

                TextView addSubBtn = ghostButton("افزودن کاربر به زیرمجموعه‌های من");
                addSubBtn.setOnClickListener(v -> {
                    try {
                        HubClient.postAction(this, "agent.add_subuser", new JSONObject().put("identifier", subIdIn.getText().toString()), (r, err) -> {
                            toast(err != null ? err : "✅ کاربر به زیرمجموعه‌های شما اضافه شد");
                            if (err == null) refreshState();
                        });
                    } catch (Exception ignored) {}
                });
                agCard.addView(addSubBtn);
                Ui.space(agCard, this, 8);

                TextView grantBtn = primaryButton("اعطای وام اقساطی به زیرمجموعه");
                grantBtn.setOnClickListener(v -> {
                    try {
                        JSONObject req = new JSONObject()
                                .put("borrower", subIdIn.getText().toString())
                                .put("principal", loanAmtIn.getText().toString())
                                .put("installments_count", Integer.parseInt(instCountIn.getText().toString().trim()))
                                .put("funding_source", "credit_pool");
                        HubClient.postAction(this, "agent.grant_loan", req, (r, err) -> {
                            toast(err != null ? err : "✅ وام اقساطی با موفقیت اعطا شد!");
                            if (err == null) refreshState();
                        });
                    } catch (Exception e) { toast("اطلاعات وام معتبر نیست"); }
                });
                agCard.addView(grantBtn);
                add(root, agCard, 12);
            }

            JSONArray loans = state.optJSONArray("active_loans");
            if (loans != null && loans.length() > 0) {
                LinearLayout lnCard = Ui.card(this);
                lnCard.addView(Ui.tv(this, "🏦 اقساط و تسهیلات فعال من", 16, Theme.TEXT, App.bold()));
                Ui.space(lnCard, this, 8);
                for (int i = 0; i < loans.length(); i++) {
                    JSONObject ln = loans.optJSONObject(i);
                    if (ln == null) continue;
                    final int loanId = ln.optInt("id");
                    lnCard.addView(Ui.tv(this, ln.optString("loan_code") + " — مانده: " + ln.optString("remaining_amount"), 13, Theme.TEXT, App.semi()));
                    Ui.space(lnCard, this, 6);
                    TextView payBtn = ghostButton("پرداخت قسط بعدی (" + ln.optString("loan_code") + ")");
                    payBtn.setOnClickListener(v -> {
                        try {
                            HubClient.postAction(this, "loans.repay", new JSONObject().put("loan_id", loanId), (r, err) -> {
                                toast(err != null ? err : "✅ قسط با موفقیت تسویه شد!");
                                if (err == null) refreshState();
                            });
                        } catch (Exception ignored) {}
                    });
                    lnCard.addView(payBtn);
                    Ui.space(lnCard, this, 8);
                }
                add(root, lnCard, 12);
            }
        }

        // 7. One-Click Proof-of-Reserve Audit Card
        LinearLayout audCard = Ui.card(this);
        audCard.addView(Ui.tv(this, "🛡️ ابزار ممیزی و اثبات ذخیره یک‌کلیکی", 15, Theme.TEXT, App.bold()));
        Ui.space(audCard, this, 6);
        final TextView audStatus = Ui.tv(this, "بررسی صحت زنجیره هش SHA-256، عرضه کل، حساب امانی و وام‌ها", 12, Theme.DIM, App.reg());
        audCard.addView(audStatus);
        Ui.space(audCard, this, 8);
        TextView audBtn = ghostButton("اجرای ممیزی یک‌کلیکی شبکه 🔍");
        audBtn.setOnClickListener(v -> HubClient.getPublic(this, "audit.run", (res, err) -> {
            if (err != null) { toast(err); return; }
            JSONObject rep = res.optJSONObject("report");
            if (rep != null) {
                boolean ok = rep.optBoolean("healthy");
                audStatus.setTextColor(ok ? Theme.GREEN : Theme.RED);
                audStatus.setText((ok ? "✅ تایید کامل ۷ شاخص زنجیره! " : "❌ مغایرت شناسایی شد! ")
                        + "عرضه خالص: " + rep.optJSONObject("summary").optString("net_supply", "0")
                        + " | زمان بررسی: " + rep.optDouble("elapsed_ms", 0) + "ms");
            }
        }));
        audCard.addView(audBtn);
        add(root, audCard, 12);
    }
}
