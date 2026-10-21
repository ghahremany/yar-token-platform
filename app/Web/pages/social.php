<?php
declare(strict_types=1);

use App\Audit;
use App\Config;
use App\Csrf;
use App\Db;
use App\Settings;
use App\Social\MessengerClient;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::assertPost();
    try {
        $act = (string) ($_POST['form_action'] ?? '');
        if ($act === 'save_social') {
            $pairs = [
                'bale_enabled'               => !empty($_POST['bale_enabled']) ? '1' : '0',
                'telegram_enabled'           => !empty($_POST['telegram_enabled']) ? '1' : '0',
                'telegram_api_base_override' => trim((string) ($_POST['telegram_api_base_override'] ?? '')),
                'public_alert_bale_chat'     => trim((string) ($_POST['public_alert_bale_chat'] ?? '')),
                'public_alert_tg_chat'       => trim((string) ($_POST['public_alert_tg_chat'] ?? '')),
                'whale_alert_threshold'      => dec_norm($_POST['whale_alert_threshold'] ?? '1000'),
                'bridge_outbound_url'        => trim((string) ($_POST['bridge_outbound_url'] ?? '')),
                'bot_welcome_text'           => trim((string) ($_POST['bot_welcome_text'] ?? '')),
            ];
            if (trim((string) ($_POST['bale_bot_token'] ?? '')) !== '') {
                $pairs['bale_bot_token_enc'] = encrypt_secret(trim((string) $_POST['bale_bot_token']));
            }
            if (trim((string) ($_POST['telegram_bot_token'] ?? '')) !== '') {
                $pairs['telegram_bot_token_enc'] = encrypt_secret(trim((string) $_POST['telegram_bot_token']));
            }
            Settings::setMany($pairs);
            Audit::record('admin', (int) $admin['id'], 'social.updated', 'تنظیمات ربات‌های بله، تلگرام و پل ارتباطی ذخیره شد');
            flash_set('success', 'تنظیمات پیام‌رسان‌ها و کانال‌های اعلان ذخیره شد.');
        } elseif ($act === 'set_webhooks') {
            $sec = (string) Config::get('webhook_secret', '');
            $msgs = [];
            if (MessengerClient::tokenFor('bale') !== '') {
                $r1 = MessengerClient::call('bale', 'setWebhook', ['url' => base_url('webhooks/bale.php'), 'secret_token' => $sec]);
                $msgs[] = 'بله: ' . (!empty($r1['ok']) ? 'متصل شد ✓' : ($r1['description'] ?? 'خطا'));
            }
            if (MessengerClient::tokenFor('telegram') !== '') {
                $r2 = MessengerClient::call('telegram', 'setWebhook', ['url' => base_url('webhooks/telegram.php'), 'secret_token' => $sec]);
                $msgs[] = 'تلگرام: ' . (!empty($r2['ok']) ? 'متصل شد ✓' : ($r2['description'] ?? 'خطا'));
            }
            flash_set('success', $msgs ? implode(' | ', $msgs) : 'ابتدا توکن حداقل یکی از ربات‌ها را ذخیره کنید.');
        } elseif ($act === 'update_group') {
            Db::pdo()->prepare(
                'UPDATE social_groups
                 SET tipping_enabled = ?, red_packets_enabled = ?, chat_reward_enabled = ?,
                     chat_reward_amount = ?, daily_user_cap = ?, is_active = ?
                 WHERE id = ?'
            )->execute([
                !empty($_POST['tipping_enabled']) ? 1 : 0,
                !empty($_POST['red_packets_enabled']) ? 1 : 0,
                !empty($_POST['chat_reward_enabled']) ? 1 : 0,
                dec_norm($_POST['chat_reward_amount'] ?? '0.5'),
                dec_norm($_POST['daily_user_cap'] ?? '10'),
                !empty($_POST['is_active']) ? 1 : 0,
                (int) ($_POST['group_id'] ?? 0),
            ]);
            flash_set('success', 'سیاست‌های اقتصادی گروه به‌روزرسانی شد!');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect_to(base_url('admin/?r=social'));
}

$groups = Db::pdo()->query('SELECT * FROM social_groups ORDER BY id DESC')->fetchAll();
$bridgeSecret = decrypt_secret(Settings::get('bridge_hmac_secret_enc', '')) ?: (string) Config::get('bridge_hmac_secret', '');
?>
<div class="card">
    <div class="card-head">
        <div>
            <h3 class="card-title">💬 اتصال چندسکویی شبکه‌های اجتماعی (بله، تلگرام، گروه‌ها و پل n8n/روبیکا/ایتا)</h3>
            <small style="color:#64748b;">پشتیبانی کامل از ربات خصوصی + اقتصاد درون‌گروهی (انعام ریپلای، پاکت هدیه شانسی و پاداش مشارکت در گروه)</small>
        </div>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="form_action" value="set_webhooks">
            <button class="btn btn-indigo btn-sm" type="submit">🔗 ثبت خودکار Webhook روی بله و تلگرام</button>
        </form>
    </div>

    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="form_action" value="save_social">
        <div class="form-grid">
            <div class="field">
                <label>توکن ربات بله (Bale Bot Token — ذخیره AES-256-GCM)</label>
                <input type="password" name="bale_bot_token" class="ltr" placeholder="<?= MessengerClient::tokenFor('bale') !== '' ? '•••••••• (ثبت شده — برای تغییر وارد کنید)' : '123456789:ABCDEF...' ?>">
                <small>آدرس وبهوک بله: <code><?= e(base_url('webhooks/bale.php')) ?></code></small>
            </div>
            <div class="field">
                <label>توکن ربات تلگرام (Telegram Bot Token)</label>
                <input type="password" name="telegram_bot_token" class="ltr" placeholder="<?= MessengerClient::tokenFor('telegram') !== '' ? '•••••••• (ثبت شده — برای تغییر وارد کنید)' : '123456789:AAH...' ?>">
                <small>آدرس وبهوک تلگرام: <code><?= e(base_url('webhooks/telegram.php')) ?></code></small>
            </div>
            <div class="field">
                <label>آدرس ورکر / پروکسی ضدتحریم تلگرام (ویژه هاست ایران)</label>
                <input name="telegram_api_base_override" class="ltr" value="<?= e(Settings::get('telegram_api_base_override')) ?>" placeholder="https://tg-worker.yourdomain.workers.dev">
                <small>خالی = اتصال مستقیم به <code>https://api.telegram.org</code></small>
            </div>
            <div class="field">
                <label>وبهوک خروجی پل اتوماسیون (Rubika / Eitaa / Discord / n8n)</label>
                <input name="bridge_outbound_url" class="ltr" value="<?= e(Settings::get('bridge_outbound_url')) ?>" placeholder="https://n8n.yourdomain.ir/webhook/yartoken">
                <small>وبهوک ورودی امضاشده: <code><?= e(base_url('webhooks/bridge.php')) ?></code></small>
            </div>
            <div class="field">
                <label>کانال/گروه شفافیت در بله (اعلان ضرب توکن و نهنگ‌ها)</label>
                <input name="public_alert_bale_chat" class="ltr" value="<?= e(Settings::get('public_alert_bale_chat')) ?>" placeholder="@channel_id یا شناسه عددی">
            </div>
            <div class="field">
                <label>کانال/گروه شفافیت در تلگرام</label>
                <input name="public_alert_tg_chat" class="ltr" value="<?= e(Settings::get('public_alert_tg_chat')) ?>" placeholder="@channel_username">
            </div>
            <div class="field">
                <label>حداقل مبلغ تراکنش نهنگ برای اعلان عمومی</label>
                <input name="whale_alert_threshold" class="ltr" value="<?= e(Settings::get('whale_alert_threshold', '1000')) ?>">
            </div>
            <div class="field" style="display:flex; gap:18px; align-items:flex-end;">
                <label><input type="checkbox" name="bale_enabled" value="1" <?= Settings::bool('bale_enabled', true) ? 'checked' : '' ?> style="width:auto;"> بله فعال</label>
                <label><input type="checkbox" name="telegram_enabled" value="1" <?= Settings::bool('telegram_enabled', true) ? 'checked' : '' ?> style="width:auto;"> تلگرام فعال</label>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>متن خوش‌آمدگویی ربات‌ها (متغیرها: <code>{token_name}</code> و <code>{symbol}</code>)</label>
                <textarea name="bot_welcome_text" rows="2"><?= e(Settings::get('bot_welcome_text')) ?></textarea>
            </div>
        </div>
        <div style="margin-bottom:12px;">
            <small style="color:#64748b;">کلید HMAC پل ارتباطی برای <code>n8n-nodes-rubika</code> / <code>n8n-nodes-Bale</code>: </small>
            <code data-copy="<?= e($bridgeSecret) ?>" style="cursor:pointer;"><?= e($bridgeSecret) ?></code>
        </div>
        <button class="btn btn-primary" type="submit">💾 ذخیره تنظیمات شبکه‌های اجتماعی</button>
    </form>
</div>

<div class="card">
    <h3 class="card-title" style="margin-bottom:12px;">👥 گروه‌های اجتماعی متصل و قوانین پاداش فعالیت (Chat-to-Earn)</h3>
    <p style="font-size:12.5px; color:#64748b; margin-top:0;">
        کافیست ربات را در هر گروه بله یا تلگرام عضو کنید؛ به محض ارسال اولین پیام، گروه به صورت خودکار اینجا ثبت می‌شود!
        دستورات آماده داخل گروه: ریپلای <code>انتقال 25</code>، دستور بسته هدیه <code>هدیه 100 5</code>، <code>موجودی</code> و <code>برترین‌ها</code>.
    </p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>پلتفرم</th><th>عنوان گروه</th><th>شناسه چت</th><th>حجم انعام‌ها</th>
                    <th>انعام ریپلای</th><th>پاکت هدیه</th><th>پاداش پیام (Chat-to-Earn)</th><th>سقف روزانه فرد</th><th>ذخیره</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="form_action" value="update_group">
                            <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
                            <input type="hidden" name="is_active" value="1">
                            <td><span class="badge badge-indigo"><?= e($g['platform']) ?></span></td>
                            <td><b><?= e($g['title']) ?></b></td>
                            <td><code><?= e($g['group_chat_id']) ?></code></td>
                            <td><?= fmt_token($g['total_tips_volume']) ?></td>
                            <td><input type="checkbox" name="tipping_enabled" value="1" <?= (int) $g['tipping_enabled'] === 1 ? 'checked' : '' ?>></td>
                            <td><input type="checkbox" name="red_packets_enabled" value="1" <?= (int) $g['red_packets_enabled'] === 1 ? 'checked' : '' ?>></td>
                            <td style="display:flex; gap:5px; align-items:center;">
                                <input type="checkbox" name="chat_reward_enabled" value="1" <?= (int) $g['chat_reward_enabled'] === 1 ? 'checked' : '' ?>>
                                <input name="chat_reward_amount" class="ltr" value="<?= e(fmt_token($g['chat_reward_amount'], false)) ?>" style="width:78px; padding:4px 6px;">
                            </td>
                            <td><input name="daily_user_cap" class="ltr" value="<?= e(fmt_token($g['daily_user_cap'], false)) ?>" style="width:80px; padding:4px 6px;"></td>
                            <td><button class="btn btn-primary btn-sm" type="submit">ثبت</button></td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
