<?php
declare(strict_types=1);

namespace App\Social;

use App\Agents;
use App\Db;
use App\FeeCalculator;
use App\Loans;
use App\Settings;
use App\TokenEngine;
use App\Users;

/**
 * Unified Multi-Messenger Interactive Bot & Group Economy Engine (Bale + Telegram + Social Bridge).
 * Handles:
 * 1) Full Private Wallet Bot (Balance, Receive Address, Step-by-Step Transfer w/ Fee Quote, Loans & Repayment,
 *    Android Wallet Pairing Code, Representative / Lender Sub-User & Loan Origination commands!)
 * 2) Full Social Group Economy:
 *    - Instant Reply Tipping (`انتقال 25` / `انعام 25` / `/tip 25`)
 *    - Interactive Group Red Packets / Token Rain (`/packet 100 5` / `هدیه 100 5` + inline claim button!)
 *    - Group Leaderboard (`/top` / `برترین‌ها`) & Quick Balance (`موجودی` / `/balance`)
 *    - Anti-Spam Chat-to-Earn automatic micro-rewards for active group members!
 */
final class BotEngine
{
    /** @param array<string,mixed> $update */
    public static function handleUpdate(string $platform, array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            self::handleCallback($platform, $update['callback_query']);
            return;
        }

        $msg = $update['message'] ?? null;
        if (!is_array($msg)) {
            return;
        }

