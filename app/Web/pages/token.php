<?php
declare(strict_types=1);

use App\Audit;
use App\Csrf;
use App\Settings;
use App\TokenEngine;
use App\Users;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    try {
        $act = (string) ($_POST['form_action'] ?? '');
        if ($act === 'mint') {
            $targetRaw = trim((string) ($_POST['target'] ?? ''));
            $target = $targetRaw !== '' ? Users::resolveRecipient($targetRaw) : Users::systemAccount('treasury');
            if (!$target) {
                throw new \RuntimeException('کیف پول مقصد یافت نشد.');
            }
            $tx = TokenEngine::mint(
                (int) $target['id'],
                (string) ($_POST['amount'] ?? '0'),
                (int) $admin['id'],
                (string) ($_POST['memo'] ?? 'تولید توکن جدید توسط مدیر کل')
            );
            flash_set('success', 'ضرب توکن با موفقیت انجام شد! هش زنجیره: ' . $tx['tx_hash']);
        } elseif ($act === 'burn') {
            $treasury = Users::systemAccount('treasury');
            $tx = TokenEngine::burn(
                (int) $treasury['id'],
                (string) ($_POST['amount'] ?? '0'),
                (int) $admin['id'],
                (string) ($_POST['memo'] ?? 'توکن‌سوزی از خزانه مرکزی')
            );
            flash_set('success', 'توکن‌سوزی دائمی ثبت شد! هش زنجیره: ' . $tx['tx_hash']);
        } elseif ($act === 'withdraw_fees') {
            $feeVault = Users::systemAccount('fee_vault');
            $dest = Users::resolveRecipient((string) ($_POST['target'] ?? ''));
            if (!$dest) throw new \RuntimeException('کیف پول مقصد یافت نشد.');
            $res = TokenEngine::transfer((int) $feeVault['id'], (int) $dest['id'], (string) ($_POST['amount'] ?? '0'), 'admin', 'انتقال درآمد کارمزد شبکه', null, true);
            flash_set('success', 'درآمد کارمزد به کیف پول مقصد منتقل شد: ' . fmt_token($res['tx']['net']));
        } elseif ($act === 'save_economics') {
            Settings::setMany([
                'token_name'             => trim((string) ($_POST['token_name'] ?? 'یارکوین')),
                'token_name_en'          => trim((string) ($_POST['token_name_en'] ?? 'YarCoin')),
                'token_symbol'           => strtoupper(trim((string) ($_POST['token_symbol'] ?? 'YAR'))),
                'token_decimals_display' => max(0, min(8, (int) ($_POST['token_decimals_display'] ?? 4))),
                'token_max_supply'       => dec_norm($_POST['token_max_supply'] ?? '0'),
                'token_price_irt'        => dec_norm($_POST['token_price_irt'] ?? '8500'),
                'token_price_usd'        => dec_norm($_POST['token_price_usd'] ?? '0.10'),
                'signup_welcome_tokens'  => dec_norm($_POST['signup_welcome_tokens'] ?? '0'),
                'transfers_paused'       => !empty($_POST['transfers_paused']) ? '1' : '0',

                'fee_mode'               => in_array($_POST['fee_mode'] ?? '', ['zero','fixed','percentage','hybrid'], true) ? $_POST['fee_mode'] : 'hybrid',
                'fee_bps'                => max(0, min(10000, (int) ($_POST['fee_bps'] ?? 100))),
                'fee_fixed'              => dec_norm($_POST['fee_fixed'] ?? '0'),
                'fee_min'                => dec_norm($_POST['fee_min'] ?? '0'),
                'fee_max'                => dec_norm($_POST['fee_max'] ?? '0'),
                'fee_payer_mode'         => ($_POST['fee_payer_mode'] ?? '') === 'deduct_from_amount' ? 'deduct_from_amount' : 'sender_extra',
                'fee_burn_share_bps'     => max(0, min(10000, (int) ($_POST['fee_burn_share_bps'] ?? 0))),
                'vip_discount_bps'       => max(0, min(10000, (int) ($_POST['vip_discount_bps'] ?? 5000))),
                'group_tip_fee_bps'      => max(0, min(10000, (int) ($_POST['group_tip_fee_bps'] ?? 0))),
                'min_transfer_amount'    => dec_norm($_POST['min_transfer_amount'] ?? '0.01'),
                'max_single_transfer'    => dec_norm($_POST['max_single_transfer'] ?? '500000'),
            ]);
            Audit::record('admin', (int) $admin['id'], 'token.settings_updated', 'فرمول کارمزد، نرخ مرجع و سیاست پولی توکن به‌روزرسانی شد');
            flash_set('success', 'تنظیمات توکن، سقف عرضه و فرمول محاسبه کارمزد ذخیره شد!');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to(base_url('admin/?r=token'));
}

$sup = TokenEngine::supplyOverview();
?>
<div class="grid grid-3" style="margin-bottom:18px;">
    <div class="card" style="margin-bottom:0;">
        <h3 class="card-title" style="margin-bottom:12px;">➕ تولید توکن جدید (Mint)</h3>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="mint">
            <div class="field">
                <label>مقصد واریز (خالی = خزانه مرکزی Treasury)</label>
                <input name="target" class="ltr" placeholder="YAR-TREASURY یا کد/آدرس کاربر">
            </div>
            <div class="field">
                <label>مقدار تولید جدید</label>
                <input name="amount" class="ltr" placeholder="50000" required>
            </div>
            <div class="field">
                <label>علت رسمی در دفتر کل شفافیت</label>
                <input name="memo" value="افزایش نقدینگی خزانه توسط مدیر کل">
            </div>
            <button class="btn btn-primary" style="width:100%;" type="submit">💎 ضرب و ثبت در زنجیره</button>
        </form>
    </div>

    <div class="card" style="margin-bottom:0;">
        <h3 class="card-title" style="margin-bottom:12px;">🔥 توکن‌سوزی از خزانه (Burn)</h3>
        <p style="font-size:12.5px; color:#64748b; margin-top:0;">موجودی فعلی خزانه: <b><?= fmt_token($sup['treasury_balance']) ?></b></p>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="burn">
            <div class="field">
                <label>مقدار سوزاندن دائمی به 0x...dEaD</label>
                <input name="amount" class="ltr" placeholder="1000" required>
            </div>
            <div class="field">
                <label>توضیح عملیات توکن‌سوزی</label>
                <input name="memo" value="سیاست ضدتورمی کاهش عرضه">
            </div>
            <button class="btn btn-danger" style="width:100%;" type="submit">🔥 سوزاندن دائمی توکن</button>
        </form>
    </div>

    <div class="card" style="margin-bottom:0;">
        <h3 class="card-title" style="margin-bottom:12px;">💰 برداشت درآمد کارمزد شبکه</h3>
        <p style="font-size:12.5px; color:#64748b; margin-top:0;">موجودی صندوق کارمزد: <b><?= fmt_token($sup['fee_vault_balance']) ?></b></p>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="withdraw_fees">
            <div class="field">
                <label>انتقال به کیف پول (YAR-... یا 0x...)</label>
                <input name="target" class="ltr" placeholder="YAR-TREASURY" required>
            </div>
            <div class="field">
                <label>مبلغ برداشت</label>
                <input name="amount" class="ltr" required>
            </div>
            <button class="btn btn-indigo" style="width:100%;" type="submit">تسویه صندوق کارمزد</button>
        </form>
    </div>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:14px;">⚖️ موتور محاسبه کارمزد، نرخ مرجع و سیاست پولی مدیر کل</h3>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="save_economics">

        <div class="form-grid">
            <div class="field"><label>نام فارسی کوین</label><input name="token_name" value="<?= e(Settings::get('token_name')) ?>" required></div>
            <div class="field"><label>نام انگلیسی (EVM)</label><input name="token_name_en" class="ltr" value="<?= e(Settings::get('token_name_en')) ?>" required></div>
            <div class="field"><label>نماد (Symbol)</label><input name="token_symbol" class="ltr" value="<?= e(Settings::get('token_symbol')) ?>" required></div>
            <div class="field"><label>ارقام اعشار نمایشی (۰ تا ۸)</label><input type="number" name="token_decimals_display" class="ltr" value="<?= e(Settings::get('token_decimals_display', '4')) ?>"></div>

            <div class="field"><label>نرخ مرجع هر واحد به تومان (IRT)</label><input name="token_price_irt" class="ltr" value="<?= e(Settings::get('token_price_irt')) ?>"></div>
            <div class="field"><label>نرخ مرجع به دلار (USD)</label><input name="token_price_usd" class="ltr" value="<?= e(Settings::get('token_price_usd')) ?>"></div>
            <div class="field"><label>سقف نهایی کل عرضه (۰ = نامحدود)</label><input name="token_max_supply" class="ltr" value="<?= e(Settings::get('token_max_supply')) ?>"></div>
            <div class="field"><label>هدیه خوش‌آمدگویی ثبت‌نام</label><input name="signup_welcome_tokens" class="ltr" value="<?= e(Settings::get('signup_welcome_tokens')) ?>"></div>

            <div class="field">
                <label>مدل محاسبه کارمزد انتقال</label>
                <select id="fee_mode" name="fee_mode">
                    <?php foreach ([
                        'hybrid'     => 'ترکیبی هوشمند (کارمزد ثابت + درصدی)',
                        'percentage' => 'فقط درصدی (Basis Points)',
                        'fixed'      => 'فقط مبلغ ثابت',
                        'zero'       => 'بدون کارمزد (۰٪)',
                    ] as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= Settings::get('fee_mode') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>نرخ درصدی کارمزد (BPS: 100 = 1%)</label>
                <input id="fee_bps" name="fee_bps" class="ltr" value="<?= e(Settings::get('fee_bps')) ?>">
            </div>
            <div class="field">
                <label>مبلغ کارمزد ثابت پایه</label>
                <input id="fee_fixed" name="fee_fixed" class="ltr" value="<?= e(Settings::get('fee_fixed')) ?>">
            </div>
            <div class="field">
                <label>نحوه کسر کارمزد از تراکنش</label>
                <select id="fee_payer_mode" name="fee_payer_mode">
                    <option value="sender_extra" <?= Settings::get('fee_payer_mode') === 'sender_extra' ? 'selected' : '' ?>>کسر کارمزد علاوه بر مبلغ از فرستنده (گیرنده کامل می‌گیرد)</option>
                    <option value="deduct_from_amount" <?= Settings::get('fee_payer_mode') === 'deduct_from_amount' ? 'selected' : '' ?>>کسر از داخل مبلغ ارسالی (مشابه CommunityToken.sol)</option>
                </select>
            </div>

            <div class="field"><label>کف کارمزد (Minimum Fee)</label><input id="fee_min" name="fee_min" class="ltr" value="<?= e(Settings::get('fee_min')) ?>"></div>
            <div class="field"><label>سقف کارمزد (Maximum Fee)</label><input id="fee_max" name="fee_max" class="ltr" value="<?= e(Settings::get('fee_max')) ?>"></div>
            <div class="field"><label>سهم توکن‌سوزی خودکار از کارمزد (2000 = 20%)</label><input id="fee_burn_share_bps" name="fee_burn_share_bps" class="ltr" value="<?= e(Settings::get('fee_burn_share_bps')) ?>"></div>
            <div class="field"><label>کارمزد انعام داخل گروه‌ها (BPS: 0 = رایگان)</label><input name="group_tip_fee_bps" class="ltr" value="<?= e(Settings::get('group_tip_fee_bps')) ?>"></div>

            <div class="field"><label>تخفیف کارمزد کاربران VIP (5000 = 50%)</label><input name="vip_discount_bps" class="ltr" value="<?= e(Settings::get('vip_discount_bps')) ?>"></div>
            <div class="field"><label>حداقل مبلغ هر انتقال</label><input name="min_transfer_amount" class="ltr" value="<?= e(Settings::get('min_transfer_amount')) ?>"></div>
            <div class="field"><label>سقف هر انتقال تکی</label><input name="max_single_transfer" class="ltr" value="<?= e(Settings::get('max_single_transfer')) ?>"></div>
            <div class="field" style="display:flex; align-items:flex-end;">
                <label style="display:flex; align-items:center; gap:8px; color:#dc2626; cursor:pointer;">
                    <input type="checkbox" name="transfers_paused" value="1" <?= Settings::bool('transfers_paused') ? 'checked' : '' ?> style="width:auto;">
                    🛑 کلید اضطراری توقف موقت انتقال‌ها (Circuit Breaker)
                </label>
            </div>
        </div>

        <div class="alert alert-info" style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-top:8px;">
            <div>
                <b>🧮 شبیه‌ساز زنده کارمزد قبل از ذخیره:</b>
                <span id="sim_result" style="margin-right:8px;">مبلغ نمونه را تغییر دهید تا خروجی دقیق دیده شود.</span>
            </div>
            <input id="sim_amount" type="number" value="1000" class="ltr" style="width:140px; padding:6px 10px; border-radius:8px; border:1px solid #93c5fd;">
        </div>

        <button class="btn btn-primary" type="submit">💾 ذخیره قوانین کارمزد و سیاست پولی شبکه</button>
    </form>
</div>
