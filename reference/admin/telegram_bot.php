<?php
require_once '../includes/config.php';
require_once '../includes/telegram_bot.php';
requireAdmin();
$pageTitle = 'Telegram Bot - ' . SITE_NAME;
njazTgEnsureTables($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_bulk_changes'])) {
            $bulkChanges = json_decode((string)($_POST['bulk_changes'] ?? ''), true);
            if (!is_array($bulkChanges) || count($bulkChanges) > 1200) {
                $notice = ['danger', 'بيانات الحفظ الجماعي غير صالحة أو كبيرة جداً.'];
            } else {
                $counts = ['settings' => 0, 'ui' => 0, 'menu' => 0, 'payment_ui' => 0, 'categories' => 0, 'services' => 0, 'subscription' => 0, 'subscription_targets' => 0];
                $isValidUiScope = static function (string $scopeKey): bool {
                    $allowed = ['layout:categories', 'layout:all_categories', 'home', 'layout:main'];
                    $known = array_column(njazTgMenuDefinitions(), 'key');
                    return $scopeKey !== '' && (in_array($scopeKey, $allowed, true) || in_array($scopeKey, $known, true) || preg_match('/^layout:category:\d+$/', $scopeKey) || preg_match('/^item:(category|service):\d+$/', $scopeKey) || preg_match('/^payment:method:\d+$/', $scopeKey));
                };
                try {
                    $pdo->beginTransaction();
                    foreach ($bulkChanges as $change) {
                        if (!is_array($change)) continue;
                        $kind = (string)($change['kind'] ?? '');
                        $data = is_array($change['data'] ?? null) ? $change['data'] : [];
                        if ($kind === 'subscription_settings') {
                            $message = trim((string)($data['subscription_message'] ?? ''));
                            if ($message === '') $message = '🔒 <b>الاشتراك الإجباري</b>\\n\\nللاستمرار في استخدام البوت، يرجى الاشتراك في القنوات أو المجموعات التالية ثم الضغط على زر التحقق.';
                            if (function_exists('mb_substr')) $message = mb_substr($message, 0, 4000, 'UTF-8'); else $message = substr($message, 0, 12000);
                            $buttonStyle = njazTgUiStyle($data['subscription_button_style'] ?? 'primary');
                            $verifyStyle = njazTgUiStyle($data['subscription_verify_style'] ?? 'success');
                            $pdo->prepare("INSERT INTO telegram_bot_subscription_settings (id,enabled,message,button_style,verify_style) VALUES (1,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),message=VALUES(message),button_style=VALUES(button_style),verify_style=VALUES(verify_style)")
                                ->execute([!empty($data['subscription_enabled']) ? 1 : 0, $message, $buttonStyle, $verifyStyle]);
                            $counts['subscription']++;
                        } elseif ($kind === 'subscription_target') {
                            $targetId = (int)($data['target_id'] ?? 0);
                            $delete = !empty($data['delete_target']);
                            if ($delete) {
                                if ($targetId > 0) { $pdo->prepare("DELETE FROM telegram_bot_subscription_targets WHERE id=?")->execute([$targetId]); $counts['subscription_targets']++; }
                                continue;
                            }
                            $type = in_array((string)($data['target_type'] ?? ''), ['channel', 'group'], true) ? (string)$data['target_type'] : '';
                            $chatId = trim((string)($data['chat_id'] ?? ''));
                            $title = trim((string)($data['title'] ?? ''));
                            $inviteUrl = trim((string)($data['invite_url'] ?? ''));
                            $buttonText = trim((string)($data['button_text'] ?? ''));
                            $sortOrder = max(-100000, min(100000, (int)($data['sort_order'] ?? 0)));
                            if ($type === '' || njazTgSubscriptionTargetChatId(['chat_id' => $chatId]) === '' || njazTgSubscriptionTargetUrl(['invite_url' => $inviteUrl]) === '') throw new InvalidArgumentException('بيانات القناة أو المجموعة غير صالحة. استخدم Chat ID صحيحاً ورابط Telegram يبدأ بـ https://t.me/.');
                            if (function_exists('mb_substr')) { $title = mb_substr($title, 0, 200, 'UTF-8'); $buttonText = mb_substr($buttonText, 0, 255, 'UTF-8'); } else { $title = substr($title, 0, 200); $buttonText = substr($buttonText, 0, 255); }
                            if ($targetId > 0) {
                                $pdo->prepare("UPDATE telegram_bot_subscription_targets SET target_type=?,chat_id=?,title=?,invite_url=?,button_text=?,enabled=?,sort_order=? WHERE id=?")
                                    ->execute([$type, $chatId, $title ?: null, $inviteUrl, $buttonText ?: null, !empty($data['enabled']) ? 1 : 0, $sortOrder, $targetId]);
                            } else {
                                $pdo->prepare("INSERT INTO telegram_bot_subscription_targets (target_type,chat_id,title,invite_url,button_text,enabled,sort_order) VALUES (?,?,?,?,?,?,?)")
                                    ->execute([$type, $chatId, $title ?: null, $inviteUrl, $buttonText ?: null, !empty($data['enabled']) ? 1 : 0, $sortOrder]);
                            }
                            $counts['subscription_targets']++;
                        } elseif ($kind === 'settings') {
                            $current = njazTgSettings($pdo);
                            $token = trim((string)($data['bot_token'] ?? ''));
                            $token = $token !== '' ? $token : ($current['bot_token'] ?? null);
                            $username = trim((string)($data['bot_username'] ?? ''));
                            $welcome = trim((string)($data['welcome_text'] ?? ''));
                            $secret = trim((string)($data['webhook_secret'] ?? '')) ?: ($current['webhook_secret'] ?? bin2hex(random_bytes(24)));
                            $pdo->prepare("UPDATE telegram_bot_settings SET bot_token=?,bot_username=?,welcome_text=?,webhook_secret=?,enabled=?,allow_orders=?,allow_topup=?,allow_balance=?,allow_orders_history=?,allow_profile=?,allow_telecom=?,notify_customer_updates=? WHERE id=1")
                                ->execute([$token ?: null, $username ?: null, $welcome ?: null, $secret, !empty($data['enabled']) ? 1 : 0, !empty($data['allow_orders']) ? 1 : 0, !empty($data['allow_topup']) ? 1 : 0, !empty($data['allow_balance']) ? 1 : 0, !empty($data['allow_orders_history']) ? 1 : 0, !empty($data['allow_profile']) ? 1 : 0, !empty($data['allow_telecom']) ? 1 : 0, !empty($data['notify_customer_updates']) ? 1 : 0]);
                            $counts['settings']++;
                        } elseif ($kind === 'menu') {
                            $menuKey = trim((string)($data['menu_key'] ?? ''));
                            $emojiRaw = trim((string)($data['menu_icon_custom_emoji_id'] ?? ''));
                            $knownMenuKeys = array_column(njazTgMenuDefinitions(), 'key');
                            $emojiId = $emojiRaw !== '' && preg_match('/^\\d{5,64}$/', $emojiRaw) ? $emojiRaw : null;
                            if (!in_array($menuKey, $knownMenuKeys, true) || ($emojiRaw !== '' && $emojiId === null)) throw new InvalidArgumentException('يوجد عنصر قائمة أو Custom Emoji ID غير صالح في التغييرات الجماعية.');
                            $pdo->prepare("INSERT INTO telegram_bot_menu_items (menu_key,icon_custom_emoji_id) VALUES (?,?) ON DUPLICATE KEY UPDATE icon_custom_emoji_id=VALUES(icon_custom_emoji_id)")->execute([$menuKey, $emojiId]);
                            $counts['menu']++;
                        } elseif ($kind === 'category') {
                            $id = (int)($data['category_id'] ?? 0);
                            $label = trim((string)($data['label_override'] ?? ''));
                            $mediaType = in_array((string)($data['media_type'] ?? ''), ['photo', 'animation'], true) ? (string)$data['media_type'] : null;
                            $mediaUrl = trim((string)($data['media_url'] ?? ''));
                            $emojiRaw = trim((string)($data['icon_custom_emoji_id'] ?? ''));
                            $emojiId = $emojiRaw !== '' && preg_match('/^\\d{5,64}$/', $emojiRaw) ? $emojiRaw : null;
                            if ($id < 1 || ($emojiRaw !== '' && $emojiId === null)) throw new InvalidArgumentException('يوجد قسم أو Custom Emoji ID غير صالح في التغييرات الجماعية.');
                            $pdo->prepare("INSERT INTO telegram_bot_categories (category_id,enabled,label_override,media_type,media_url,icon_custom_emoji_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),label_override=VALUES(label_override),media_type=VALUES(media_type),media_url=VALUES(media_url),icon_custom_emoji_id=VALUES(icon_custom_emoji_id)")->execute([$id, !empty($data['enabled']) ? 1 : 0, $label ?: null, $mediaType, $mediaUrl ?: null, $emojiId]);
                            $counts['categories']++;
                        } elseif ($kind === 'service') {
                            $id = (int)($data['service_id'] ?? 0);
                            $label = trim((string)($data['label_override'] ?? ''));
                            $emojiRaw = trim((string)($data['icon_custom_emoji_id'] ?? ''));
                            $emojiId = $emojiRaw !== '' && preg_match('/^\\d{5,64}$/', $emojiRaw) ? $emojiRaw : null;
                            if ($id < 1 || ($emojiRaw !== '' && $emojiId === null)) throw new InvalidArgumentException('يوجد خدمة أو Custom Emoji ID غير صالح في التغييرات الجماعية.');
                            $pdo->prepare("INSERT INTO telegram_bot_services (service_id,enabled,label_override,icon_custom_emoji_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),label_override=VALUES(label_override),icon_custom_emoji_id=VALUES(icon_custom_emoji_id)")->execute([$id, !empty($data['enabled']) ? 1 : 0, $label ?: null, $emojiId]);
                            $counts['services']++;
                        } elseif ($kind === 'payment_ui') {
                            $scopeKey = trim((string)($data['payment_scope_key'] ?? ''));
                            $emojiRaw = trim((string)($data['payment_icon_custom_emoji_id'] ?? ''));
                            $emojiId = $emojiRaw !== '' && preg_match('/^\\d{5,64}$/', $emojiRaw) ? $emojiRaw : null;
                            $buttonStyle = njazTgUiStyle($data['payment_button_style'] ?? 'primary');
                            if (!preg_match('/^(?:payment:method:\\d+|topup:(?:binance|card|floosak|usdt))$/', $scopeKey) || ($emojiRaw !== '' && $emojiId === null)) throw new InvalidArgumentException('يوجد نطاق وسيلة دفع أو Custom Emoji ID غير صالح في التغييرات الجماعية.');
                            $pdo->prepare("INSERT INTO telegram_bot_ui_settings (scope_key,button_style) VALUES (?,?) ON DUPLICATE KEY UPDATE button_style=VALUES(button_style)")
                                ->execute([$scopeKey, $buttonStyle]);
                            $pdo->prepare("INSERT INTO telegram_bot_menu_items (menu_key,icon_custom_emoji_id) VALUES (?,?) ON DUPLICATE KEY UPDATE icon_custom_emoji_id=VALUES(icon_custom_emoji_id)")
                                ->execute([$scopeKey, $emojiId]);
                            $counts['payment_ui']++;
                        } elseif ($kind === 'ui') {
                            $scopeKey = trim((string)($data['ui_scope_key'] ?? ''));
                            if (!$isValidUiScope($scopeKey)) throw new InvalidArgumentException('يوجد نطاق مظهر غير صالح في التغييرات الجماعية.');
                            $buttonStyle = njazTgUiStyle($data['button_style'] ?? 'primary');
                            $nextStyle = njazTgUiStyle($data['next_style'] ?? 'primary');
                            $previousStyle = njazTgUiStyle($data['previous_style'] ?? 'primary');
                            $cancelStyle = njazTgUiStyle($data['cancel_style'] ?? 'danger');
                            $pageSize = max(1, min(50, (int)($data['page_size'] ?? 8)));
                            $columnsCount = max(1, min(4, (int)($data['columns_count'] ?? 2)));
                            $pdo->prepare("INSERT INTO telegram_bot_ui_settings (scope_key,button_style,page_size,columns_count,next_style,previous_style,cancel_style) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE button_style=VALUES(button_style),page_size=VALUES(page_size),columns_count=VALUES(columns_count),next_style=VALUES(next_style),previous_style=VALUES(previous_style),cancel_style=VALUES(cancel_style)")->execute([$scopeKey, $buttonStyle, $pageSize, $columnsCount, $nextStyle, $previousStyle, $cancelStyle]);
                            $counts['ui']++;
                        }
                    }
                    $pdo->commit();
                    $summary = [];
                    if ($counts['settings']) $summary[] = 'الإعدادات العامة: ' . $counts['settings'];
                    if ($counts['ui']) $summary[] = 'المظهر: ' . $counts['ui'];
                    if ($counts['payment_ui']) $summary[] = 'وسائل الدفع: ' . $counts['payment_ui'];
                    if ($counts['menu']) $summary[] = 'أيقونات القوائم: ' . $counts['menu'];
                    if ($counts['categories']) $summary[] = 'الأقسام: ' . $counts['categories'];
                    if ($counts['services']) $summary[] = 'الخدمات: ' . $counts['services'];
                    if ($counts['subscription']) $summary[] = 'إعدادات الاشتراك: ' . $counts['subscription'];
                    if ($counts['subscription_targets']) $summary[] = 'قنوات/مجموعات الاشتراك: ' . $counts['subscription_targets'];
                    $notice = ['success', 'تم حفظ جميع التغييرات المحددة بنجاح.' . ($summary ? ' (' . implode(' — ', $summary) . ')' : '')];
                } catch (Throwable $bulkError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $notice = [$bulkError instanceof InvalidArgumentException ? 'danger' : 'danger', $bulkError instanceof InvalidArgumentException ? $bulkError->getMessage() : 'تعذر حفظ التغييرات الجماعية. لم يتم تطبيق أي تغيير.'];
                }
            }
        }
        if (isset($_POST['save_settings'])) {
            $current = njazTgSettings($pdo);
            $token = trim((string)($_POST['bot_token'] ?? ''));
            $token = $token !== '' ? $token : ($current['bot_token'] ?? null);
            $username = trim((string)($_POST['bot_username'] ?? ''));
            $welcome = trim((string)($_POST['welcome_text'] ?? ''));
            $secret = trim((string)($_POST['webhook_secret'] ?? '')) ?: ($current['webhook_secret'] ?? bin2hex(random_bytes(24)));
            $pdo->prepare("UPDATE telegram_bot_settings SET bot_token=?,bot_username=?,welcome_text=?,webhook_secret=?,enabled=?,allow_orders=?,allow_topup=?,allow_balance=?,allow_orders_history=?,allow_profile=?,allow_telecom=?,notify_customer_updates=? WHERE id=1")
                ->execute([$token ?: null, $username ?: null, $welcome ?: null, $secret, isset($_POST['enabled']) ? 1 : 0, isset($_POST['allow_orders']) ? 1 : 0, isset($_POST['allow_topup']) ? 1 : 0, isset($_POST['allow_balance']) ? 1 : 0, isset($_POST['allow_orders_history']) ? 1 : 0, isset($_POST['allow_profile']) ? 1 : 0, isset($_POST['allow_telecom']) ? 1 : 0, isset($_POST['notify_customer_updates']) ? 1 : 0]);
            $notice = ['success', 'تم حفظ إعدادات Telegram Bot.'];
        }
        if (isset($_POST['regenerate_secret'])) {
            $secret = bin2hex(random_bytes(24)); $pdo->prepare("UPDATE telegram_bot_settings SET webhook_secret=? WHERE id=1")->execute([$secret]);
            $notice = ['success', 'تم توليد secret token جديد. يجب إعادة تسجيل الـ Webhook.'];
        }
        if (isset($_POST['set_webhook'])) {
            $s = njazTgSettings($pdo); $url = rtrim(SITE_URL, '/') . '/telegram_webhook.php';
            $r = njazTgApi($pdo, 'setWebhook', ['url' => $url, 'secret_token' => $s['webhook_secret'], 'allowed_updates' => json_encode(['message','callback_query'])]);
            $notice = [!empty($r['ok']) ? 'success' : 'danger', !empty($r['ok']) ? 'تم تسجيل الـ Webhook بنجاح.' : 'فشل تسجيل الـ Webhook: ' . ($r['description'] ?? 'خطأ غير معروف')];
        }
        if (isset($_POST['delete_webhook'])) {
            $r = njazTgApi($pdo, 'deleteWebhook', []);
            $notice = [!empty($r['ok']) ? 'success' : 'danger', !empty($r['ok']) ? 'تم حذف الـ Webhook.' : 'فشل حذف الـ Webhook: ' . ($r['description'] ?? 'خطأ')];
        }
        if (isset($_POST['refresh_custom_emojis'])) {
            $synced = njazTgCustomEmojiSync($pdo);
            $notice = ['success', 'تم تحديث كتالوج Custom Emoji. الأيقونات الموجودة في سجلات Webhook أو المضافة يدوياً أصبحت جاهزة للمعاينة.'];
        }
        if (isset($_POST['add_custom_emoji'])) {
            $emojiRaw = trim((string)($_POST['catalog_custom_emoji_id'] ?? ''));
            if (!preg_match('/^\\d{5,64}$/', $emojiRaw)) {
                $notice = ['danger', 'أدخل Custom Emoji ID رقمياً صحيحاً من Telegram.'];
            } elseif (!njazTgCustomEmojiEnsure($pdo, $emojiRaw)) {
                $notice = ['danger', 'تعذر إضافة Custom Emoji إلى الكتالوج.'];
            } else {
                njazTgCustomEmojiSync($pdo);
                $notice = ['success', 'تمت إضافة الأيقونة وتحديث معاينتها. اضغط على إدراج أو نسخ لاستخدامها.'];
            }
        }
        if (isset($_POST['save_menu_icon'])) {
            $menuKey = trim((string)($_POST['menu_key'] ?? ''));
            $emojiRaw = trim((string)($_POST['menu_icon_custom_emoji_id'] ?? ''));
            $knownMenuKeys = array_column(njazTgMenuDefinitions(), 'key');
            $emojiId = $emojiRaw !== '' && preg_match('/^\\d{5,64}$/', $emojiRaw) ? $emojiRaw : null;
            if (!in_array($menuKey, $knownMenuKeys, true)) {
                $notice = ['danger', 'عنصر القائمة غير معروف.'];
            } elseif ($emojiRaw !== '' && $emojiId === null) {
                $notice = ['danger', 'معرّف Custom Emoji يجب أن يكون رقماً صحيحاً من Telegram.'];
            } else {
                $pdo->prepare("INSERT INTO telegram_bot_menu_items (menu_key,icon_custom_emoji_id) VALUES (?,?) ON DUPLICATE KEY UPDATE icon_custom_emoji_id=VALUES(icon_custom_emoji_id)")
                    ->execute([$menuKey, $emojiId]);
                $notice = ['success', 'تم حفظ أيقونة عنصر القائمة.'];
            }
        }
        if (isset($_POST['save_payment_ui'])) {
            $scopeKey = trim((string)($_POST['payment_scope_key'] ?? ''));
            $emojiRaw = trim((string)($_POST['payment_icon_custom_emoji_id'] ?? ''));
            $emojiId = $emojiRaw !== '' && preg_match('/^\\d{5,64}$/', $emojiRaw) ? $emojiRaw : null;
            $buttonStyle = njazTgUiStyle($_POST['payment_button_style'] ?? 'primary');
            if (!preg_match('/^(?:payment:method:\\d+|topup:(?:binance|card|floosak|usdt))$/', $scopeKey)) {
                $notice = ['danger', 'وسيلة الدفع غير معروفة.'];
            } elseif ($emojiRaw !== '' && $emojiId === null) {
                $notice = ['danger', 'معرّف Custom Emoji يجب أن يكون رقماً صحيحاً من Telegram.'];
            } else {
                $pdo->prepare("INSERT INTO telegram_bot_ui_settings (scope_key,button_style) VALUES (?,?) ON DUPLICATE KEY UPDATE button_style=VALUES(button_style)")
                    ->execute([$scopeKey, $buttonStyle]);
                $pdo->prepare("INSERT INTO telegram_bot_menu_items (menu_key,icon_custom_emoji_id) VALUES (?,?) ON DUPLICATE KEY UPDATE icon_custom_emoji_id=VALUES(icon_custom_emoji_id)")
                    ->execute([$scopeKey, $emojiId]);
                $notice = ['success', $scopeKey === 'topup:binance' ? 'تم حفظ مظهر وCustom Emoji ID الخاص بـ Binance مباشر.' : ($scopeKey === 'topup:usdt' ? 'تم حفظ مظهر وCustom Emoji ID الخاص بـ USDT — BEP20 مباشر.' : 'تم حفظ مظهر وسيلة الدفع.')];
            }
        }
        if (isset($_POST['save_category'])) {
            $id = (int)$_POST['category_id']; $label = trim((string)($_POST['label_override'] ?? ''));
            $mediaType = in_array((string)($_POST['media_type'] ?? ''), ['photo', 'animation'], true) ? (string)$_POST['media_type'] : null;
            $mediaUrl = trim((string)($_POST['media_url'] ?? ''));
            $emojiRaw = trim((string)($_POST['icon_custom_emoji_id'] ?? ''));
            $emojiId = $emojiRaw !== '' && preg_match('/^\\d{5,64}$/', $emojiRaw) ? $emojiRaw : null;
            if ($emojiRaw !== '' && $emojiId === null) {
                $notice = ['danger', 'معرّف Custom Emoji يجب أن يكون رقماً صحيحاً من Telegram.'];
            } else {
                $pdo->prepare("INSERT INTO telegram_bot_categories (category_id,enabled,label_override,media_type,media_url,icon_custom_emoji_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),label_override=VALUES(label_override),media_type=VALUES(media_type),media_url=VALUES(media_url),icon_custom_emoji_id=VALUES(icon_custom_emoji_id)")
                    ->execute([$id, isset($_POST['enabled']) ? 1 : 0, $label ?: null, $mediaType, $mediaUrl ?: null, $emojiId]);
                $notice = ['success', 'تم تحديث ربط القسم والوسيط والأيقونة المخصصة.'];
            }
        }
        if (isset($_POST['save_service'])) {
            $id = (int)$_POST['service_id']; $label = trim((string)($_POST['label_override'] ?? ''));
            $emojiRaw = trim((string)($_POST['icon_custom_emoji_id'] ?? ''));
            $emojiId = $emojiRaw !== '' && preg_match('/^\\d{5,64}$/', $emojiRaw) ? $emojiRaw : null;
            if ($emojiRaw !== '' && $emojiId === null) {
                $notice = ['danger', 'معرّف Custom Emoji يجب أن يكون رقماً صحيحاً من Telegram.'];
            } else {
                $pdo->prepare("INSERT INTO telegram_bot_services (service_id,enabled,label_override,icon_custom_emoji_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),label_override=VALUES(label_override),icon_custom_emoji_id=VALUES(icon_custom_emoji_id)")
                    ->execute([$id, isset($_POST['enabled']) ? 1 : 0, $label ?: null, $emojiId]);
                $notice = ['success', 'تم تحديث ربط الخدمة والأيقونة المخصصة.'];
            }
        }
        if (isset($_POST['save_subscription_settings'])) {
            $message = trim((string)($_POST['subscription_message'] ?? ''));
            if ($message === '') $message = '🔒 <b>الاشتراك الإجباري</b>\\n\\nللاستمرار في استخدام البوت، يرجى الاشتراك في القنوات أو المجموعات التالية ثم الضغط على زر التحقق.';
            if (function_exists('mb_substr')) $message = mb_substr($message, 0, 4000, 'UTF-8'); else $message = substr($message, 0, 12000);
            $buttonStyle = njazTgUiStyle($_POST['subscription_button_style'] ?? 'primary');
            $verifyStyle = njazTgUiStyle($_POST['subscription_verify_style'] ?? 'success');
            $pdo->prepare("INSERT INTO telegram_bot_subscription_settings (id,enabled,message,button_style,verify_style) VALUES (1,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),message=VALUES(message),button_style=VALUES(button_style),verify_style=VALUES(verify_style)")
                ->execute([isset($_POST['subscription_enabled']) ? 1 : 0, $message, $buttonStyle, $verifyStyle]);
            $notice = ['success', 'تم حفظ إعدادات الاشتراك الإجباري.'];
        }
        if (isset($_POST['save_subscription_target']) || isset($_POST['delete_subscription_target'])) {
            $targetId = (int)($_POST['target_id'] ?? 0);
            if (isset($_POST['delete_subscription_target'])) {
                if ($targetId > 0) { $pdo->prepare("DELETE FROM telegram_bot_subscription_targets WHERE id=?")->execute([$targetId]); $notice = ['success', 'تم حذف هدف الاشتراك.']; }
                else $notice = ['danger', 'هدف الاشتراك غير صالح.'];
            } else {
                $type = in_array((string)($_POST['target_type'] ?? ''), ['channel', 'group'], true) ? (string)$_POST['target_type'] : '';
                $chatId = trim((string)($_POST['chat_id'] ?? ''));
                $title = trim((string)($_POST['title'] ?? ''));
                $inviteUrl = trim((string)($_POST['invite_url'] ?? ''));
                $buttonText = trim((string)($_POST['button_text'] ?? ''));
                $sortOrder = max(-100000, min(100000, (int)($_POST['sort_order'] ?? 0)));
                if ($type === '' || njazTgSubscriptionTargetChatId(['chat_id' => $chatId]) === '' || njazTgSubscriptionTargetUrl(['invite_url' => $inviteUrl]) === '') {
                    $notice = ['danger', 'بيانات القناة أو المجموعة غير صالحة. استخدم Chat ID صحيحاً ورابط Telegram يبدأ بـ https://t.me/.'];
                } else {
                    if (function_exists('mb_substr')) { $title = mb_substr($title, 0, 200, 'UTF-8'); $buttonText = mb_substr($buttonText, 0, 255, 'UTF-8'); } else { $title = substr($title, 0, 200); $buttonText = substr($buttonText, 0, 255); }
                    if ($targetId > 0) {
                        $pdo->prepare("UPDATE telegram_bot_subscription_targets SET target_type=?,chat_id=?,title=?,invite_url=?,button_text=?,enabled=?,sort_order=? WHERE id=?")
                            ->execute([$type, $chatId, $title ?: null, $inviteUrl, $buttonText ?: null, isset($_POST['enabled']) ? 1 : 0, $sortOrder, $targetId]);
                    } else {
                        $pdo->prepare("INSERT INTO telegram_bot_subscription_targets (target_type,chat_id,title,invite_url,button_text,enabled,sort_order) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$type, $chatId, $title ?: null, $inviteUrl, $buttonText ?: null, isset($_POST['enabled']) ? 1 : 0, $sortOrder]);
                    }
                    $notice = ['success', 'تم حفظ هدف الاشتراك.'];
                }
            }
        }
        if (isset($_POST['save_ui_settings'])) {
            $scopeKey = trim((string)($_POST['ui_scope_key'] ?? ''));
            $allowedScopes = ['layout:categories', 'layout:all_categories', 'home', 'layout:main'];
            $knownUiKeys = array_column(njazTgMenuDefinitions(), 'key');
            $validScope = $scopeKey !== '' && (in_array($scopeKey, $allowedScopes, true)
                || in_array($scopeKey, $knownUiKeys, true)
                || preg_match('/^layout:category:\\d+$/', $scopeKey)
                || preg_match('/^item:(category|service):\\d+$/', $scopeKey));
            if (!$validScope) {
                $notice = ['danger', 'نطاق المظهر غير صالح.'];
            } else {
                $buttonStyle = njazTgUiStyle($_POST['button_style'] ?? 'primary');
                $nextStyle = njazTgUiStyle($_POST['next_style'] ?? 'primary');
                $previousStyle = njazTgUiStyle($_POST['previous_style'] ?? 'primary');
                $cancelStyle = njazTgUiStyle($_POST['cancel_style'] ?? 'danger');
                $pageSize = max(1, min(50, (int)($_POST['page_size'] ?? 8)));
                $columnsCount = max(1, min(4, (int)($_POST['columns_count'] ?? 2)));
                $pdo->prepare("INSERT INTO telegram_bot_ui_settings (scope_key,button_style,page_size,columns_count,next_style,previous_style,cancel_style) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE button_style=VALUES(button_style),page_size=VALUES(page_size),columns_count=VALUES(columns_count),next_style=VALUES(next_style),previous_style=VALUES(previous_style),cancel_style=VALUES(cancel_style)")
                    ->execute([$scopeKey, $buttonStyle, $pageSize, $columnsCount, $nextStyle, $previousStyle, $cancelStyle]);
                $notice = ['success', 'تم حفظ إعدادات مظهر القائمة.'];
            }
        }
    } catch (Throwable $e) {
        error_log('Telegram admin action: ' . $e->getMessage()); $notice = ['danger', 'تعذر تنفيذ العملية. راجع سجل الأخطاء.'];
    }
}

