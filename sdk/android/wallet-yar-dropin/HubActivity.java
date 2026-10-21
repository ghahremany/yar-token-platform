package ir.ghahremani.wallet;

import android.text.InputType;
import android.view.View;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import ir.ghahremani.wallet.core.Wallet;

/**
 * Companion Android Activity for Wallet Yar:
 * Custom Token Balance, Live Fee Quote, ECDSA Signed Transfers, Loan Repayments & Representative Desk.
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
        header("توکن اختصاصی، انتقال و وام");

        if (state != null) {
            JSONObject w = state.optJSONObject("wallet");
            JSONObject val = state.optJSONObject("valuation");
            if (w != null) {
                LinearLayout hero = Ui.card(this);
                hero.addView(Ui.label(this, "موجودی تاییدشده کیف پول غیرحضانتی (ECDSA)"));
                Ui.space(hero, this, 6);
                hero.addView(Ui.tv(this, w.optString("balance", "0") + " توکن", 24, Theme.GREEN, App.bold()));
                if (val != null) {
                    hero.addView(Ui.tv(this, "معادل تومانی: " + Ui.fa(String.valueOf((long) val.optDouble("balance_irt", 0))) + " تومان", 12, Theme.DIM, App.reg()));
                }
                Ui.space(hero, this, 6);
                hero.addView(Ui.tv(this, "کد کیف پول: " + w.optString("wallet_code", "") + "  |  امتیاز اعتباری: " + w.optString("credit_score", "650"), 12, Theme.TEXT, App.med()));
                add(root, hero, 8);
            }
        }

        LinearLayout sendCard = Ui.card(this);
        sendCard.addView(Ui.tv(this, "⬆ انتقال آنی توکن (با محاسبه کارمزد سرور)", 16, Theme.TEXT, App.bold()));
        Ui.space(sendCard, this, 10);
        final EditText toInput = input("آدرس 0x... یا کد YAR-... یا نام کاربری", "", true);
        final EditText amtInput = input("مقدار توکن (مثال: 50)", "", true);
        final EditText memoInput = input("توضیحات تراکنش (اختیاری)", "", false);
        final TextView feePreview = Ui.tv(this, "پیش از ارسال می‌توانید کارمزد دقیق را استعلام بگیرید.", 12, Theme.AMBER, App.reg());

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

        JSONObject agentObj = state == null ? null : state.optJSONObject("agent");
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
                            .put("amount", loanAmtIn.getText().toString())
                            .put("installments_count", Integer.parseInt(instCountIn.getText().toString().trim()));
                    HubClient.postAction(this, "agent.grant_loan", req, (r, err) -> {
                        toast(err != null ? err : "🏦 وام صادر و به کیف پول کاربر واریز شد!");
                        if (err == null) refreshState();
                    });
                } catch (Exception e) { toast(e.getMessage()); }
            });
            agCard.addView(grantBtn);
            add(root, agCard, 12);
        }

        if (state != null) {
            JSONArray loans = state.optJSONArray("active_loans");
            if (loans != null && loans.length() > 0) {
                LinearLayout lnCard = Ui.card(this);
                lnCard.addView(Ui.tv(this, "🏦 اقساط و وام‌های فعال من", 16, Theme.TEXT, App.bold()));
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

        LinearLayout srv = Ui.card(this);
        srv.addView(Ui.tv(this, "🌐 اتصال به سرور توکن و ربات‌های اجتماعی", 16, Theme.TEXT, App.bold()));
        Ui.space(srv, this, 8);
        final EditText urlIn = input("https://your-server-domain.ir", HubClient.serverUrl(this), true);
        final EditText agCodeIn = input("کد معرف نماینده (اختیاری: AG-...)", "", true);
        srv.addView(urlIn);
        Ui.space(srv, this, 8);
        srv.addView(agCodeIn);
        Ui.space(srv, this, 10);

        TextView connectBtn = primaryButton("اتصال امن با امضای کلید خصوصی (ECDSA)");
        connectBtn.setOnClickListener(v -> {
            String u = urlIn.getText().toString().trim();
            HubClient.setServerUrl(this, u);
            if (!u.isEmpty()) Store.put(this, "rpc_eth", u + "/rpc.php");
            HubClient.connectWallet(this, wallet, agCodeIn.getText().toString(), (res, err) -> {
                if (err != null) { toast("❌ " + err); return; }
                toast("✅ اتصال غیرحضانتی کیف پول به سرور برقرار شد!");
                refreshState();
            });
        });
        srv.addView(connectBtn);
        Ui.space(srv, this, 8);

        TextView pairBtn = ghostButton("دریافت کد ۶ رقمی اتصال به ربات بله / تلگرام");
        pairBtn.setOnClickListener(v -> HubClient.postAction(this, "wallet.pair_code", new JSONObject(), (res, err) -> {
            if (err != null) { toast(err); return; }
            String code = res.optString("pair_code");
            Ui.copy(this, "/link " + code);
            toast("کپی شد: /link " + code + " — در ربات بله یا تلگرام ارسال کنید");
        }));
        srv.addView(pairBtn);
        add(root, srv, 12);
    }
}