        $chat = $msg['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $chatType = (string) ($chat['type'] ?? 'private');
        $from = $msg['from'] ?? [];
        if ($chatId === '' || empty($from['id']) || !empty($from['is_bot'])) {
            return;
        }

        $fromId = (string) $from['id'];
        $fromName = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));
        if ($fromName === '') {
            $fromName = (string) ($from['username'] ?? 'کاربر');
        }
        $text = trim((string) ($msg['text'] ?? ''));

        if ($chatType === 'group' || $chatType === 'supergroup') {
            self::handleGroupMessage($platform, $msg, $chatId, (string) ($chat['title'] ?? 'گروه'), $fromId, $fromName, $text);
            return;
        }

        self::handlePrivateMessage($platform, $chatId, $fromId, $fromName, $text);
    }

    /**
     * Builds the main persistent reply keyboard (adapts dynamically if user is an Agent!).
     *
     * @return array<string,mixed>
     */
    public static function mainKeyboard(array $user): array
    {
        $rows = [
            [['text' => '💼 کیف پول من'], ['text' => '💸 انتقال توکن']],
            [['text' => '🏦 وام‌های من'], ['text' => '📜 گردش حساب']],
            [['text' => '📱 اتصال به اپ اندروید'], ['text' => '📊 آمار شبکه']],
        ];
        if (($user['account_type'] ?? '') === 'agent') {
            $rows[] = [['text' => '🤝 پنل نمایندگی و وام‌دهی']];
        }
        return [
            'keyboard'        => $rows,
            'resize_keyboard' => true,
        ];
    }

    private static function handlePrivateMessage(
        string $platform,
        string $chatId,
        string $fromId,
        string $fromName,
        string $text
    ): void {
        $inviteAgentCode = null;
        if (str_starts_with($text, '/start')) {
            $parts = preg_split('/\s+/', $text, 2);
            if (!empty($parts[1])) {
                $inviteAgentCode = trim($parts[1]);
            }
        }

        $user = Users::findOrCreateBySocial($platform, $fromId, $fromName, $inviteAgentCode);
        if ((int) $user['is_frozen'] === 1) {
            MessengerClient::sendMessage($platform, $chatId, '⛔ حساب کیف پول شما مسدود شده است.');
            return;
        }

        // Cancel state on main menu taps or /start or انصراف
        $menuButtons = ['💼 کیف پول من', '💸 انتقال توکن', '🏦 وام‌های من', '📜 گردش حساب', '📱 اتصال به اپ اندروید', '📊 آمار شبکه', '🤝 پنل نمایندگی و وام‌دهی', '❌ انصراف'];
        if (str_starts_with($text, '/') || in_array($text, $menuButtons, true)) {
            if ($user['bot_state'] !== '') {
                self::setState((int) $user['id'], '', null);
                $user['bot_state'] = '';
            }
        }

        if ($text === '❌ انصراف') {
            MessengerClient::sendMessage($platform, $chatId, '✅ عملیات لغو شد.', self::mainKeyboard($user));
            return;
        }

        // Handle active multi-step state (e.g. step-by-step transfer wizard)
        if ($user['bot_state'] !== '') {
            self::handleStatefulInput($platform, $chatId, $user, $text);
            return;
        }

        // Slash commands
        if (str_starts_with($text, '/start')) {
            $tokName = Settings::get('token_name', 'یارکوین');
            $sym = Settings::get('token_symbol', 'YAR');
            $welcome = strtr(Settings::get('bot_welcome_text', ''), [
                '{token_name}' => $tokName,
                '{symbol}'     => $sym,
            ]);
            MessengerClient::sendMessage(
                $platform,
                $chatId,
                $welcome . "\n\n"
                . "🆔 کد کیف پول شما: <code>" . e($user['wallet_code']) . "</code>\n"
                . "🔑 آدرس زنجیره: <code>" . e($user['evm_address']) . "</code>\n"
                . "💰 موجودی فعلی: <b>" . fmt_token($user['balance']) . "</b>",
                self::mainKeyboard($user)
            );
            return;
        }

        // Pair with Android Wallet Yar non-custodial wallet: /link ABC123
        if (str_starts_with($text, '/link')) {
            $parts = preg_split('/\s+/', $text, 2);
            $code = trim($parts[1] ?? '');
            if ($code === '') {
                $issued = Users::issueSocialLinkCode((int) $user['id']);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "🔗 <b>کد اتصال کیف پول به اپ اندروید / وب:</b>\n\n"
                    . "کد یکبار مصرف ۱۰ دقیقه‌ای شما: <code>{$issued}</code>\n\n"
                    . "یا اگر کد اتصال را از اپ اندروید گرفته‌اید، دستور زیر را بفرستید:\n"
                    . "<code>/link CODE</code>",
                    self::mainKeyboard($user)
                );
                return;
            }
            try {
                $merged = Users::consumeSocialLinkCode($code, $platform, $fromId);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "✅ <b>اتصال با موفقیت انجام شد!</b>\n\n"
                    . "اکانت پیام‌رسان شما اکنون به کیف پول اصلی <code>" . e($merged['evm_address']) . "</code> متصل است.\n"
                    . "💰 موجودی واحد شما: <b>" . fmt_token($merged['balance']) . "</b>",
                    self::mainKeyboard($merged)
                );
            } catch (\Throwable $e) {
                MessengerClient::sendMessage($platform, $chatId, '❌ ' . e($e->getMessage()), self::mainKeyboard($user));
            }
            return;
        }

        // Fast slash transfer: /send <target> <amount> [memo]
        if (str_starts_with($text, '/send ')) {
            $parts = preg_split('/\s+/', $text, 4);
            if (count($parts) < 3) {
                MessengerClient::sendMessage($platform, $chatId, "فرمت صحیح:\n<code>/send YAR-XXXXXX 25 توضیحات</code>");
                return;
            }
            try {
                $rec = Users::resolveRecipient($parts[1]);
                if (!$rec) throw new \RuntimeException('کیف پول مقصد یافت نشد.');
                $res = TokenEngine::transfer((int) $user['id'], (int) $rec['id'], $parts[2], $platform, $parts[3] ?? '');
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "✅ <b>انتقال با موفقیت انجام شد</b>\n\n"
                    . "📤 به: <code>" . e($rec['wallet_code']) . "</code>\n"
                    . "💰 خالص واریزی: <b>" . fmt_token($res['quote']['receiver_net_credit']) . "</b>\n"
                    . "🧾 کارمزد شبکه: " . fmt_token($res['quote']['fee_amount']) . "\n"
                    . "🔗 هش: <code>" . substr((string) $res['tx']['tx_hash'], 0, 20) . "…</code>",
                    self::mainKeyboard(Users::findById((int) $user['id']) ?? $user)
                );
            } catch (\Throwable $e) {
                MessengerClient::sendMessage($platform, $chatId, '❌ ' . e($e->getMessage()));
            }
            return;
        }

        // Fast Agent Loan Grant command inside bot: /grant <subuser> <amount> [installments]
        if (str_starts_with($text, '/grant ')) {
            $parts = preg_split('/\s+/', $text, 4);
            if (($user['account_type'] ?? '') !== 'agent') {
                MessengerClient::sendMessage($platform, $chatId, '⛔ این دستور ویژه نمایندگان رسمی وام‌دهنده است.');
                return;
            }
            try {
                $target = Users::resolveRecipient($parts[1] ?? '');
                if (!$target) throw new \RuntimeException('کاربر زیرمجموعه یافت نشد.');
                // Auto-attach if unassigned
                if (empty($target['parent_agent_id'])) {
                    Agents::attachSubUser((int) $user['id'], (string) $target['wallet_code']);
                }
                $instCount = max(1, (int) ($parts[3] ?? 3));
                $loan = Loans::grantLoan((int) $user['id'], (int) $target['id'], $parts[2] ?? '0', [
                    'installments_count' => $instCount,
                    'purpose'            => 'اعطای وام از طریق ربات نمایندگی',
                ]);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "🏦 <b>وام نمایندگی با موفقیت صادر و واریز شد!</b>\n\n"
                    . "🆔 شماره وام: <code>" . e($loan['loan_code']) . "</code>\n"
                    . "👤 وام‌گیرنده: <b>" . e($loan['borrower_name']) . "</b>\n"
                    . "💰 مبلغ اصل: <b>" . fmt_token($loan['principal_amount']) . "</b>\n"
                    . "🗓 تعداد اقساط: " . fa_digits($loan['installments_count']) . " قسط"
                );
            } catch (\Throwable $e) {
                MessengerClient::sendMessage($platform, $chatId, '❌ ' . e($e->getMessage()));
            }
            return;
        }

        // Menu handlers
        switch ($text) {
            case '💼 کیف پول من':
            case '/wallet':
                $fresh = Users::findById((int) $user['id']) ?? $user;
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "💼 <b>اطلاعات کیف پول شما</b>\n\n"
                    . "👤 نام حساب: <b>" . e($fresh['full_name']) . "</b>\n"
                    . "🆔 کد اختصاصی: <code>" . e($fresh['wallet_code']) . "</code>\n"
                    . "🔑 آدرس EVM (سازگار با اپ اندروید): <code>" . e($fresh['evm_address']) . "</code>\n\n"
                    . "💎 موجودی قابل استفاده: <b>" . fmt_token($fresh['balance']) . "</b>\n"
                    . "💵 ارزش تقریبی: <b>" . fmt_irt($fresh['balance']) . "</b>\n"
                    . "⭐ امتیاز اعتباری خوش‌حسابی: <b>" . fa_digits($fresh['credit_score']) . " / ۸۵۰</b>",
                    self::mainKeyboard($fresh)
                );
                break;

            case '💸 انتقال توکن':
                self::setState((int) $user['id'], 'await_recipient', []);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "📤 <b>انتقال توکن به کیف پول دیگر</b>\n\n"
                    . "لطفاً <b>آدرس <code>0x...</code></b>، <b>کد کیف پول (<code>YAR-...</code>)</b> یا <b>نام کاربری</b> گیرنده را ارسال کنید:",
                    ['keyboard' => [[['text' => '❌ انصراف']]], 'resize_keyboard' => true]
                );
                break;

            case '🏦 وام‌های من':
                $loans = Db::pdo()->prepare('SELECT * FROM loans WHERE borrower_user_id = ? AND status IN ("active","overdue","requested") ORDER BY id DESC LIMIT 5');
                $loans->execute([(int) $user['id']]);
                $rows = $loans->fetchAll();
                if (empty($rows)) {
                    MessengerClient::sendMessage(
                        $platform,
                        $chatId,
                        "🏦 شما در حال حاضر وام فعالی ندارید.\n"
                        . "⭐ امتیاز اعتباری فعلی شما: <b>" . fa_digits($user['credit_score']) . "</b>\n\n"
                        . (!empty($user['parent_agent_id'])
                            ? "برای ثبت درخواست وام از نماینده خود، از کیف پول وب یا اپلیکیشن اندروید اقدام فرمایید."
                            : "برای دریافت تسهیلات، کافیست زیرمجموعه یکی از نمایندگان رسمی شبکه شوید."),
                        self::mainKeyboard($user)
                    );
                } else {
                    foreach ($rows as $ln) {
                        $kb = null;
                        if (in_array($ln['status'], ['active', 'overdue'], true)) {
                            $kb = [
                                'inline_keyboard' => [
                                    [['text' => '💳 پرداخت قسط بعدی (' . fmt_token(bcdiv((string) $ln['total_repayable'], (string) max(1, (int) $ln['installments_count']), 4)) . ')', 'callback_data' => 'repay:' . $ln['id']]],
                                ],
                            ];
                        }
                        MessengerClient::sendMessage(
                            $platform,
                            $chatId,
                            "🏦 <b>پرونده وام <code>" . e($ln['loan_code']) . "</code></b>\n"
                            . "• اصل وام: " . fmt_token($ln['principal_amount']) . "\n"
                            . "• بازپرداخت‌شده: " . fmt_token($ln['repaid_amount']) . "\n"
                            . "• مانده بدهی: <b>" . fmt_token($ln['remaining_amount']) . "</b>\n"
                            . "• وضعیت: <b>" . e($ln['status']) . "</b>",
                            $kb
                        );
                    }
                }
                break;

            case '📜 گردش حساب':
                $uid = (int) $user['id'];
                $txs = Db::pdo()->prepare('SELECT * FROM ledger_entries WHERE from_user_id = ? OR to_user_id = ? ORDER BY id DESC LIMIT 6');
                $txs->execute([$uid, $uid]);
                $list = $txs->fetchAll();
                if (empty($list)) {
                    MessengerClient::sendMessage($platform, $chatId, '📭 هنوز تراکنشی برای کیف پول شما ثبت نشده است.');
                    break;
                }
                $lines = ["📜 <b>۶ گردش اخیر کیف پول شما:</b>\n"];
                foreach ($list as $t) {
                    $in = ((int) ($t['to_user_id'] ?? 0) === $uid);
                    $sign = $in ? '🟢 +' : '🔴 -';
                    $amt = $in ? $t['net_amount'] : bcadd((string) $t['gross_amount'], (string) $t['fee_amount'], 8);
                    $lines[] = "{$sign}" . fmt_token($amt) . " | <code>" . e($t['tx_type']) . "</code>\n   🕒 " . e($t['created_at']);
                }
                MessengerClient::sendMessage($platform, $chatId, implode("\n", $lines), self::mainKeyboard($user));
                break;

            case '📱 اتصال به اپ اندروید':
                $linkCode = Users::issueSocialLinkCode((int) $user['id']);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "📱 <b>اتصال کیف پول به اپلیکیشن اندروید (Wallet Yar)</b>\n\n"
                    . "۱) در اپلیکیشن اندروید وارد بخش <b>سرور توکن / همگام‌سازی</b> شوید:\n"
                    . "🌐 آدرس سرور: <code>" . e(base_url()) . "</code>\n"
                    . "⛓ آدرس RPC سازگار: <code>" . e(base_url('rpc.php')) . "</code>\n\n"
                    . "۲) برای اتصال حساب فعلی به کیف پول غیرحضانتی گوشی، کد زیر را در اپ وارد کنید:\n"
                    . "🔑 کد جفت‌سازی: <code>{$linkCode}</code>",
                    self::mainKeyboard($user)
                );
                break;

            case '📊 آمار شبکه':
                $sup = TokenEngine::supplyOverview();
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "📊 <b>آمار زنده شبکه «" . e($sup['name']) . " (" . e($sup['symbol']) . ")»</b>\n\n"
                    . "💎 عرضه در گردش خالص: <b>" . fmt_token($sup['net_total_supply'], false) . " " . e($sup['symbol']) . "</b>\n"
                    . "🔥 کل توکن سوزانده‌شده: <b>" . fmt_token($sup['total_burned']) . "</b>\n"
                    . "👥 کیف پول‌های دارای موجودی: <b>" . fa_digits($sup['active_holders']) . "</b>\n"
                    . "🔄 تعداد بلاک‌های دفتر کل: <b>" . fa_digits($sup['transactions_count']) . "</b>\n"
                    . "💵 نرخ مرجع فعلی: <b>" . fa_digits(number_format($sup['price_irt'])) . " تومان</b>",
                    self::mainKeyboard($user)
                );
                break;

            case '🤝 پنل نمایندگی و وام‌دهی':
                if (($user['account_type'] ?? '') !== 'agent') break;
                $m = Agents::dashboardMetrics((int) $user['id']);
                $ag = $m['agent'];
                $botUsernameInfo = "<code>" . e($ag['agent_code']) . "</code>";
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "🤝 <b>میز کار نمایندگی «" . e($ag['title']) . "»</b>\n\n"
                    . "🔑 کد دعوت زیرمجموعه‌ها: {$botUsernameInfo}\n"
                    . "👥 تعداد زیرمجموعه‌ها: <b>" . fa_digits($m['subusers_count']) . " نفر</b>\n"
                    . "🏦 اعتبار آزاد وام‌دهی: <b>" . fmt_token($m['available_pool']) . "</b>\n"
                    . "📈 مطالبات وام‌های فعال: <b>" . fmt_token($m['active_receivables']) . "</b>\n"
                    . "💰 پورسانت کارمزد دریافتی: <b>" . fmt_token($m['commissions_earned']) . "</b>\n\n"
                    . "⚡ <b>دستورات سریع رباتیک نماینده:</b>\n"
                    . "• اعطای فوری وام:\n<code>/grant YAR-XXXXXX 500 3</code>\n"
                    . "• انتقال مستقیم وجه:\n<code>/send YAR-XXXXXX 200 پاداش</code>\n"
                    . "• پرتال کامل تحت وب نماینده:\n<code>" . e(base_url('agent/')) . "</code>",
                    self::mainKeyboard($user)
                );
                break;

            default:
                MessengerClient::sendMessage($platform, $chatId, 'لطفاً یکی از گزینه‌های منوی کیف پول را انتخاب کنید:', self::mainKeyboard($user));
        }
    }

    private static function handleStatefulInput(string $platform, string $chatId, array $user, string $text): void
    {
        $state = (string) $user['bot_state'];
        $data = json_decode((string) ($user['bot_state_data'] ?? '{}'), true) ?: [];

        if ($state === 'await_recipient') {
            $rec = Users::resolveRecipient($text);
            if (!$rec) {
                MessengerClient::sendMessage($platform, $chatId, '❌ کیف پولی با این مشخصات پیدا نشد. لطفاً مجدداً کد YAR-... یا آدرس 0x... معتبر وارد کنید (یا «❌ انصراف» بزنید):');
                return;
            }
            if ((int) $rec['id'] === (int) $user['id']) {
                MessengerClient::sendMessage($platform, $chatId, '❌ نمی‌توانید به کیف پول خودتان انتقال دهید.');
                return;
            }
            self::setState((int) $user['id'], 'await_amount', ['recipient_id' => (int) $rec['id']]);
            MessengerClient::sendMessage(
                $platform,
                $chatId,
                "👤 گیرنده: <b>" . e($rec['full_name']) . "</b> (<code>" . e($rec['wallet_code']) . "</code>)\n"
                . "💰 موجودی شما: <b>" . fmt_token($user['balance']) . "</b>\n\n"
                . "لطفاً <b>مقدار توکن</b> موردنظر برای ارسال را وارد کنید:"
            );
            return;
        }

        if ($state === 'await_amount') {
            try {
                $rec = Users::findById((int) ($data['recipient_id'] ?? 0));
                if (!$rec) throw new \RuntimeException('گیرنده نامعتبر است.');
                $quote = FeeCalculator::quote($user, $rec, $text, $platform);
                self::setState((int) $user['id'], 'await_confirm', [
                    'recipient_id' => (int) $rec['id'],
                    'amount'       => $quote['gross_amount'],
                ]);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "🧾 <b>پیش‌فاکتور و محاسبه کارمزد انتقال</b>\n\n"
                    . "📥 گیرنده: <b>" . e($rec['full_name']) . "</b> (<code>" . e($rec['wallet_code']) . "</code>)\n"
                    . "💰 مبلغ انتقالی: <b>" . fmt_token($quote['gross_amount']) . "</b>\n"
                    . "⚖️ کارمزد شبکه: <b>" . fmt_token($quote['fee_amount']) . "</b>\n"
                    . "📤 جمع کسر از حساب شما: <b>" . fmt_token($quote['sender_total_debit']) . "</b>\n"
                    . "✅ خالص دریافتی گیرنده: <b>" . fmt_token($quote['receiver_net_credit']) . "</b>",
                    [
                        'inline_keyboard' => [
                            [
                                ['text' => '✅ تایید و انتقال قطعی', 'callback_data' => 'tx_confirm'],
                                ['text' => '❌ لغو', 'callback_data' => 'tx_cancel'],
                            ],
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                MessengerClient::sendMessage($platform, $chatId, '❌ ' . e($e->getMessage()));
            }
        }
    }

    /**
     * Full Social Group Handler (Reply Tipping, Group Red Packets, Group Leaderboard & Chat-to-Earn!).
     *
     * @param array<string,mixed> $msg
     */
    private static function handleGroupMessage(
        string $platform,
        array $msg,
        string $chatId,
        string $groupTitle,
        string $fromId,
        string $fromName,
        string $text
    ): void {
        if ($text === '') return;

        // Auto-register group if new
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO social_groups (platform, group_chat_id, title)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title)'
        )->execute([$platform, $chatId, mb_substr($groupTitle, 0, 180)]);

        $gStmt = $pdo->prepare('SELECT * FROM social_groups WHERE platform = ? AND group_chat_id = ? LIMIT 1');
        $gStmt->execute([$platform, $chatId]);
        $group = $gStmt->fetch();
        if (!$group || (int) $group['is_active'] !== 1) {
            return;
        }

        $sender = Users::findOrCreateBySocial($platform, $fromId, $fromName);
        $msgId = isset($msg['message_id']) ? (int) $msg['message_id'] : null;

        // 1. Reply Tipping: "انتقال 25 ..." or "انعام 25 ..." or "/tip 25 ..."
        if (
            (int) $group['tipping_enabled'] === 1 &&
            isset($msg['reply_to_message']['from']) &&
            preg_match('/^(?:\/tip|انتقال|انعام|تیپ)\s+([0-9۰-۹.]+)(?:\s+(.*))?$/u', $text, $m)
        ) {
            $replyFrom = $msg['reply_to_message']['from'];
            if (!empty($replyFrom['is_bot'])) {
                MessengerClient::sendMessage($platform, $chatId, '🤖 امکان ارسال انعام به ربات وجود ندارد!', null, $msgId);
                return;
            }
            $recSocialId = (string) ($replyFrom['id'] ?? '');
            $recName = trim(((string) ($replyFrom['first_name'] ?? '')) . ' ' . ((string) ($replyFrom['last_name'] ?? ''))) ?: 'کاربر گروه';
            $receiver = Users::findOrCreateBySocial($platform, $recSocialId, $recName);

            try {
                $note = trim((string) ($m[2] ?? 'انعام گروهی در ' . $groupTitle));
                $txRes = TokenEngine::transfer((int) $sender['id'], (int) $receiver['id'], $m[1], 'group', $note);
                $pdo->prepare('UPDATE social_groups SET total_tips_volume = total_tips_volume + ? WHERE id = ?')
                    ->execute([$txRes['quote']['gross_amount'], (int) $group['id']]);

                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "🎉 <b>انتقال آنی درون‌گروهی انجام شد!</b>\n\n"
                    . "👤 از: <b>" . e($fromName) . "</b>\n"
                    . "🎁 به: <b>" . e($recName) . "</b>\n"
                    . "💎 مبلغ: <b>" . fmt_token($txRes['quote']['receiver_net_credit']) . "</b>"
                    . ($note !== '' ? "\n💬 پیام: " . e($note) : ''),
                    null,
                    $msgId
                );
            } catch (\Throwable $e) {
                MessengerClient::sendMessage($platform, $chatId, '❌ ' . e($e->getMessage()), null, $msgId);
            }
            return;
        }

        // 2. Group Red Packet / Token Rain: "/packet 100 5 [title]" or "هدیه 100 5 [title]"
        if (
            (int) $group['red_packets_enabled'] === 1 &&
            preg_match('/^(?:\/packet|\/rain|هدیه|باران)\s+([0-9۰-۹.]+)\s+([0-9۰-۹]+)(?:\s+(.*))?$/u', $text, $m)
        ) {
            try {
                $count = max(1, (int) en_digits($m[2]));
                $title = trim((string) ($m[3] ?? 'بسته هدیه ' . $fromName));
                $pkt = RedPackets::create((int) $sender['id'], $m[1], $count, $platform, $chatId, 'lucky', $title);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "🧧 <b>بسته هدیه شانسی در گروه باز شد!</b>\n\n"
                    . "👤 اهداکننده: <b>" . e($fromName) . "</b>\n"
                    . "💰 مجموع جایزه: <b>" . fmt_token($pkt['total_amount']) . "</b>\n"
                    . "👥 ظرفیت: <b>" . fa_digits($pkt['max_claims']) . " نفر اول</b>\n"
                    . "🏷 عنوان: " . e($title),
                    [
                        'inline_keyboard' => [
                            [['text' => '🎁 دریافت سهم من از هدیه!', 'callback_data' => 'pkt:' . $pkt['packet_code']]],
                        ],
                    ],
                    $msgId
                );
            } catch (\Throwable $e) {
                MessengerClient::sendMessage($platform, $chatId, '❌ ' . e($e->getMessage()), null, $msgId);
            }
            return;
        }

        // 3. Quick Group Balance & Top Holders
        if ($text === 'موجودی' || $text === '/balance') {
            MessengerClient::sendMessage(
                $platform,
                $chatId,
                "💼 <b>" . e($fromName) . "</b> عزیز:\n"
                . "موجودی شما: <b>" . fmt_token($sender['balance']) . "</b> (<code>" . e($sender['wallet_code']) . "</code>)",
                null,
                $msgId
            );
            return;
        }

        if ($text === 'برترین‌ها' || $text === '/top') {
            $tops = $pdo->query(
                "SELECT full_name, balance FROM users WHERE account_type IN ('user','agent') AND balance > 0 ORDER BY balance DESC LIMIT 5"
            )->fetchAll();
            $out = ["🏆 <b>۵ دارنده برتر توکن در شبکه:</b>\n"];
            foreach ($tops as $idx => $t) {
                $medal = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'][$idx] ?? '•';
                $out[] = "{$medal} <b>" . e($t['full_name']) . "</b> — " . fmt_token($t['balance']);
            }
            MessengerClient::sendMessage($platform, $chatId, implode("\n", $out), null, $msgId);
            return;
        }

        // 4. Optional Chat-to-Earn Micro-Reward for constructive messages
        if (
            (int) $group['chat_reward_enabled'] === 1 &&
            mb_strlen($text) >= (int) $group['min_msg_length'] &&
            !str_starts_with($text, '/')
        ) {
            $reward = (string) $group['chat_reward_amount'];
            $cap = (string) $group['daily_user_cap'];
            $today = date('Y-m-d');
            $earnedToday = ($sender['daily_chat_earned_date'] === $today) ? (string) $sender['daily_chat_earned_amount'] : '0.00000000';

            if (bccomp($reward, '0', 8) > 0 && bccomp(bcadd($earnedToday, $reward, 8), $cap, 8) <= 0) {
                try {
                    $treasury = Users::systemAccount('treasury');
                    if (bccomp((string) $treasury['balance'], $reward, 8) >= 0) {
                        TokenEngine::transfer(
                            (int) $treasury['id'],
                            (int) $sender['id'],
                            $reward,
                            'group_reward',
                            'پاداش مشارکت در گروه ' . $groupTitle,
                            null,
                            true,
                            null,
                            'chat_reward'
                        );
                        $newEarned = bcadd($earnedToday, $reward, 8);
                        $pdo->prepare('UPDATE users SET daily_chat_earned_date = ?, daily_chat_earned_amount = ? WHERE id = ?')
                            ->execute([$today, $newEarned, (int) $sender['id']]);
                    }
                } catch (\Throwable) {
                }
            }
        }
    }

    /** @param array<string,mixed> $cb */
    private static function handleCallback(string $platform, array $cb): void
    {
        $cbId = (string) ($cb['id'] ?? '');
        $data = (string) ($cb['data'] ?? '');
        $from = $cb['from'] ?? [];
        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        if ($from === [] || $chatId === '') return;

        $fromId = (string) $from['id'];
        $fromName = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? ''))) ?: 'کاربر';
        $user = Users::findOrCreateBySocial($platform, $fromId, $fromName);

        // Claim Red Packet inside Group!
        if (str_starts_with($data, 'pkt:')) {
            $code = substr($data, 4);
            try {
                $res = RedPackets::claim($code, (int) $user['id']);
                MessengerClient::call($platform, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => '🎉 تبریک! ' . fmt_token($res['share']) . ' به کیف پول شما واریز شد!',
                    'show_alert'        => true,
                ]);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "🎁 <b>" . e($fromName) . "</b> مبلغ <b>" . fmt_token($res['share']) . "</b> از بسته <code>" . e($code) . "</code> دریافت کرد! ("
                    . fa_digits($res['claimed_count']) . '/' . fa_digits($res['max_claims']) . ')'
                );
            } catch (\Throwable $e) {
                MessengerClient::call($platform, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => $e->getMessage(),
                    'show_alert'        => true,
                ]);
            }
            return;
        }

        // Confirm Private Bot Transfer
        if ($data === 'tx_confirm') {
            $stData = json_decode((string) ($user['bot_state_data'] ?? '{}'), true) ?: [];
            self::setState((int) $user['id'], '', null);
            try {
                $rec = Users::findById((int) ($stData['recipient_id'] ?? 0));
                if (!$rec) throw new \RuntimeException('گیرنده نامعتبر است.');
                $txRes = TokenEngine::transfer((int) $user['id'], (int) $rec['id'], (string) ($stData['amount'] ?? '0'), $platform, 'انتقال از ربات');
                MessengerClient::call($platform, 'answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => '✅ انتقال انجام شد!']);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "✅ <b>رسید انتقال موفق</b>\n\n"
                    . "📥 گیرنده: <b>" . e($rec['full_name']) . "</b> (<code>" . e($rec['wallet_code']) . "</code>)\n"
                    . "💰 مبلغ خالص: <b>" . fmt_token($txRes['quote']['receiver_net_credit']) . "</b>\n"
                    . "⚖️ کارمزد: " . fmt_token($txRes['quote']['fee_amount']) . "\n"
                    . "🔗 هش تراکنش:\n<code>" . e($txRes['tx']['tx_hash']) . "</code>",
                    self::mainKeyboard(Users::findById((int) $user['id']) ?? $user)
                );
            } catch (\Throwable $e) {
                MessengerClient::sendMessage($platform, $chatId, '❌ ' . e($e->getMessage()), self::mainKeyboard($user));
            }
            return;
        }

        if ($data === 'tx_cancel') {
            self::setState((int) $user['id'], '', null);
            MessengerClient::call($platform, 'answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'لغو شد']);
            MessengerClient::sendMessage($platform, $chatId, '✅ تراکنش لغو شد.', self::mainKeyboard($user));
            return;
        }

        // Pay Loan Installment from Bot inline button
        if (str_starts_with($data, 'repay:')) {
            $loanId = (int) substr($data, 6);
            try {
                $rep = Loans::repayInstallment($loanId, (int) $user['id'], false, $platform);
                MessengerClient::call($platform, 'answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => '✅ قسط پرداخت شد!']);
                MessengerClient::sendMessage(
                    $platform,
                    $chatId,
                    "✅ قسط وام <code>" . e($rep['loan_code']) . "</code> به مبلغ <b>" . fmt_token($rep['paid_amount']) . "</b> با موفقیت تسویه شد.\n"
                    . "📊 مانده بدهی: <b>" . fmt_token($rep['remaining']) . "</b>",
                    self::mainKeyboard(Users::findById((int) $user['id']) ?? $user)
                );
            } catch (\Throwable $e) {
                MessengerClient::call($platform, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => $e->getMessage(),
                    'show_alert'        => true,
                ]);
            }
        }
    }

    /** @param ?array<string,mixed> $data */
    private static function setState(int $userId, string $state, ?array $data): void
    {
        Db::pdo()->prepare('UPDATE users SET bot_state = ?, bot_state_data = ? WHERE id = ?')
            ->execute([$state, $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, $userId]);
    }
}