$s = njazTgSettings($pdo);
$webhookUrl = rtrim(SITE_URL, '/') . '/telegram_webhook.php';
$menuDefinitions = njazTgMenuDefinitions();
$menuIconMap = [];
try { $menuIconMap = $pdo->query("SELECT menu_key,icon_custom_emoji_id FROM telegram_bot_menu_items")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { $menuIconMap = []; }
$subscriptionSettings = [];
$subscriptionTargets = [];
try { $subscriptionSettings = njazTgSubscriptionSettings($pdo); } catch (Throwable $e) { $subscriptionSettings = ['enabled' => 0, 'message' => '', 'button_style' => 'primary', 'verify_style' => 'success']; }
try { $subscriptionTargets = njazTgSubscriptionTargets($pdo, false); } catch (Throwable $e) { $subscriptionTargets = []; }
$uiSettingsMap = [];
try { $uiSettingsMap = $pdo->query("SELECT scope_key,button_style,page_size,columns_count,next_style,previous_style,cancel_style,media_type,media_url FROM telegram_bot_ui_settings")->fetchAll(PDO::FETCH_UNIQUE); } catch (Throwable $e) { $uiSettingsMap = []; }
$paymentMethods = [];
try { $paymentMethods = $pdo->query("SELECT id,name,payment_mode,icon,status,sort_order FROM payment_methods WHERE status=1 ORDER BY payment_mode,sort_order,id")->fetchAll(); } catch (Throwable $e) { $paymentMethods = []; }
$tgStyleOptions = static function (string $selected, bool $fullLabel = true): string {
    $options = ['primary' => $fullLabel ? 'أزرق (primary)' : 'أزرق', 'success' => $fullLabel ? 'أخضر (success)' : 'أخضر', 'danger' => $fullLabel ? 'أحمر (danger)' : 'أحمر'];
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html;
};
$renderTgUiForm = static function (string $scopeKey, string $scopeLabel, array $map, callable $styleOptions, bool $layout = true): void {
    $current = is_array($map[$scopeKey] ?? null) ? $map[$scopeKey] : [];
    $buttonStyle = njazTgUiStyle($current['button_style'] ?? 'primary');
    $pageSize = max(1, min(50, (int)($current['page_size'] ?? 8)));
    $columns = max(1, min(4, (int)($current['columns_count'] ?? 2)));
    $nextStyle = njazTgUiStyle($current['next_style'] ?? 'primary');
    $previousStyle = njazTgUiStyle($current['previous_style'] ?? 'primary');
    $cancelStyle = njazTgUiStyle($current['cancel_style'] ?? 'danger');
    $esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    echo '<form method="post" class="tg-ui-form" style="background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:13px;margin-bottom:10px">';
    echo adminCsrfField();
    echo '<input type="hidden" name="ui_scope_key" value="' . $esc($scopeKey) . '">';
    if (!$layout) echo '<input type="hidden" name="page_size" value="' . $pageSize . '"><input type="hidden" name="columns_count" value="' . $columns . '"><input type="hidden" name="next_style" value="' . $esc($nextStyle) . '"><input type="hidden" name="previous_style" value="' . $esc($previousStyle) . '"><input type="hidden" name="cancel_style" value="' . $esc($cancelStyle) . '">';
    echo '<div style="font-weight:800;color:var(--text);margin-bottom:9px">' . $esc($scopeLabel) . '</div><div style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end">';
    echo '<div><label style="color:var(--text2);font-size:.75rem">لون الأزرار</label><select name="button_style" style="display:block;background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:7px;padding:7px">' . $styleOptions($buttonStyle) . '</select></div>';
    if ($layout) {
        echo '<div><label style="color:var(--text2);font-size:.75rem">العناصر/صفحة</label><input type="number" name="page_size" value="' . $pageSize . '" min="1" max="50" style="display:block;width:78px;background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:7px;padding:7px"></div>';
        echo '<div><label style="color:var(--text2);font-size:.75rem">الأعمدة</label><select name="columns_count" style="display:block;background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:7px;padding:7px"><option value="1"' . ($columns === 1 ? ' selected' : '') . '>1</option><option value="2"' . ($columns === 2 ? ' selected' : '') . '>2</option><option value="3"' . ($columns === 3 ? ' selected' : '') . '>3</option><option value="4"' . ($columns === 4 ? ' selected' : '') . '>4</option></select></div>';
        foreach ([['next_style','التالي',$nextStyle],['previous_style','السابق',$previousStyle],['cancel_style','رجوع/إلغاء',$cancelStyle]] as $control) {
            echo '<div><label style="color:var(--text2);font-size:.75rem">' . $control[1] . '</label><select name="' . $control[0] . '" style="display:block;background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:7px;padding:7px">' . $styleOptions($control[2], false) . '</select></div>';
        }
    }
    echo '<button class="tg-btn tg-primary" name="save_ui_settings" value="1">حفظ</button></div></form>';
};
$renderTgPaymentForm = static function (string $scopeKey, string $scopeLabel, string $fallbackIcon, array $uiMap, array $iconMap, callable $styleOptions): void {
    $current = is_array($uiMap[$scopeKey] ?? null) ? $uiMap[$scopeKey] : [];
    $buttonStyle = njazTgUiStyle($current['button_style'] ?? 'primary');
    $emoji = trim((string)($iconMap[$scopeKey] ?? ''));
    $esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    echo '<form method="post" class="tg-payment-ui-form" style="background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:13px;margin-bottom:10px">';
    echo adminCsrfField();
    echo '<input type="hidden" name="payment_scope_key" value="' . $esc($scopeKey) . '">';
    echo '<div style="font-weight:800;color:var(--text);margin-bottom:9px">' . $esc($scopeLabel) . ' <code style="direction:ltr;display:inline-block;color:var(--text3);font-size:.68rem">' . $esc($scopeKey) . '</code></div><div style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end">';
    echo '<div><label style="color:var(--text2);font-size:.75rem">لون الزر</label><select name="payment_button_style" style="display:block;background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:7px;padding:7px">' . $styleOptions($buttonStyle) . '</select></div>';
    echo '<div><label style="color:var(--text2);font-size:.75rem">Custom Emoji ID</label><input type="text" name="payment_icon_custom_emoji_id" class="tg-emoji-id-input" value="' . $esc($emoji) . '" inputmode="numeric" pattern="[0-9]{5,64}" placeholder="اتركه فارغاً لاستخدام Unicode" title="معرّف رقمي من Telegram" style="min-width:220px;background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:7px;padding:7px"></div>';
    echo '<div style="color:var(--text2);font-size:1.35rem;padding-bottom:5px" title="Unicode الاحتياطي">' . $esc($fallbackIcon) . '</div>';
    if ($emoji !== '') echo '<img src="telegram_custom_emoji_preview.php?id=' . $esc($emoji) . '" alt="Custom Emoji" loading="lazy" style="width:42px;height:42px;object-fit:contain;background:#111827;border-radius:8px" title="معاينة الأيقونة المخصصة">';
    echo '<button class="tg-btn tg-primary" name="save_payment_ui" value="1">حفظ</button></div></form>';
};
$tgPerPage = 30;
$tgEntitySearch = trim((string)($_GET['tg_entity_search'] ?? ''));
if (function_exists('mb_substr')) $tgEntitySearch = mb_substr($tgEntitySearch, 0, 100, 'UTF-8'); else $tgEntitySearch = substr($tgEntitySearch, 0, 100);
$tgCategoryPage = max(1, (int)($_GET['tg_category_page'] ?? 1));
$tgServicePage = max(1, (int)($_GET['tg_service_page'] ?? 1));
$tgCategoryWhere = 'c.status=1';
$tgCategoryParams = [];
$tgServiceWhere = 's.status=1';
$tgServiceParams = [];
if ($tgEntitySearch !== '') {
    $tgSearchLike = '%' . $tgEntitySearch . '%';
    $tgCategoryWhere .= ' AND (c.name LIKE :category_name_search OR CAST(c.id AS CHAR) LIKE :category_id_search OR t.label_override LIKE :category_label_search OR t.icon_custom_emoji_id LIKE :category_emoji_search)';
    $tgCategoryParams = [
        ':category_name_search' => $tgSearchLike,
        ':category_id_search' => $tgSearchLike,
        ':category_label_search' => $tgSearchLike,
        ':category_emoji_search' => $tgSearchLike,
    ];
    $tgServiceWhere .= ' AND (s.name LIKE :service_name_search OR CAST(s.id AS CHAR) LIKE :service_id_search OR c.name LIKE :service_category_search OR t.label_override LIKE :service_label_search OR t.icon_custom_emoji_id LIKE :service_emoji_search)';
    $tgServiceParams = [
        ':service_name_search' => $tgSearchLike,
        ':service_id_search' => $tgSearchLike,
        ':service_category_search' => $tgSearchLike,
        ':service_label_search' => $tgSearchLike,
        ':service_emoji_search' => $tgSearchLike,
    ];
}
$tgCategoryTotal = 0;
$tgServiceTotal = 0;
try {
    $countCategories = $pdo->prepare("SELECT COUNT(*) FROM categories c LEFT JOIN telegram_bot_categories t ON t.category_id=c.id WHERE {$tgCategoryWhere}");
    $countCategories->execute($tgCategoryParams); $tgCategoryTotal = (int)$countCategories->fetchColumn();
} catch (Throwable $e) { $tgCategoryTotal = 0; }
try {
    $countServices = $pdo->prepare("SELECT COUNT(*) FROM services s JOIN categories c ON c.id=s.category_id LEFT JOIN telegram_bot_services t ON t.service_id=s.id WHERE {$tgServiceWhere}");
    $countServices->execute($tgServiceParams); $tgServiceTotal = (int)$countServices->fetchColumn();
} catch (Throwable $e) { $tgServiceTotal = 0; }
$tgCategoryPages = max(1, (int)ceil($tgCategoryTotal / $tgPerPage));
$tgServicePages = max(1, (int)ceil($tgServiceTotal / $tgPerPage));
$tgCategoryPage = min($tgCategoryPage, $tgCategoryPages);
$tgServicePage = min($tgServicePage, $tgServicePages);
$tgCategoryOffset = ($tgCategoryPage - 1) * $tgPerPage;
$tgServiceOffset = ($tgServicePage - 1) * $tgPerPage;
try {
    $catStmt = $pdo->prepare("SELECT c.*,COALESCE(t.enabled,1) bot_enabled,t.label_override,t.media_type,t.media_url,t.icon_custom_emoji_id FROM categories c LEFT JOIN telegram_bot_categories t ON t.category_id=c.id WHERE {$tgCategoryWhere} ORDER BY c.parent_id IS NOT NULL,c.sort_order,c.id LIMIT {$tgPerPage} OFFSET {$tgCategoryOffset}");
    $catStmt->execute($tgCategoryParams); $cats = $catStmt->fetchAll();
} catch (Throwable $e) { $cats=[]; }
try {
    $serviceStmt = $pdo->prepare("SELECT s.id,s.name,s.image,s.price,s.status,c.name category_name,COALESCE(t.enabled,1) bot_enabled,t.label_override,t.icon_custom_emoji_id FROM services s JOIN categories c ON c.id=s.category_id LEFT JOIN telegram_bot_services t ON t.service_id=s.id WHERE {$tgServiceWhere} ORDER BY c.sort_order,s.sort_order,s.id LIMIT {$tgPerPage} OFFSET {$tgServiceOffset}");
    $serviceStmt->execute($tgServiceParams); $services = $serviceStmt->fetchAll();
} catch (Throwable $e) { $services=[]; }
$tgPageUrl = static function (string $type, int $page, string $anchor = 'links') use ($tgCategoryPage, $tgServicePage): string {
    $query = $_GET;
    $query['tg_category_page'] = $type === 'category' ? $page : $tgCategoryPage;
    $query['tg_service_page'] = $type === 'service' ? $page : $tgServicePage;
    $anchor = preg_replace('/[^a-z0-9_-]/i', '', $anchor) ?: 'links';
    return '?' . http_build_query($query) . '#' . $anchor;
};
$renderTgPager = static function (string $type, int $current, int $total, callable $urlBuilder): void {
    if ($total <= 1) return;
    $label = $type === 'category' ? 'الأقسام' : 'الخدمات';
    echo '<div class="tg-pager" aria-label="صفحات ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
    if ($current > 1) echo '<a class="tg-page-link" href="' . htmlspecialchars($urlBuilder($type, $current - 1), ENT_QUOTES, 'UTF-8') . '">السابق</a>';
    $start = max(1, $current - 2); $end = min($total, $current + 2);
    if ($start > 1) echo '<a class="tg-page-link" href="' . htmlspecialchars($urlBuilder($type, 1), ENT_QUOTES, 'UTF-8') . '">1</a>' . ($start > 2 ? '<span class="tg-page-gap">…</span>' : '');
    for ($p = $start; $p <= $end; $p++) {
        $active = $p === $current ? ' is-current' : '';
        echo '<a class="tg-page-link' . $active . '" href="' . htmlspecialchars($urlBuilder($type, $p), ENT_QUOTES, 'UTF-8') . '">' . $p . '</a>';
    }
    if ($end < $total) echo ($end < $total - 1 ? '<span class="tg-page-gap">…</span>' : '') . '<a class="tg-page-link" href="' . htmlspecialchars($urlBuilder($type, $total), ENT_QUOTES, 'UTF-8') . '">' . $total . '</a>';
    if ($current < $total) echo '<a class="tg-page-link" href="' . htmlspecialchars($urlBuilder($type, $current + 1), ENT_QUOTES, 'UTF-8') . '">التالي</a>';
    echo '<span class="tg-page-meta">صفحة ' . $current . ' من ' . $total . ' — 30 عنصرًا</span></div>';
};
try { $logs = $pdo->query("SELECT * FROM telegram_bot_logs ORDER BY id DESC LIMIT 12")->fetchAll(); } catch (Throwable $e) { $logs=[]; }
try { $customEmojis = njazTgCustomEmojiSync($pdo); } catch (Throwable $e) { $customEmojis = []; }
$tgStats = [
    'users' => 0, 'linked' => 0, 'active' => 0, 'blocked' => 0,
    'in24' => 0, 'out24' => 0, 'errors24' => 0,
    'orders7' => 0, 'ordersPending' => 0, 'topups7' => 0,
];
try { $tgStats['users'] = (int)$pdo->query("SELECT COUNT(*) FROM telegram_users")->fetchColumn(); } catch (Throwable $e) {}
try { $tgStats['linked'] = (int)$pdo->query("SELECT COUNT(*) FROM telegram_users WHERE user_id IS NOT NULL")->fetchColumn(); } catch (Throwable $e) {}
try { $tgStats['active'] = (int)$pdo->query("SELECT COUNT(*) FROM telegram_users WHERE last_seen_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn(); } catch (Throwable $e) {}
try { $tgStats['blocked'] = (int)$pdo->query("SELECT COUNT(*) FROM telegram_users WHERE is_blocked=1")->fetchColumn(); } catch (Throwable $e) {}
try { $q = $pdo->query("SELECT direction,COUNT(*) c FROM telegram_bot_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY direction"); foreach ($q->fetchAll() as $r) { if ($r['direction']==='in') $tgStats['in24']=(int)$r['c']; elseif ($r['direction']==='out') $tgStats['out24']=(int)$r['c']; elseif ($r['direction']==='error') $tgStats['errors24']=(int)$r['c']; } } catch (Throwable $e) {}
try { $tgStats['orders7'] = (int)$pdo->query("SELECT COUNT(DISTINCT o.id) FROM orders o INNER JOIN telegram_users tu ON tu.user_id=o.user_id WHERE o.created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn(); } catch (Throwable $e) {}
try { $tgStats['ordersPending'] = (int)$pdo->query("SELECT COUNT(DISTINCT o.id) FROM orders o INNER JOIN telegram_users tu ON tu.user_id=o.user_id WHERE o.status IN ('pending','processing')")->fetchColumn(); } catch (Throwable $e) {}
try { $tgStats['topups7'] = (int)$pdo->query("SELECT COUNT(DISTINCT r.id) FROM topup_requests r INNER JOIN telegram_users tu ON tu.user_id=r.user_id WHERE r.created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn(); } catch (Throwable $e) {}
try { $tgErrors = $pdo->query("SELECT created_at,method,payload FROM telegram_bot_logs WHERE direction='error' ORDER BY id DESC LIMIT 10")->fetchAll(); } catch (Throwable $e) { $tgErrors=[]; }
include 'header.php';
?>
<style>
.tg-wrap{max-width:1180px}.tg-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:18px}.tg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.tg-field label{display:block;color:var(--text2);font-size:.78rem;margin-bottom:6px}.tg-field input,.tg-field textarea{width:100%;box-sizing:border-box;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:9px;padding:10px;font-family:inherit}.tg-field textarea{min-height:80px}.tg-btn{border:0;border-radius:9px;padding:9px 14px;cursor:pointer;font-family:inherit;font-weight:700}.tg-primary{background:var(--primary);color:#fff}.tg-green{background:#00b894;color:#071116}.tg-danger{background:#ff4455;color:#fff}.tg-muted{background:var(--card2);color:var(--text2)}.tg-switch{display:flex;align-items:center;gap:8px;color:var(--text2);font-size:.82rem}.tg-table{width:100%;border-collapse:collapse;font-size:.78rem}.tg-table th,.tg-table td{padding:9px;border-bottom:1px solid var(--border);text-align:right;vertical-align:middle}.tg-table th{color:var(--text2);font-weight:600}.tg-table td{color:var(--text)}.tg-url{direction:ltr;text-align:left;background:#080d18;padding:9px;border-radius:8px;word-break:break-all;color:#8bd5ff;font-size:.75rem}.tg-on{color:#00d4aa}.tg-off{color:#ff7788}.tg-scroll{max-height:480px;overflow:auto}.tg-emoji-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px}.tg-emoji-card{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:12px;text-align:center}.tg-emoji-preview{width:86px;height:86px;object-fit:contain;display:block;margin:0 auto 8px;background:#111827;border-radius:12px}.tg-emoji-id{display:block;direction:ltr;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text2);font-size:.7rem;margin:6px 0}.tg-emoji-actions{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}.tg-emoji-actions .tg-btn{font-size:.72rem;padding:7px 9px}@media(max-width:700px){.tg-table{min-width:720px}.tg-scroll{overflow-x:auto}}
.tg-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px;padding:8px;background:var(--card2);border:1px solid var(--border);border-radius:14px;position:sticky;top:10px;z-index:5}.tg-search-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 18px;padding:10px 12px;background:var(--card);border:1px solid var(--border);border-radius:12px}.tg-search-toolbar label{color:var(--text2);font-size:.8rem;font-weight:800;white-space:nowrap}.tg-search-input{flex:1;min-width:220px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:9px;padding:10px 12px;font-family:inherit}.tg-search-clear{background:transparent!important;border:1px solid var(--border)!important;color:var(--text2)!important}.tg-search-count{color:var(--text3);font-size:.76rem}.tg-search-empty{display:none;padding:16px;background:var(--card2);border:1px dashed var(--border);border-radius:10px;color:var(--text2);text-align:center;margin-bottom:16px}.tg-bulk-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 18px;padding:10px 12px;background:linear-gradient(135deg,rgba(37,99,235,.16),var(--card2));border:1px solid rgba(59,130,246,.35);border-radius:12px}.tg-bulk-toolbar span{color:var(--text2);font-size:.78rem}.tg-tab{appearance:none;border:1px solid transparent;background:transparent;color:var(--text2);border-radius:10px;padding:11px 15px;cursor:pointer;font-family:inherit;font-weight:800;font-size:.84rem;transition:.18s ease}.tg-tab:hover{background:var(--card);color:var(--text)}.tg-tab.is-active{background:var(--primary);color:#fff;box-shadow:0 5px 16px rgba(0,0,0,.18)}.tg-tab-panel[hidden]{display:none!important}.tg-tab-panel{animation:tgTabIn .18s ease}@keyframes tgTabIn{from{opacity:.35;transform:translateY(3px)}to{opacity:1;transform:translateY(0)}}@media(max-width:700px){.tg-tabs{position:static;display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.tg-tab{padding:10px 8px;font-size:.76rem}.tg-bulk-toolbar{align-items:stretch}.tg-bulk-toolbar .tg-btn{width:100%}}
  .tg-pager{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;padding:12px 4px 2px}.tg-page-link{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:32px;padding:0 9px;border:1px solid var(--border);border-radius:8px;background:var(--card2);color:var(--text2);text-decoration:none;font-size:.78rem;font-weight:800}.tg-page-link:hover{background:var(--primary);color:#fff;border-color:var(--primary)}.tg-page-link.is-current{background:var(--primary);color:#fff;border-color:var(--primary);pointer-events:none}.tg-page-gap{color:var(--text3);padding:0 2px}.tg-page-meta{color:var(--text3);font-size:.74rem;margin-inline-start:8px}
  </style>
  <div class="tg-wrap">
  <?php if ($notice): ?><div class="alert alert-<?=htmlspecialchars($notice[0])?>" style="margin-bottom:16px"><?=htmlspecialchars($notice[1])?></div><?php endif; ?>
  <div class="page-header"><h2><i class="fab fa-telegram"></i> Telegram Bot</h2></div>
  <nav class="tg-tabs" role="tablist" aria-label="أقسام إدارة Telegram">
    <button type="button" class="tg-tab is-active" role="tab" aria-selected="true" data-tg-tab="overview">الرئيسية والمراقبة</button>
    <button type="button" class="tg-tab" role="tab" aria-selected="false" data-tg-tab="appearance">المظهر والأيقونات</button>
    <button type="button" class="tg-tab" role="tab" aria-selected="false" data-tg-tab="subscription">الاشتراك الإجباري</button>
    <button type="button" class="tg-tab" role="tab" aria-selected="false" data-tg-tab="links">الأقسام والخدمات</button>
    <button type="button" class="tg-tab" role="tab" aria-selected="false" data-tg-tab="logs">السجلات والأخطاء</button>
  </nav>
  <form method="post" id="tg-bulk-save-form" class="tg-bulk-toolbar">
    <?=adminCsrfField()?>
    <input type="hidden" name="bulk_changes" id="tg-bulk-changes" value="">
    <button type="submit" class="tg-btn tg-primary" name="save_bulk_changes" value="1">حفظ كل التغييرات</button>
    <span id="tg-bulk-status">عدّل ما تريد في أي تبويب، ثم اضغط هذا الزر لحفظ جميع التغييرات دفعة واحدة.</span>
  </form>
  <div class="tg-search-toolbar" role="search">
    <label for="tg-admin-search">بحث في لوحة Telegram</label>
    <input type="search" id="tg-admin-search" class="tg-search-input" value="<?=htmlspecialchars($tgEntitySearch, ENT_QUOTES, 'UTF-8')?>" placeholder="ابحث باسم القائمة أو القسم أو الخدمة أو ID أو الإعداد..." autocomplete="off" spellcheck="false">
    <button type="button" id="tg-admin-search-global" class="tg-btn tg-primary">بحث شامل</button>
    <button type="button" id="tg-admin-search-clear" class="tg-btn tg-search-clear">مسح</button>
    <span id="tg-admin-search-count" class="tg-search-count"><?= $tgEntitySearch !== '' ? 'نتائج البحث الشامل: ' . number_format($tgCategoryTotal + $tgServiceTotal) . ' قسم/خدمة' : 'اكتب للبحث داخل جميع التبويبات' ?></span>
  </div>
  <div id="tg-admin-search-empty" class="tg-search-empty">لا توجد نتائج مطابقة في لوحة Telegram.</div>
  <div class="tg-tab-panel" id="tg-tab-overview" data-tg-panel="overview" role="tabpanel">

  <div class="tg-card">
    <h3 style="margin-top:0">الإعدادات الأساسية</h3>
    <form method="post" class="tg-settings-form">
      <?=adminCsrfField()?>
      <div class="tg-grid">
        <div class="tg-field"><label>Bot Token من BotFather</label><input type="password" name="bot_token" placeholder="اتركه فارغاً للإبقاء على القيمة الحالية"><small style="color:var(--text3)"><?=!empty($s['bot_token'])?'تم حفظ Token بالفعل.':''?></small></div>
        <div class="tg-field"><label>اسم البوت</label><input name="bot_username" value="<?=htmlspecialchars($s['bot_username'] ?? '')?>" placeholder="NjazCardBot"></div>
        <div class="tg-field"><label>Webhook URL</label><div class="tg-url"><?=htmlspecialchars($webhookUrl)?></div></div>
        <div class="tg-field"><label>Secret Token</label><input name="webhook_secret" value="<?=htmlspecialchars($s['webhook_secret'] ?? '')?>"><button class="tg-btn tg-muted" name="regenerate_secret" value="1" style="margin-top:7px">توليد جديد</button></div>
      </div>
      <div class="tg-field" style="margin-top:14px"><label>رسالة الترحيب</label><textarea name="welcome_text"><?=htmlspecialchars($s['welcome_text'] ?? '')?></textarea></div>
      <div class="tg-grid" style="margin-top:14px">
        <label class="tg-switch"><input type="checkbox" name="enabled" <?=$s['enabled']?'checked':''?>> تفعيل البوت</label>
        <label class="tg-switch"><input type="checkbox" name="allow_orders" <?=$s['allow_orders']?'checked':''?>> الطلبات</label>
        <label class="tg-switch"><input type="checkbox" name="allow_topup" <?=$s['allow_topup']?'checked':''?>> شحن الرصيد</label>
        <label class="tg-switch"><input type="checkbox" name="allow_balance" <?=$s['allow_balance']?'checked':''?>> عرض الرصيد</label>
        <label class="tg-switch"><input type="checkbox" name="allow_orders_history" <?=$s['allow_orders_history']?'checked':''?>> سجل الطلبات</label>
        <label class="tg-switch"><input type="checkbox" name="allow_profile" <?=$s['allow_profile']?'checked':''?>> الحساب</label>
        <label class="tg-switch"><input type="checkbox" name="allow_telecom" <?=$s['allow_telecom']?'checked':''?>> الاتصالات</label>
        <label class="tg-switch"><input type="checkbox" name="notify_customer_updates" <?=$s['notify_customer_updates']??1?'checked':''?>> إشعارات العملاء عبر Telegram</label>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:17px"><button class="tg-btn tg-primary" name="save_settings" value="1">حفظ الإعدادات</button><button class="tg-btn tg-green" name="set_webhook" value="1">تسجيل Webhook</button><button class="tg-btn tg-danger" name="delete_webhook" value="1">حذف Webhook</button></div>
    </form>
  </div>

    <div class="tg-card"><h3 style="margin-top:0">مراقبة تشغيل البوت</h3><p style="color:var(--text2);font-size:.82rem">الأرقام التالية للمتابعة التشغيلية فقط، وتُقرأ من سجل Telegram وطلبات المستخدمين المرتبطين بحساباتهم في نجاز.</p><div class="tg-grid">
    <div class="tg-card" style="margin:0;background:var(--card2)"><small style="color:var(--text2)">المستخدمون</small><div style="font-size:1.65rem;font-weight:800;margin-top:5px"><?=number_format($tgStats['users'])?></div><small style="color:var(--text3)"><?=number_format($tgStats['linked'])?> مرتبطون بحساب موقع</small></div>
    <div class="tg-card" style="margin:0;background:var(--card2)"><small style="color:var(--text2)">نشطون خلال 7 أيام</small><div style="font-size:1.65rem;font-weight:800;margin-top:5px;color:#00d4aa"><?=number_format($tgStats['active'])?></div><small style="color:var(--text3)"><?=number_format($tgStats['blocked'])?> محظورون</small></div>
    <div class="tg-card" style="margin:0;background:var(--card2)"><small style="color:var(--text2)">تحديثات آخر 24 ساعة</small><div style="font-size:1.65rem;font-weight:800;margin-top:5px"><?=number_format($tgStats['in24'])?></div><small style="color:var(--text3)"><?=number_format($tgStats['out24'])?> إرسال — <?=number_format($tgStats['errors24'])?> أخطاء</small></div>
    <div class="tg-card" style="margin:0;background:var(--card2)"><small style="color:var(--text2)">عمليات مرتبطة بالبوت</small><div style="font-size:1.65rem;font-weight:800;margin-top:5px"><?=number_format($tgStats['orders7'])?></div><small style="color:var(--text3)">خلال 7 أيام — <?=number_format($tgStats['ordersPending'])?> معلقة/قيد التنفيذ</small></div>
    <div class="tg-card" style="margin:0;background:var(--card2)"><small style="color:var(--text2)">طلبات شحن مرتبطة</small><div style="font-size:1.65rem;font-weight:800;margin-top:5px"><?=number_format($tgStats['topups7'])?></div><small style="color:var(--text3)">خلال 7 أيام</small></div>
  </div><?php if ($tgErrors): ?><div class="tg-scroll" style="margin-top:16px"><table class="tg-table"><thead><tr><th>الوقت</th><th>الطريقة</th><th>وصف مختصر</th></tr></thead><tbody><?php foreach ($tgErrors as $err): $desc=''; $decoded=json_decode((string)($err['payload']??''),true); if (is_array($decoded)) $desc=(string)($decoded['description']??$decoded['error']??''); ?><tr><td><?=htmlspecialchars($err['created_at']??'')?></td><td><?=htmlspecialchars($err['method']??'')?></td><td class="tg-off"><?=htmlspecialchars(mb_substr($desc ?: 'فشل في استدعاء Telegram',0,180))?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  </div>
  <div class="tg-tab-panel" id="tg-tab-subscription" data-tg-panel="subscription" role="tabpanel" hidden>
    <div class="tg-card">
      <h3 style="margin-top:0">🔒 الاشتراك الإجباري</h3>
      <p style="color:var(--text2);font-size:.82rem;line-height:1.8">لا يستطيع المستخدم استخدام البوت قبل الاشتراك في كل القنوات والمجموعات المفعّلة. يجب أن يكون البوت مشرفاً في القنوات أو المجموعات حتى يستطيع Telegram إرجاع حالة العضوية عبر <code>getChatMember</code>.</p>
      <form method="post" class="tg-subscription-settings-form">
        <?=adminCsrfField()?>
        <div class="tg-grid">
          <div class="tg-field"><label>رسالة التنبيه للمستخدم قبل الاشتراك</label><textarea name="subscription_message" maxlength="4000" placeholder="اكتب الرسالة التي تظهر للمستخدم قبل السماح له باستخدام البوت..." style="min-height:130px"><?=htmlspecialchars((string)($subscriptionSettings['message'] ?? ''))?></textarea><small style="color:var(--text3)">تظهر هذه الرسالة تلقائياً عند /start أو عند محاولة استخدام أي زر قبل اكتمال الاشتراك. يمكنك استخدام HTML المدعوم من Telegram، وبحد أقصى 4000 حرف.</small></div>
          <div>
            <label class="tg-switch" style="margin-bottom:14px"><input type="checkbox" name="subscription_enabled" value="1" <?=!empty($subscriptionSettings['enabled'])?'checked':''?>> تفعيل الاشتراك الإجباري</label>
            <div class="tg-field"><label>لون أزرار القنوات والمجموعات</label><select name="subscription_button_style"><?=$tgStyleOptions(njazTgUiStyle($subscriptionSettings['button_style'] ?? 'primary'))?></select></div>
            <div class="tg-field" style="margin-top:10px"><label>لون زر التحقق</label><select name="subscription_verify_style"><?=$tgStyleOptions(njazTgUiStyle($subscriptionSettings['verify_style'] ?? 'success'), false)?></select></div>
          </div>
        </div>
        <button class="tg-btn tg-primary" name="save_subscription_settings" value="1" style="margin-top:14px">حفظ إعدادات الاشتراك</button>
      </form>
    </div>
    <div class="tg-card">
      <h3 style="margin-top:0">القنوات والمجموعات المطلوبة</h3>
      <p style="color:var(--text2);font-size:.82rem;line-height:1.8">أضف <b>Chat ID</b> للقناة أو المجموعة مثل <code>@mychannel</code> أو <code>-1001234567890</code>، ثم ضع رابط الانضمام الذي سيظهر للمستخدم كزر.</p>
      <?php $renderSubscriptionTarget = static function (array $target, callable $styleOptions): void {
          $targetId = (int)($target['id'] ?? 0); $type = (string)($target['target_type'] ?? 'channel'); $enabled = !array_key_exists('enabled', $target) || !empty($target['enabled']);
          $esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
      ?>
        <form method="post" class="tg-subscription-target-form" style="background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:12px">
          <?=adminCsrfField()?>
          <input type="hidden" name="target_id" value="<?=$targetId?>">
          <div class="tg-grid">
            <div class="tg-field"><label>النوع</label><select name="target_type"><option value="channel" <?=$type==='channel'?'selected':''?>>قناة</option><option value="group" <?=$type==='group'?'selected':''?>>مجموعة</option></select></div>
            <div class="tg-field"><label>Chat ID أو Username</label><input name="chat_id" value="<?=$esc($target['chat_id'] ?? '')?>" placeholder="@channel أو -1001234567890" dir="ltr"></div>
            <div class="tg-field"><label>اسم العرض</label><input name="title" value="<?=$esc($target['title'] ?? '')?>" placeholder="قناة نجاز الرسمية"></div>
            <div class="tg-field"><label>رابط الانضمام</label><input name="invite_url" value="<?=$esc($target['invite_url'] ?? '')?>" placeholder="https://t.me/channel" dir="ltr"></div>
            <div class="tg-field"><label>نص الزر الاختياري</label><input name="button_text" value="<?=$esc($target['button_text'] ?? '')?>" placeholder="📢 انضم إلى القناة"></div>
            <div class="tg-field"><label>الترتيب</label><input type="number" name="sort_order" value="<?=$esc($target['sort_order'] ?? 0)?>" min="-100000" max="100000"></div>
          </div>
          <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:10px">
            <label class="tg-switch"><input type="checkbox" name="enabled" value="1" <?=$enabled?'checked':''?>> مفعّل</label>
            <?php if ($targetId > 0): ?><label class="tg-switch" style="color:#ff7788"><input type="checkbox" name="delete_target" value="1"> تحديد للحذف عند الحفظ الجماعي</label><?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"><button class="tg-btn tg-primary" name="save_subscription_target" value="1">حفظ الهدف</button><?php if ($targetId > 0): ?><button class="tg-btn tg-danger" name="delete_subscription_target" value="1" onclick="return confirm('حذف هدف الاشتراك؟')">حذف</button><?php endif; ?></div>
        </form>
      <?php };
      foreach ($subscriptionTargets as $target) $renderSubscriptionTarget($target, $tgStyleOptions);
      $renderSubscriptionTarget(['id'=>0,'target_type'=>'channel','enabled'=>1,'sort_order'=>count($subscriptionTargets)], $tgStyleOptions);
      ?>
    </div>
  </div>
  <div class="tg-tab-panel" id="tg-tab-appearance" data-tg-panel="appearance" role="tabpanel" hidden>

  <div class="tg-card" id="ui-settings">
    <h3 style="margin-top:0">إعدادات مظهر القوائم والكتالوج</h3>
    <p style="color:var(--text2);font-size:.82rem">تحكم في ألوان أزرار Telegram وعدد العناصر في الصفحة وعدد الأعمدة وألوان التالي والسابق والرجوع. يدعم Telegram الألوان <code>primary</code> الأزرق و<code>success</code> الأخضر و<code>danger</code> الأحمر فقط.</p>
    <?php
      $renderTgUiForm('home', 'القائمة الرئيسية', $uiSettingsMap, $tgStyleOptions);
      $renderTgUiForm('layout:categories', 'صفحة الأقسام الرئيسية', $uiSettingsMap, $tgStyleOptions);
      foreach ($cats as $uiCat) {
          $uiCatId = (int)($uiCat['id'] ?? 0);
          if ($uiCatId > 0) $renderTgUiForm('layout:category:' . $uiCatId, 'قسم: ' . (string)($uiCat['name'] ?? ('#' . $uiCatId)), $uiSettingsMap, $tgStyleOptions);
      }
    ?>
  </div>

  <div class="tg-card" id="custom-emoji-catalog">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
      <div><h3 style="margin:0 0 6px">كتالوج أيقونات Telegram المخصصة</h3><p style="color:var(--text2);font-size:.82rem;margin:0">يظهر هنا كل Custom Emoji وصل عبر Webhook أو أضفته يدوياً. اضغط <strong>إدراج</strong> بعد تحديد حقل Custom Emoji ID في القسم أو الخدمة، أو استخدم <strong>نسخ ID</strong>.</p></div>
      <form method="post" style="display:inline-flex;gap:7px;align-items:center"><?=adminCsrfField()?><button class="tg-btn tg-muted" name="refresh_custom_emojis" value="1">تحديث الكتالوج</button></form>
    </div>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:16px 0 14px;padding:12px;background:var(--card2);border-radius:10px"><?=adminCsrfField()?><input type="text" name="catalog_custom_emoji_id" inputmode="numeric" pattern="[0-9]{5,64}" placeholder="ألصق Custom Emoji ID لإضافته" style="min-width:280px;background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:7px;padding:9px"><button class="tg-btn tg-primary" name="add_custom_emoji" value="1">إضافة ومعاينة</button><span style="color:var(--text3);font-size:.75rem">يمكنك استخراج المعرّف من سجل Webhook كما شرح الدليل.</span></form>
    <?php if ($customEmojis): ?><div class="tg-emoji-grid">
      <?php foreach ($customEmojis as $emoji): $emojiId=(string)($emoji['custom_emoji_id']??''); $emojiEsc=htmlspecialchars($emojiId, ENT_QUOTES, 'UTF-8'); $hasPreview=!empty($emoji['thumbnail_file_id']) || !empty($emoji['file_id']); ?>
        <div class="tg-emoji-card">
          <img class="tg-emoji-preview" src="telegram_custom_emoji_preview.php?id=<?=$emojiEsc?>" alt="Custom Emoji" loading="lazy">
          <code class="tg-emoji-id" title="<?=$emojiEsc?>"><?=$emojiEsc?></code>
          <small style="display:block;color:<?= $hasPreview ? 'var(--tg-green,#00d4aa)' : 'var(--tg-danger,#ff7788)' ?>;margin-bottom:8px"><?= $hasPreview ? 'المعاينة جاهزة' : 'تحتاج تحديثاً من Telegram' ?></small>
          <?php if (!empty($emoji['last_error'])): ?><small style="display:block;color:var(--text3);margin-bottom:8px"><?=htmlspecialchars((string)$emoji['last_error'])?></small><?php endif; ?>
          <div class="tg-emoji-actions"><button type="button" class="tg-btn tg-green tg-emoji-use" data-emoji-id="<?=$emojiEsc?>">إدراج</button><button type="button" class="tg-btn tg-muted tg-emoji-copy" data-emoji-id="<?=$emojiEsc?>">نسخ ID</button></div>
        </div>
      <?php endforeach; ?></div>
    <?php else: ?><div style="padding:20px;color:var(--text2);background:var(--card2);border-radius:10px">لا توجد أيقونات بعد. أرسل Custom Emoji إلى البوت ثم اضغط «تحديث الكتالوج»، أو ألصق المعرّف في الحقل أعلاه.</div><?php endif; ?>
  </div>

  <div class="tg-card" id="menu-icons">
    <h3 style="margin-top:0">أيقونات كل القوائم والأزرار</h3>
    <p style="color:var(--text2);font-size:.82rem">من هنا تتحكم في Custom Emoji ID لكل عناصر القائمة الرئيسية والقوائم الفرعية وأزرار التنقل والإجراءات. اترك الحقل فارغاً لاستخدام رمز Unicode الحالي. يمكنك الضغط على «إدراج» من كتالوج الأيقونات بعد تحديد الحقل، ثم حفظ الصف.</p>
    <div class="tg-scroll"><table class="tg-table"><thead><tr><th>المجموعة</th><th>العنصر</th><th>Unicode الاحتياطي</th><th>Custom Emoji ID</th><th>المعاينة</th><th></th></tr></thead><tbody>
    <?php foreach ($menuDefinitions as $menu): $menuKey=(string)$menu['key']; $menuEmoji=(string)($menuIconMap[$menuKey]??''); $menuEsc=htmlspecialchars($menuEmoji,ENT_QUOTES,'UTF-8'); ?><tr>
      <td><?=htmlspecialchars((string)$menu['group'])?></td><td><?=htmlspecialchars((string)$menu['label'])?><br><code style="direction:ltr;display:inline-block;color:var(--text3);font-size:.68rem"><?=htmlspecialchars($menuKey)?></code></td><td><?=htmlspecialchars((string)$menu['icon'])?></td>
      <td><form method="post" class="tg-menu-icon-form" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?=adminCsrfField()?><input type="hidden" name="menu_key" value="<?=htmlspecialchars($menuKey,ENT_QUOTES,'UTF-8')?>"><input type="text" name="menu_icon_custom_emoji_id" class="tg-emoji-id-input tg-menu-emoji-input" data-menu-key="<?=htmlspecialchars($menuKey,ENT_QUOTES,'UTF-8')?>" value="<?=$menuEsc?>" inputmode="numeric" pattern="[0-9]{5,64}" placeholder="Custom Emoji ID" title="معرّف رقمي من Telegram" style="min-width:190px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><button class="tg-btn tg-muted" name="save_menu_icon" value="1">حفظ</button></form></td>
      <td><?php if ($menuEmoji !== ''): ?><img src="telegram_custom_emoji_preview.php?id=<?=$menuEsc?>" alt="Custom Emoji" loading="lazy" style="width:42px;height:42px;object-fit:contain;background:#111827;border-radius:8px" title="المعاينة"><?php else: ?><span style="color:var(--text3)">Unicode</span><?php endif; ?></td><td></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>

    <h4 style="margin:18px 0 8px">أيقونات الأقسام والخدمات المضافة من النظام</h4>
    <p style="color:var(--text2);font-size:.82rem">هذه القائمة تُقرأ مباشرة من جداول الأقسام والخدمات في نجاز عند كل فتح للوحة، ولا تعتمد على قائمة ثابتة. لذلك يظهر أي قسم أو خدمة جديدة تلقائياً مع الحفاظ على إعدادات Custom Emoji الحالية. يعرض الجدول 30 عنصراً في الصفحة، ويمكن استخدام البحث الشامل للوصول إلى أي عنصر.</p>
    <div class="tg-scroll"><table class="tg-table"><thead><tr><th>النوع</th><th>العنصر</th><th>Unicode الاحتياطي</th><th>Custom Emoji ID</th><th>المعاينة</th><th>الحالة والحفظ</th></tr></thead><tbody>
    <?php foreach ($cats as $entityCategory): $entityId=(int)($entityCategory['id']??0); $entityEmoji=trim((string)($entityCategory['icon_custom_emoji_id']??'')); $entityEsc=htmlspecialchars($entityEmoji,ENT_QUOTES,'UTF-8'); $entityFallback=njazTgCategoryIcon($entityCategory); $entityName=(string)($entityCategory['name']??('#'.$entityId)); if ($entityId < 1) continue; ?><tr>
      <td>قسم</td><td><?=htmlspecialchars($entityName)?><br><code style="direction:ltr;display:inline-block;color:var(--text3);font-size:.68rem">category:<?=$entityId?></code></td><td style="font-size:1.25rem"><?=htmlspecialchars($entityFallback)?></td>
      <td><form method="post" class="tg-category-form" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?=adminCsrfField()?><input type="hidden" name="category_id" value="<?=$entityId?>"><input type="hidden" name="label_override" value="<?=htmlspecialchars((string)($entityCategory['label_override']??''),ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="media_type" value="<?=htmlspecialchars((string)($entityCategory['media_type']??''),ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="media_url" value="<?=htmlspecialchars((string)($entityCategory['media_url']??''),ENT_QUOTES,'UTF-8')?>"><input type="text" name="icon_custom_emoji_id" class="tg-emoji-id-input" value="<?=$entityEsc?>" inputmode="numeric" pattern="[0-9]{5,64}" placeholder="Custom Emoji ID" title="معرّف رقمي من Telegram" style="min-width:190px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><label class="tg-switch"><input type="checkbox" name="enabled" value="1" <?=$entityCategory['bot_enabled']?'checked':''?>> مفعّل</label><button class="tg-btn tg-muted" name="save_category" value="1">حفظ</button></form></td>
      <td><?php if ($entityEmoji !== ''): ?><img src="telegram_custom_emoji_preview.php?id=<?=$entityEsc?>" alt="Custom Emoji" loading="lazy" style="width:42px;height:42px;object-fit:contain;background:#111827;border-radius:8px" title="المعاينة"><?php else: ?><span style="color:var(--text3)">Unicode</span><?php endif; ?></td><td></td>
    </tr><?php endforeach; ?>
    <?php foreach ($services as $entityService): $entityId=(int)($entityService['id']??0); $entityEmoji=trim((string)($entityService['icon_custom_emoji_id']??'')); $entityEsc=htmlspecialchars($entityEmoji,ENT_QUOTES,'UTF-8'); $entityFallback=njazTgServiceIcon($entityService); $entityName=(string)($entityService['name']??('#'.$entityId)); if ($entityId < 1) continue; ?><tr>
      <td>خدمة</td><td><?=htmlspecialchars($entityName)?><br><code style="direction:ltr;display:inline-block;color:var(--text3);font-size:.68rem">service:<?=$entityId?></code></td><td style="font-size:1.25rem"><?=htmlspecialchars($entityFallback)?></td>
      <td><form method="post" class="tg-service-form" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?=adminCsrfField()?><input type="hidden" name="service_id" value="<?=$entityId?>"><input type="hidden" name="label_override" value="<?=htmlspecialchars((string)($entityService['label_override']??''),ENT_QUOTES,'UTF-8')?>"><input type="text" name="icon_custom_emoji_id" class="tg-emoji-id-input" value="<?=$entityEsc?>" inputmode="numeric" pattern="[0-9]{5,64}" placeholder="Custom Emoji ID" title="معرّف رقمي من Telegram" style="min-width:190px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><label class="tg-switch"><input type="checkbox" name="enabled" value="1" <?=$entityService['bot_enabled']?'checked':''?>> مفعّل</label><button class="tg-btn tg-muted" name="save_service" value="1">حفظ</button></form></td>
      <td><?php if ($entityEmoji !== ''): ?><img src="telegram_custom_emoji_preview.php?id=<?=$entityEsc?>" alt="Custom Emoji" loading="lazy" style="width:42px;height:42px;object-fit:contain;background:#111827;border-radius:8px" title="المعاينة"><?php else: ?><span style="color:var(--text3)">Unicode</span><?php endif; ?></td><td></td>
    </tr><?php endforeach; ?>
    <?php if (!$cats && !$services): ?><tr><td colspan="6" style="text-align:center;color:var(--text2)">لا توجد أقسام أو خدمات مفعّلة حالياً.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php $tgAppearancePageUrl = static function (string $type, int $page) use ($tgPageUrl): string { return $tgPageUrl($type, $page, 'appearance'); }; ?>
    <div style="color:var(--text3);font-size:.74rem;margin-top:8px">صفحة الأقسام الحالية: <?=$tgCategoryPage?> من <?=$tgCategoryPages?> — صفحة الخدمات الحالية: <?=$tgServicePage?> من <?=$tgServicePages?></div>
    <?php $renderTgPager('category', $tgCategoryPage, $tgCategoryPages, $tgAppearancePageUrl); $renderTgPager('service', $tgServicePage, $tgServicePages, $tgAppearancePageUrl); ?>
  </div>

  <div class="tg-card" id="menu-ui-settings">
    <h3 style="margin-top:0">ألوان عناصر القوائم والإجراءات</h3>
    <p style="color:var(--text2);font-size:.82rem">يمكن تغيير لون كل زر في القائمة الرئيسية والقوائم الفرعية دون تغيير callback أو منطق الطلبات.</p>
    <?php foreach ($menuDefinitions as $uiMenu): $uiMenuKey = (string)($uiMenu['key'] ?? ''); if ($uiMenuKey === '') continue; $renderTgUiForm($uiMenuKey, (string)($uiMenu['group'] ?? '') . ' — ' . (string)($uiMenu['label'] ?? $uiMenuKey), $uiSettingsMap, $tgStyleOptions, false); endforeach; ?>
  </div>

  <div class="tg-card" id="payment-ui-settings">
    <h3 style="margin-top:0">ألوان وأيقونات وسائل الدفع</h3>
    <p style="color:var(--text2);font-size:.82rem">تحكم في لون زر كل وسيلة دفع ظاهرة في شاشة شحن الرصيد، وأضف Custom Emoji ID خاصاً بها. تُحفظ هذه الإعدادات لبوت Telegram فقط ولا تغيّر إعدادات الدفع في الموقع أو callbacks الدفع.</p>
    <?php foreach ($paymentMethods as $paymentMethod): $paymentId=(int)($paymentMethod['id']??0); if ($paymentId < 1) continue; $paymentMode=(string)($paymentMethod['payment_mode']??'manual'); $paymentIcon=trim((string)($paymentMethod['icon']??'')); if ($paymentIcon === '') $paymentIcon=$paymentMode === 'auto' ? '⚡' : '💳'; $paymentLabel=($paymentMode === 'auto' ? 'دفع مباشر — ' : 'دفع يدوي — ') . (string)($paymentMethod['name']??('#'.$paymentId)); $renderTgPaymentForm('payment:method:' . $paymentId, $paymentLabel, $paymentIcon, $uiSettingsMap, $menuIconMap, $tgStyleOptions); endforeach; ?>
    <?php $renderTgPaymentForm('topup:card', 'شحن بكود البطاقة', '🎫', $uiSettingsMap, $menuIconMap, $tgStyleOptions); ?>
    <?php if (getSetting('floosak_enabled') === '1' && trim((string)getSetting('floosak_merchant_key')) !== ''): $renderTgPaymentForm('topup:floosak', 'فلوسك — دفع مباشر', '💳', $uiSettingsMap, $menuIconMap, $tgStyleOptions); endif; ?>
    <?php $renderTgPaymentForm('topup:binance', 'بينانس مباشر', '◈', $uiSettingsMap, $menuIconMap, $tgStyleOptions); ?>
    <?php $renderTgPaymentForm('topup:usdt', 'USDT — BEP20 مباشر', '₮', $uiSettingsMap, $menuIconMap, $tgStyleOptions); ?>
  </div>

  <div class="tg-card" id="entity-ui-settings">
    <h3 style="margin-top:0">ألوان الأقسام والخدمات</h3>
    <p style="color:var(--text2);font-size:.82rem">تخصيص لون أزرار كل قسم وكل خدمة بشكل مستقل. اتركه على الأزرق أو الأخضر الافتراضي إذا لم تكن بحاجة إلى تخصيص خاص.</p>
    <?php foreach ($cats as $uiCat): $uiCatId = (int)($uiCat['id'] ?? 0); if ($uiCatId > 0) $renderTgUiForm('item:category:' . $uiCatId, 'قسم — ' . (string)($uiCat['name'] ?? ('#' . $uiCatId)), $uiSettingsMap, $tgStyleOptions, false); endforeach; ?>
    <?php foreach ($services as $uiService): $uiServiceId = (int)($uiService['id'] ?? 0); if ($uiServiceId > 0) $renderTgUiForm('item:service:' . $uiServiceId, 'خدمة — ' . (string)($uiService['name'] ?? ('#' . $uiServiceId)), $uiSettingsMap, $tgStyleOptions, false); endforeach; ?>
  </div>
  </div>
  <div class="tg-tab-panel" id="tg-tab-links" data-tg-panel="links" role="tabpanel" hidden>

  <div class="tg-card"><h3 style="margin-top:0">ربط الأقسام</h3>
<p style="color:var(--text2);font-size:.82rem">هذه الخيارات تتحكم في ظهور نفس الأقسام الموجودة في النظام داخل البوت. يمكن وضع معرّف Custom Emoji الرقمي في الحقل المخصص؛ اتركه فارغاً لاستخدام Unicode التلقائي أو رمز القسم. يستخدم البوت تلقائياً صورة القسم الأصلية المحفوظة في الموقع، وتُحدّث في Telegram عند تغييرها في إدارة الأقسام. حقول الوسيط اليدوي أدناه احتياطية فقط عند عدم وجود صورة أصلية.</p><div class="tg-scroll"><table class="tg-table"><thead><tr><th>القسم</th><th>النوع</th><th>صورة الموقع</th><th>الحالة</th><th>التسمية والوسيط الاحتياطي</th><th></th></tr></thead><tbody>
  <?php foreach ($cats as $c): ?><tr><td><?=htmlspecialchars($c['name'])?></td><td><?=($c['category_type']??'default')==='telecom'?'اتصالات':'عادي'?></td><td><?=!empty($c['image'])?'<span class="tg-on">موجودة</span>':'<span class="tg-off">غير موجودة</span>'?></td><td class="<?=$c['bot_enabled']?'tg-on':'tg-off'?>"><?=$c['bot_enabled']?'مفعل':'مخفي'?></td><td><form method="post" class="tg-category-form" style="display:flex;gap:6px;align-items:center"><?=adminCsrfField()?><input type="hidden" name="category_id" value="<?=$c['id']?>"><input type="text" name="label_override" value="<?=htmlspecialchars($c['label_override']??'')?>" placeholder="تسمية بديلة" style="max-width:150px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><select name="media_type" style="max-width:110px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><option value="">بدون وسائط</option><option value="photo" <?=($c['media_type']??'')==='photo'?'selected':''?>>صورة</option><option value="animation" <?=($c['media_type']??'')==='animation'?'selected':''?>>GIF</option></select><input type="url" name="media_url" value="<?=htmlspecialchars($c['media_url']??'')?>" placeholder="رابط صورة/GIF" style="min-width:180px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><input type="text" name="icon_custom_emoji_id" class="tg-emoji-id-input" value="<?=htmlspecialchars($c['icon_custom_emoji_id']??'')?>" placeholder="Custom Emoji ID" title="معرّف رقمي من Telegram، وليس رابط صورة" style="min-width:150px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><label><input type="checkbox" name="enabled" <?=$c['bot_enabled']?'checked':''?>> إظهار</label><button class="tg-btn tg-muted" name="save_category" value="1">حفظ</button></form></td><td></td></tr><?php endforeach; if (!$cats && $tgEntitySearch !== ''): ?><tr><td colspan="6" style="text-align:center;color:var(--text2)">لا توجد أقسام مطابقة لعبارة البحث.</td></tr><?php endif; ?>
  </tbody></table></div><?php $renderTgPager('category', $tgCategoryPage, $tgCategoryPages, $tgPageUrl); ?></div>

  <div class="tg-card"><h3 style="margin-top:0">ربط الخدمات</h3><p style="color:var(--text2);font-size:.82rem">يستخدم البوت السعر المحسوب من مجموعة تسعير العميل، ثم يمرر الطلب إلى نقطة الطلب الحالية التي تستخدم المزودين وواجهات APIs نفسها. عند فتح الخدمة أو مراجعة الطلب، يستخدم البوت تلقائياً الصورة الأصلية من حقل الخدمة في الموقع.</p><div class="tg-scroll"><table class="tg-table"><thead><tr><th>ID</th><th>الخدمة</th><th>القسم</th><th>السعر الأساسي</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>
  <?php foreach ($services as $v): ?><tr><td><?=intval($v['id'])?></td><td><?=htmlspecialchars($v['name'])?></td><td><?=htmlspecialchars($v['category_name'])?></td><td><?=number_format((float)$v['price'],4)?> $</td><td class="<?=$v['bot_enabled']?'tg-on':'tg-off'?>"><?=$v['bot_enabled']?'مفعل':'مخفي'?></td><td><form method="post" class="tg-service-form" style="display:flex;gap:6px;align-items:center"><?=adminCsrfField()?><input type="hidden" name="service_id" value="<?=$v['id']?>"><input type="text" name="label_override" value="<?=htmlspecialchars($v['label_override']??'')?>" placeholder="تسمية بديلة" style="max-width:140px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><input type="text" name="icon_custom_emoji_id" class="tg-emoji-id-input" value="<?=htmlspecialchars($v['icon_custom_emoji_id']??'')?>" placeholder="Custom Emoji ID" title="معرّف رقمي من Telegram، وليس رابط صورة" style="min-width:150px;background:var(--card2);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:6px"><label><input type="checkbox" name="enabled" <?=$v['bot_enabled']?'checked':''?>> إظهار</label><button class="tg-btn tg-muted" name="save_service" value="1">حفظ</button></form></td></tr><?php endforeach; if (!$services && $tgEntitySearch !== ''): ?><tr><td colspan="6" style="text-align:center;color:var(--text2)">لا توجد خدمات مطابقة لعبارة البحث.</td></tr><?php endif; ?>
  </tbody></table></div><?php $renderTgPager('service', $tgServicePage, $tgServicePages, $tgPageUrl); ?>  </div>
  </div>
  <div class="tg-tab-panel" id="tg-tab-logs" data-tg-panel="logs" role="tabpanel" hidden>

  <div class="tg-card"><h3 style="margin-top:0">آخر سجلات البوت</h3><div class="tg-scroll"><table class="tg-table"><thead><tr><th>الوقت</th><th>الاتجاه</th><th>الطريقة</th><th>Telegram ID</th></tr></thead><tbody><?php foreach ($logs as $l): ?><tr><td><?=htmlspecialchars($l['created_at'])?></td><td><?=htmlspecialchars($l['direction'])?></td><td><?=htmlspecialchars($l['method'])?></td><td><?=htmlspecialchars($l['telegram_id']??'')?></td></tr><?php endforeach; if (!$logs): ?><tr><td colspan="4">لا توجد سجلات بعد.</td></tr><?php endif; ?></tbody></table></div></div>
  </div>
  </div>
<script>
(function(){
  let activeField = null;
  document.addEventListener('focusin', function(event){
    if (event.target.classList && event.target.classList.contains('tg-emoji-id-input')) activeField = event.target;
  });
  function copyId(id){
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(id);
    const helper=document.createElement('textarea'); helper.value=id; helper.style.position='fixed'; helper.style.opacity='0'; document.body.appendChild(helper); helper.select(); document.execCommand('copy'); helper.remove(); return Promise.resolve();
  }
  document.querySelectorAll('.tg-emoji-use').forEach(function(button){
    button.addEventListener('click', function(){
      const id=button.dataset.emojiId || '';
      if (activeField && document.body.contains(activeField)) { activeField.value=id; activeField.dispatchEvent(new Event('input',{bubbles:true})); activeField.focus(); button.textContent='تم الإدراج'; setTimeout(function(){button.textContent='إدراج';},1200); }
      else { copyId(id).then(function(){button.textContent='تم النسخ'; setTimeout(function(){button.textContent='إدراج';},1200);}); }
    });
  });
  document.querySelectorAll('.tg-emoji-copy').forEach(function(button){
    button.addEventListener('click', function(){ const id=button.dataset.emojiId || ''; copyId(id).then(function(){button.textContent='تم النسخ'; setTimeout(function(){button.textContent='نسخ ID';},1200);}); });
  });
  const tabs = Array.from(document.querySelectorAll('[data-tg-tab]'));
  const panels = Array.from(document.querySelectorAll('[data-tg-panel]'));
  function activateTab(key, updateUrl){
    if (!tabs.some(function(tab){ return tab.dataset.tgTab === key; })) key = 'overview';
    tabs.forEach(function(tab){
      const active = tab.dataset.tgTab === key;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    panels.forEach(function(panel){ panel.hidden = panel.dataset.tgPanel !== key; });
    try { sessionStorage.setItem('njazTgActiveTab', key); } catch (e) {}
    if (updateUrl && window.history && window.history.replaceState) window.history.replaceState(null, '', '#' + key);
  }
  tabs.forEach(function(tab){ tab.addEventListener('click', function(){ activateTab(tab.dataset.tgTab, true); }); });
  const editableForms = Array.from(document.querySelectorAll('.tg-settings-form,.tg-ui-form,.tg-menu-icon-form,.tg-category-form,.tg-service-form,.tg-subscription-settings-form,.tg-subscription-target-form'));
  const bulkStatus = document.getElementById('tg-bulk-status');
  const initialFormState = new WeakMap();
  function formState(form){ return new URLSearchParams(new FormData(form)).toString(); }
  function refreshBulkStatus(){
    const changed = editableForms.filter(function(form){ return initialFormState.get(form) !== formState(form); }).length;
    if (bulkStatus) bulkStatus.textContent = changed ? ('تم تعديل ' + changed + ' نموذج — اضغط «حفظ كل التغييرات» لتطبيقها.') : 'عدّل ما تريد في أي تبويب، ثم اضغط هذا الزر لحفظ جميع التغييرات دفعة واحدة.';
  }
  editableForms.forEach(function(form){
    initialFormState.set(form, formState(form));
    form.addEventListener('input', refreshBulkStatus);
    form.addEventListener('change', refreshBulkStatus);
  });
  const searchInput = document.getElementById('tg-admin-search');
  const searchClear = document.getElementById('tg-admin-search-clear');
  const searchCount = document.getElementById('tg-admin-search-count');
  const searchEmpty = document.getElementById('tg-admin-search-empty');
  const searchableCards = Array.from(document.querySelectorAll('.tg-card'));
  const searchableForms = Array.from(document.querySelectorAll('.tg-settings-form,.tg-ui-form,.tg-menu-icon-form,.tg-payment-ui-form,.tg-category-form,.tg-service-form'));
  const searchableRows = Array.from(document.querySelectorAll('.tg-table tbody tr'));
  const normalizeSearch = function(value){ return String(value || '').toLocaleLowerCase().replace(/[\u064B-\u065F\u0670]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/\s+/g, ' ').trim(); };
  function runSearch(){
    const query = normalizeSearch(searchInput ? searchInput.value : '');
    let matches = 0;
    if (!query) {
      searchableCards.forEach(function(card){ card.hidden = false; });
      searchableForms.forEach(function(form){ form.hidden = false; });
      searchableRows.forEach(function(row){ row.hidden = false; });
      if (searchCount) searchCount.textContent = 'اكتب للبحث داخل جميع التبويبات';
      if (searchEmpty) searchEmpty.style.display = 'none';
      return;
    }
    searchableCards.forEach(function(card){
      const insideNested = card.querySelectorAll('.tg-card').length > 0;
      if (insideNested) return;
      const text = normalizeSearch(card.textContent);
      const match = text.includes(query);
      card.hidden = !match;
      if (match) matches++;
    });
    searchableForms.forEach(function(form){
      const text = normalizeSearch(form.textContent + ' ' + Array.from(form.querySelectorAll('input,select,textarea')).map(function(input){ return input.value + ' ' + input.name; }).join(' '));
      const match = text.includes(query);
      form.hidden = !match;
      if (match) matches++;
    });
    searchableRows.forEach(function(row){
      const text = normalizeSearch(row.textContent);
      const match = text.includes(query);
      row.hidden = !match;
      if (match) matches++;
    });
    if (searchCount) searchCount.textContent = matches ? ('تم العثور على ' + matches + ' نتيجة') : 'لا توجد نتائج';
    if (searchEmpty) searchEmpty.style.display = matches ? 'none' : 'block';
    if (matches) {
      const first = searchableCards.find(function(card){ return !card.hidden; }) || searchableForms.find(function(form){ return !form.hidden; }) || searchableRows.find(function(row){ return !row.hidden; });
      const panel = first && first.closest('[data-tg-panel]');
      if (panel && panel.dataset.tgPanel) activateTab(panel.dataset.tgPanel, false);
    }
  }
  function submitGlobalEntitySearch(){
    const value = String(searchInput ? searchInput.value : '').trim();
    const url = new URL(window.location.href);
    if (value) url.searchParams.set('tg_entity_search', value); else url.searchParams.delete('tg_entity_search');
    url.searchParams.set('tg_category_page', '1');
    url.searchParams.set('tg_service_page', '1');
    url.hash = 'links';
    window.location.href = url.toString();
  }
  const globalSearchButton = document.getElementById('tg-admin-search-global');
  if (globalSearchButton) globalSearchButton.addEventListener('click', submitGlobalEntitySearch);
  if (searchInput) {
    searchInput.addEventListener('input', runSearch);
    searchInput.addEventListener('keydown', function(event){ if (event.key === 'Enter') { event.preventDefault(); submitGlobalEntitySearch(); } });
  }
  if (searchClear) searchClear.addEventListener('click', function(){
    if (searchInput) searchInput.value = '';
    const url = new URL(window.location.href);
    if (url.searchParams.has('tg_entity_search')) {
      url.searchParams.delete('tg_entity_search');
      url.searchParams.set('tg_category_page', '1');
      url.searchParams.set('tg_service_page', '1');
      url.hash = 'links';
      window.location.href = url.toString();
      return;
    }
    runSearch();
    if (searchInput) searchInput.focus();
  });
  const bulkForm = document.getElementById('tg-bulk-save-form');
  if (bulkForm) {
    bulkForm.addEventListener('submit', function(event){
      event.preventDefault();
      const changes = [];
      function collect(selector, kind){
        document.querySelectorAll(selector).forEach(function(form){
          if (initialFormState.get(form) === formState(form)) return;
          const data = Object.fromEntries(new FormData(form).entries());
          delete data._csrf;
          changes.push({kind: kind, data: data});
        });
      }
      collect('.tg-settings-form', 'settings');
      collect('.tg-ui-form', 'ui');
      collect('.tg-menu-icon-form', 'menu');
      collect('.tg-payment-ui-form', 'payment_ui');
      collect('.tg-category-form', 'category');
      collect('.tg-service-form', 'service');
      collect('.tg-subscription-settings-form', 'subscription_settings');
      collect('.tg-subscription-target-form', 'subscription_target');
      const field = document.getElementById('tg-bulk-changes');
      if (!changes.length) { window.alert('لا توجد نماذج قابلة للحفظ الجماعي.'); return; }
      field.value = JSON.stringify(changes);
      HTMLFormElement.prototype.submit.call(bulkForm);
    });
  }
  let initialTab = (window.location.hash || '').replace('#','');
  if (!initialTab) { try { initialTab = sessionStorage.getItem('njazTgActiveTab') || ''; } catch (e) {} }
  activateTab(initialTab || 'overview', false);
})();
</script>
<?php include 'footer.php'; ?>
