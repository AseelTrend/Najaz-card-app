<?php
require_once '../includes/config.php';
require_once '../includes/language_catalog.php';
requireAdmin();
$pageTitle = 'لغات العرض — ' . SITE_NAME;

// لا تنفذ التهيئة أو المزامنة تلقائياً عند فتح الصفحة؛ القراءة والتصفح يجب أن يبقيا خفيفين.

function langAdminH($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function langAdminRedirect(string $tab = 'languages'): void { redirect(SITE_URL . '/admin/languages.php?tab=' . rawurlencode($tab)); }

// تصدير قبل تضمين قالب الإدارة حتى لا تسبق headers أي مخرجات.
if (($_GET['export'] ?? '') === 'json') {
    $languages = $pdo->query('SELECT language_code,language_name,language_name_native,direction,flag_emoji,status,sort_order,is_default FROM display_languages ORDER BY sort_order,id')->fetchAll();
    $translationRows = $pdo->query('SELECT language_code,source_text,translated_text,context,status FROM language_translations ORDER BY language_code,id')->fetchAll();
    $translations = [];
    foreach ($translationRows as $row) {
        $translations[$row['language_code']][] = [
            'source_text' => $row['source_text'],
            'translated_text' => $row['translated_text'],
            'context' => $row['context'],
            'status' => (int)$row['status'],
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="njaz-display-languages-' . date('Y-m-d') . '.json"');
    echo json_encode(['version' => 1, 'exported_at' => date('c'), 'languages' => $languages, 'translations' => $translations], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminCsrfVerify();
    $tab = ($_POST['tab'] ?? 'languages') === 'translations' ? 'translations' : 'languages';

    if (isset($_POST['sync_js'])) {
        $result = njazLanguageSyncFromJs($pdo, !empty($_POST['replace_existing']));
        flashMessage('success', 'تمت مزامنة ' . (int)$result['languages'] . ' لغة و' . (int)$result['translations'] . ' ترجمة من i18n.js.');
        langAdminRedirect('translations');
    }

    if (isset($_POST['sync_dynamic'])) {
        $count = njazLanguageSyncDynamicEntities($pdo, true);
        flashMessage('success', 'تم تحديث كتالوج الأقسام والخدمات والحقول والخيارات. أضيفت ' . (int)$count . ' سجلات جديدة، ويمكن الآن ترجمتها من تبويب الترجمات.');
        langAdminRedirect('translations');
    }

    if (isset($_POST['import_json'])) {
        $payload = json_decode((string)($_POST['json_payload'] ?? ''), true);
        if (!is_array($payload)) {
            flashMessage('danger', 'ملف JSON غير صالح.');
            langAdminRedirect('languages');
        }
        $addedLanguages = 0;
        $addedTranslations = 0;
        try {
            $pdo->beginTransaction();
            $languageStmt = $pdo->prepare("INSERT INTO display_languages
                (language_code,language_name,language_name_native,direction,flag_emoji,status,sort_order,is_default)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE language_name=VALUES(language_name),language_name_native=VALUES(language_name_native),direction=VALUES(direction),flag_emoji=VALUES(flag_emoji),status=VALUES(status),sort_order=VALUES(sort_order),is_default=VALUES(is_default)");
            foreach ((array)($payload['languages'] ?? []) as $language) {
                $code = strtolower(trim((string)($language['language_code'] ?? $language['code'] ?? '')));
                $name = trim((string)($language['language_name'] ?? $language['name'] ?? $code));
                $native = trim((string)($language['language_name_native'] ?? $language['native_name'] ?? $name));
                $direction = ($language['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
                if (!preg_match('/^[a-z][a-z0-9_-]{1,19}$/', $code) || $name === '') continue;
                $languageStmt->execute([$code,$name,$native,(string)$direction,trim((string)($language['flag_emoji'] ?? $language['flag'] ?? '')) ?: null,isset($language['status']) ? (int)!empty($language['status']) : 1,(int)($language['sort_order'] ?? 0),!empty($language['is_default']) ? 1 : 0]);
                $addedLanguages++;
            }
            $find = $pdo->prepare('SELECT id FROM language_translations WHERE language_code=? AND source_text=? AND context <=> ? LIMIT 1');
            $insert = $pdo->prepare('INSERT INTO language_translations (language_code,source_text,translated_text,context,status) VALUES (?,?,?,?,?)');
            $update = $pdo->prepare('UPDATE language_translations SET translated_text=?,status=? WHERE id=?');
            foreach ((array)($payload['translations'] ?? []) as $code => $items) {
                $code = strtolower(trim((string)$code));
                if (!preg_match('/^[a-z][a-z0-9_-]{1,19}$/', $code)) continue;
                if (is_array($items) && array_is_list($items)) {
                    $iterable = $items;
                } elseif (is_array($items)) {
                    $iterable = [];
                    foreach ($items as $source => $translated) $iterable[] = ['source_text' => $source, 'translated_text' => $translated];
                } else continue;
                foreach ($iterable as $item) {
                    $source = trim((string)($item['source_text'] ?? $item['source'] ?? ''));
                    $translated = trim((string)($item['translated_text'] ?? $item['translated'] ?? $item['value'] ?? ''));
                    $context = trim((string)($item['context'] ?? '')) ?: null;
                    if ($source === '' || $translated === '') continue;
                    $find->execute([$code,$source,$context]);
                    $id = $find->fetchColumn();
                    if ($id) $update->execute([$translated,isset($item['status']) ? (int)!empty($item['status']) : 1,(int)$id]);
                    else { $insert->execute([$code,$source,$translated,$context,isset($item['status']) ? (int)!empty($item['status']) : 1]); $addedTranslations++; }
                }
            }
            $pdo->commit();
            flashMessage('success', "تم استيراد $addedLanguages لغة و$addedTranslations ترجمة بنجاح.");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Language JSON import error: ' . $e->getMessage());
            flashMessage('danger', 'تعذر استيراد الملف. تحقق من البنية والبيانات.');
        }
        langAdminRedirect('languages');
    }

    if (isset($_POST['save_language'])) {
        $id = (int)($_POST['id'] ?? 0);
        $code = strtolower(trim((string)($_POST['language_code'] ?? '')));
        $name = trim((string)($_POST['language_name'] ?? ''));
        $native = trim((string)($_POST['language_name_native'] ?? ''));
        $direction = ($_POST['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
        $flag = trim((string)($_POST['flag_emoji'] ?? '')) ?: null;
        $status = isset($_POST['status']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        $default = isset($_POST['is_default']) ? 1 : 0;
        if (!preg_match('/^[a-z][a-z0-9_-]{1,19}$/', $code) || $name === '' || $native === '') {
            flashMessage('danger', 'أدخل كوداً صحيحاً واسم اللغة والاسم المحلي.');
        } else {
            try {
                if ($default) $pdo->exec('UPDATE display_languages SET is_default=0');
                if ($id) {
                    $oldStmt = $pdo->prepare('SELECT language_code FROM display_languages WHERE id=?');
                    $oldStmt->execute([$id]);
                    $oldCode = (string)$oldStmt->fetchColumn();
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare('UPDATE display_languages SET language_code=?,language_name=?,language_name_native=?,direction=?,flag_emoji=?,status=?,sort_order=?,is_default=? WHERE id=?');
                        $stmt->execute([$code,$name,$native,$direction,$flag,$status,$sort,$default,$id]);
                        if ($oldCode !== '' && $oldCode !== $code) {
                            $pdo->prepare('UPDATE language_translations SET language_code=? WHERE language_code=?')->execute([$code,$oldCode]);
                        }
                        $pdo->commit();
                    } catch (Throwable $inner) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $inner;
                    }
                    flashMessage('success', 'تم تحديث اللغة ' . $native . '.');
                } else {
                    $stmt = $pdo->prepare('INSERT INTO display_languages (language_code,language_name,language_name_native,direction,flag_emoji,status,sort_order,is_default) VALUES (?,?,?,?,?,?,?,?)');
                    $stmt->execute([$code,$name,$native,$direction,$flag,$status,$sort,$default]);
                    flashMessage('success', 'تمت إضافة اللغة ' . $native . '.');
                }
            } catch (Throwable $e) {
                flashMessage('danger', $e->getCode() === '23000' ? 'كود اللغة مستخدم مسبقاً.' : 'تعذر حفظ اللغة.');
            }
        }
        langAdminRedirect('languages');
    }

    if (isset($_POST['save_translation'])) {
        $id = (int)($_POST['id'] ?? 0);
        $code = strtolower(trim((string)($_POST['language_code'] ?? '')));
        $source = trim((string)($_POST['source_text'] ?? ''));
        $translated = trim((string)($_POST['translated_text'] ?? ''));
        $context = trim((string)($_POST['context'] ?? '')) ?: null;
        $status = isset($_POST['status']) ? 1 : 0;
        if ($code === '' || $source === '' || $translated === '') {
            flashMessage('danger', 'اللغة والنص الأصلي والترجمة حقول مطلوبة.');
        } else {
            try {
                if ($id) {
                    $stmt = $pdo->prepare('UPDATE language_translations SET language_code=?,source_text=?,translated_text=?,context=?,status=? WHERE id=?');
                    $stmt->execute([$code,$source,$translated,$context,$status,$id]);
                } else {
                    $find = $pdo->prepare('SELECT id FROM language_translations WHERE language_code=? AND source_text=? AND context <=> ? LIMIT 1');
                    $find->execute([$code,$source,$context]);
                    $existing = $find->fetchColumn();
                    if ($existing) $pdo->prepare('UPDATE language_translations SET translated_text=?,status=? WHERE id=?')->execute([$translated,$status,(int)$existing]);
                    else $pdo->prepare('INSERT INTO language_translations (language_code,source_text,translated_text,context,status) VALUES (?,?,?,?,?)')->execute([$code,$source,$translated,$context,$status]);
                }
                flashMessage('success', 'تم حفظ الترجمة، وستظهر في الموقع والبوت عند القراءة التالية.');
            } catch (Throwable $e) {
                error_log('Language translation save error: ' . $e->getMessage());
                flashMessage('danger', 'تعذر حفظ الترجمة.');
            }
        }
        langAdminRedirect('translations');
    }

    if (isset($_POST['delete_language'])) {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT language_code,is_default FROM display_languages WHERE id=?');
        $row->execute([$id]); $row = $row->fetch();
        if (!$row) flashMessage('danger', 'اللغة غير موجودة.');
        elseif ((int)$row['is_default'] === 1) flashMessage('danger', 'عيّن لغة افتراضية أخرى قبل حذف هذه اللغة.');
        else {
            $pdo->prepare('DELETE FROM language_translations WHERE language_code=?')->execute([$row['language_code']]);
            $pdo->prepare('DELETE FROM display_languages WHERE id=?')->execute([$id]);
            flashMessage('success', 'تم حذف اللغة وترجماتها.');
        }
        langAdminRedirect('languages');
    }

    if (isset($_POST['delete_translation'])) {
        $pdo->prepare('DELETE FROM language_translations WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flashMessage('success', 'تم حذف الترجمة.');
        langAdminRedirect('translations');
    }

    if (isset($_POST['toggle_language'])) {
        $pdo->prepare('UPDATE display_languages SET status=1-status WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        langAdminRedirect('languages');
    }
    if (isset($_POST['toggle_translation'])) {
        $pdo->prepare('UPDATE language_translations SET status=1-status WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        langAdminRedirect('translations');
    }
    if (isset($_POST['set_default'])) {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $pdo->exec('UPDATE display_languages SET is_default=0');
        $pdo->prepare('UPDATE display_languages SET is_default=1,status=1 WHERE id=?')->execute([$id]);
        $pdo->commit();
        flashMessage('success', 'تم تعيين اللغة الافتراضية.');
        langAdminRedirect('languages');
    }
}

$tab = ($_GET['tab'] ?? 'languages') === 'translations' ? 'translations' : 'languages';
$languages = $pdo->query('SELECT * FROM display_languages ORDER BY sort_order,id')->fetchAll();
$activeLanguages = array_values(array_filter($languages, static fn($row) => (int)$row['status'] === 1));
$languageByCode = [];
foreach ($languages as $language) $languageByCode[$language['language_code']] = $language;

$editLanguage = null;
if (!empty($_GET['edit_language'])) {
    $stmt = $pdo->prepare('SELECT * FROM display_languages WHERE id=?'); $stmt->execute([(int)$_GET['edit_language']]); $editLanguage = $stmt->fetch() ?: null;
}
$editTranslation = null;
if (!empty($_GET['edit_translation'])) {
    $stmt = $pdo->prepare('SELECT * FROM language_translations WHERE id=?'); $stmt->execute([(int)$_GET['edit_translation']]); $editTranslation = $stmt->fetch() ?: null;
}

$filterLanguage = strtolower(trim((string)($_GET['language'] ?? '')));
$search = trim((string)($_GET['q'] ?? ''));
$showAllRows = (($_GET['all'] ?? '') === '1');
$total = 0;
$pages = 1;
$page = max(1, (int)($_GET['page'] ?? 1));
$translationRows = [];
if ($tab === 'translations') {
    $perPage = 30;
    $where = [];
    $params = [];
    // سجلات المزامنة غير المترجمة تكون نسخاً مطابقة للمصدر؛ لا نعرضها افتراضياً حتى لا تثقل الصفحة.
    if (!$showAllRows) { $where[] = '(context IS NULL OR translated_text <> source_text)'; }
    if ($filterLanguage !== '') { $where[] = 'language_code=?'; $params[] = $filterLanguage; }
    if ($search !== '') { $where[] = '(source_text LIKE ? OR translated_text LIKE ? OR context LIKE ?)'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like); }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM language_translations' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $translationStmt = $pdo->prepare('SELECT * FROM language_translations' . $whereSql . ' ORDER BY language_code, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $translationStmt->execute($params);
    $translationRows = $translationStmt->fetchAll();
}

include 'header.php';
?>
<style>
.lang-admin-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}.lang-admin-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 15px;border:1px solid var(--border);border-radius:11px;color:var(--text3);text-decoration:none;background:var(--card2);font-weight:800;font-size:.86rem}.lang-admin-tab.active{color:#fff;background:linear-gradient(135deg,#1769ff,#6c3fe0);border-color:transparent}.lang-grid{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:16px;align-items:start}.lang-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:18px}.lang-card h3{margin:0 0 14px;font-size:1rem;display:flex;align-items:center;gap:8px}.lang-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.lang-field{display:flex;flex-direction:column;gap:5px;margin-bottom:10px}.lang-field.full{grid-column:1/-1}.lang-field label{font-size:.76rem;color:var(--text3);font-weight:700}.lang-field input,.lang-field select,.lang-field textarea{width:100%;box-sizing:border-box;background:var(--bg2);border:1px solid var(--border2);border-radius:9px;padding:9px 10px;color:var(--text);font:inherit;font-size:.84rem;outline:none}.lang-field textarea{min-height:78px;resize:vertical}.lang-checks{display:flex;gap:12px;flex-wrap:wrap;margin:5px 0 13px;font-size:.8rem;color:var(--text2)}.lang-checks label{display:flex;align-items:center;gap:5px}.lang-grid>*{min-width:0}.lang-table-wrap{position:relative;width:100%;max-width:100%;min-width:0;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;touch-action:pan-x;scrollbar-width:thin;scrollbar-color:var(--border2) transparent}.lang-table-wrap::-webkit-scrollbar{height:8px}.lang-table-wrap::-webkit-scrollbar-track{background:transparent}.lang-table-wrap::-webkit-scrollbar-thumb{background:var(--border2);border-radius:999px}.lang-scroll-hint{display:none;color:var(--text3);font-size:.7rem;text-align:center;padding:7px 8px;border-bottom:1px solid var(--border);white-space:nowrap}.lang-table{width:100%;border-collapse:collapse;min-width:680px}.lang-table th,.lang-table td{padding:11px 9px;border-bottom:1px solid var(--border);text-align:right;vertical-align:middle;font-size:.8rem}.lang-table th{color:var(--text3);font-size:.72rem;white-space:nowrap}.lang-table td{color:var(--text2)}.lang-table th:last-child,.lang-table td:last-child{min-width:145px}.lang-code{font-family:monospace;color:#76a7ff;font-weight:800}.lang-actions{display:flex;gap:5px;flex-wrap:nowrap;min-width:max-content}.lang-action{display:inline-flex;align-items:center;justify-content:center;gap:5px;border:1px solid var(--border2);background:var(--card2);color:var(--text2);border-radius:7px;padding:6px 8px;text-decoration:none;cursor:pointer;font:inherit;font-size:.72rem;white-space:nowrap}.lang-action.danger{color:#ff8190;border-color:rgba(255,68,85,.3)}.lang-action.success{color:#29d991;border-color:rgba(0,230,118,.3)}.lang-muted{color:var(--text3);font-size:.76rem}.lang-toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:13px}.lang-toolbar form{display:flex;gap:7px;flex-wrap:wrap}.lang-toolbar input,.lang-toolbar select{background:var(--bg2);border:1px solid var(--border2);border-radius:8px;padding:8px 10px;color:var(--text);font:inherit;font-size:.8rem}.lang-badge{display:inline-flex;padding:4px 7px;border-radius:999px;font-size:.68rem;font-weight:800}.lang-badge.on{background:rgba(0,230,118,.12);color:#29d991}.lang-badge.off{background:rgba(255,68,85,.12);color:#ff8190}.lang-pagination{display:flex;gap:6px;justify-content:center;margin-top:15px;flex-wrap:wrap}.lang-pagination a{padding:6px 9px;border:1px solid var(--border);border-radius:7px;color:var(--text2);text-decoration:none;font-size:.75rem}.lang-pagination a.active{background:#1769ff;color:#fff;border-color:#1769ff}.lang-note{background:rgba(23,105,255,.08);border:1px solid rgba(23,105,255,.24);border-radius:11px;padding:11px 13px;color:var(--text2);font-size:.78rem;line-height:1.7;margin-bottom:15px}.lang-toolbar-actions{display:flex;gap:8px;flex-wrap:wrap}.lang-small{font-size:.72rem;color:var(--text3)}@media(max-width:900px){.lang-grid{grid-template-columns:1fr}.lang-table-wrap{margin-inline:0;border:1px solid var(--border);border-radius:10px;background:rgba(0,0,0,.08)}.lang-scroll-hint{display:block}.lang-table{min-width:760px}}
</style>
<div class="page-header"><div class="page-header-left"><div class="page-header-title"><div class="page-header-title-icon" style="background:rgba(23,105,255,.15);color:#76a7ff"><i class="fas fa-language"></i></div>لغات العرض</div><div class="page-header-subtitle">مصدر موحد للموقع وبوت Telegram مع مزامنة فورية من قاعدة البيانات</div></div><div class="page-header-actions lang-toolbar-actions"><a class="btn btn-secondary" href="<?=langAdminH(SITE_URL)?>/admin/languages.php?export=json"><i class="fas fa-file-export"></i> تصدير JSON</a><button class="btn btn-primary" type="button" onclick="document.getElementById('importModal').style.display='flex'"><i class="fas fa-file-import"></i> استيراد JSON</button></div></div>
<?php $flash=getFlash(); if($flash): ?><div class="alert alert-<?=langAdminH($flash['type'])?>" style="margin-bottom:16px"><?=langAdminH($flash['message'])?></div><?php endif; ?>
<div class="lang-note"><strong><i class="fas fa-sync-alt"></i> كيف يعمل المصدر الموحد؟</strong><br>تُحفظ اللغات والترجمات في قاعدة بيانات نجاز. يقرأها البوت عند كل قائمة أو رسالة جديدة، ويحمل الموقع نسخة المفعّل منها عند فتح الصفحة. يبقى <code>assets/js/i18n.js</code> احتياطياً ويمكن مزامنته من زر المزامنة.</div>
<div class="lang-admin-tabs"><a class="lang-admin-tab <?=$tab==='languages'?'active':''?>" href="languages.php?tab=languages"><i class="fas fa-globe"></i> اللغات <span class="lang-small">(<?=count($languages)?>)</span></a><a class="lang-admin-tab <?=$tab==='translations'?'active':''?>" href="languages.php?tab=translations"><i class="fas fa-spell-check"></i> الترجمات <span class="lang-small">(<?=$total?>)</span></a></div>

<?php if($tab==='languages'): ?>
<div class="lang-grid">
  <div class="lang-card">
    <h3><i class="fas fa-<?=$editLanguage?'edit':'plus-circle'?>" style="color:#76a7ff"></i><?=$editLanguage?'تعديل لغة':'إضافة لغة جديدة'?></h3>
    <form method="post">
      <?=adminCsrfField()?> <input type="hidden" name="tab" value="languages"><input type="hidden" name="id" value="<?=langAdminH($editLanguage['id']??0)?>">
      <div class="lang-form-grid"><div class="lang-field"><label>كود اللغة</label><input name="language_code" maxlength="20" pattern="[a-z][a-z0-9_-]{1,19}" value="<?=langAdminH($editLanguage['language_code']??'')?>" placeholder="es" required></div><div class="lang-field"><label>الاتجاه</label><select name="direction"><option value="ltr" <?=($editLanguage['direction']??'ltr')==='ltr'?'selected':''?>>يسار إلى يمين</option><option value="rtl" <?=($editLanguage['direction']??'')==='rtl'?'selected':''?>>يمين إلى يسار</option></select></div><div class="lang-field"><label>الاسم الإداري</label><input name="language_name" value="<?=langAdminH($editLanguage['language_name']??'')?>" placeholder="Spanish" required></div><div class="lang-field"><label>الاسم المحلي</label><input name="language_name_native" value="<?=langAdminH($editLanguage['language_name_native']??'')?>" placeholder="Español" required></div><div class="lang-field"><label>العلم / الرمز</label><input name="flag_emoji" maxlength="20" value="<?=langAdminH($editLanguage['flag_emoji']??'')?>" placeholder="🇪🇸"></div><div class="lang-field"><label>الترتيب</label><input type="number" name="sort_order" value="<?=langAdminH($editLanguage['sort_order']??count($languages))?>" min="0"></div></div>
      <div class="lang-checks"><label><input type="checkbox" name="status" value="1" <?=(!$editLanguage || (int)$editLanguage['status']===1)?'checked':''?>> مفعلة للموقع والبوت</label><label><input type="checkbox" name="is_default" value="1" <?=($editLanguage && (int)$editLanguage['is_default']===1)?'checked':''?>> افتراضية</label></div>
      <button class="btn btn-primary" type="submit" name="save_language"><i class="fas fa-save"></i> حفظ اللغة</button><?php if($editLanguage): ?> <a class="btn btn-secondary" href="languages.php?tab=languages">إلغاء</a><?php endif; ?>
    </form>
    <hr style="border:0;border-top:1px solid var(--border);margin:18px 0"><form method="post"><?=adminCsrfField()?><input type="hidden" name="tab" value="languages"><label class="lang-checks"><input type="checkbox" name="replace_existing" value="1"> استبدال الترجمات المعدلة عند المزامنة</label>      <button class="btn btn-secondary" type="submit" name="sync_js"><i class="fas fa-sync-alt"></i> مزامنة من i18n.js</button></form>
    <form method="post" style="margin-top:9px"><?=adminCsrfField()?><input type="hidden" name="tab" value="languages"><button class="btn btn-secondary" type="submit" name="sync_dynamic"><i class="fas fa-boxes"></i> مزامنة الأقسام والخدمات والحقول</button></form>
  </div>
  <div class="lang-card"><div class="lang-toolbar"><h3><i class="fas fa-list" style="color:#00d4aa"></i> اللغات المتاحة</h3><span class="lang-muted"><?=count($activeLanguages)?> مفعلة من <?=count($languages)?></span></div><div class="lang-table-wrap"><div class="lang-scroll-hint"><i class="fas fa-arrows-left-right"></i> اسحب الجدول يميناً أو يساراً لإظهار أزرار التعديل والإجراءات</div><table class="lang-table"><thead><tr><th>اللغة</th><th>الكود</th><th>الاتجاه</th><th>الحالة</th><th>الافتراضية</th><th>الترتيب</th><th>الإجراءات</th></tr></thead><tbody><?php foreach($languages as $language): ?><tr><td><strong><?=langAdminH($language['flag_emoji'])?> <?=langAdminH($language['language_name_native'])?></strong><div class="lang-muted"><?=langAdminH($language['language_name'])?></div></td><td><span class="lang-code"><?=langAdminH($language['language_code'])?></span></td><td><?=($language['direction']==='rtl'?'RTL':'LTR')?></td><td><span class="lang-badge <?=$language['status']?'on':'off'?>"><?=$language['status']?'مفعلة':'متوقفة'?></span></td><td><?=$language['is_default']?'<span class="lang-badge on">افتراضية</span>':'—'?></td><td><?=langAdminH($language['sort_order'])?></td><td><div class="lang-actions"><a class="lang-action" href="languages.php?tab=languages&edit_language=<?=$language['id']?>"><i class="fas fa-edit"></i></a><form method="post" style="display:inline"><?=adminCsrfField()?><input type="hidden" name="tab" value="languages"><input type="hidden" name="id" value="<?=$language['id']?>"><button class="lang-action <?=$language['status']?'danger':'success'?>" name="toggle_language" title="تفعيل/إيقاف"><i class="fas fa-power-off"></i></button></form><?php if(!$language['is_default']): ?><form method="post" style="display:inline"><?=adminCsrfField()?><input type="hidden" name="tab" value="languages"><input type="hidden" name="id" value="<?=$language['id']?>"><button class="lang-action" name="set_default">افتراضي</button></form><form method="post" style="display:inline" onsubmit="return confirm('حذف اللغة وترجماتها؟')"><?=adminCsrfField()?><input type="hidden" name="tab" value="languages"><input type="hidden" name="id" value="<?=$language['id']?>"><button class="lang-action danger" name="delete_language"><i class="fas fa-trash"></i></button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php else: ?>
<div class="lang-card"><div class="lang-toolbar"><form method="get"><input type="hidden" name="tab" value="translations"><select name="language"><option value="">كل اللغات</option><?php foreach($languages as $language): ?><option value="<?=langAdminH($language['language_code'])?>" <?=$filterLanguage===$language['language_code']?'selected':''?>><?=langAdminH($language['flag_emoji'])?> <?=langAdminH($language['language_name_native'])?> (<?=langAdminH($language['language_code'])?>)</option><?php endforeach; ?></select><input name="q" value="<?=langAdminH($search)?>" placeholder="ابحث في الأصل أو الترجمة أو السياق"><label class="lang-small" style="display:inline-flex;align-items:center;gap:5px"><input type="checkbox" name="all" value="1" <?=$showAllRows?'checked':''?>> عرض غير المترجم</label><button class="btn btn-secondary" type="submit"><i class="fas fa-search"></i> بحث</button><?php if($search!==''||$filterLanguage!==''||$showAllRows): ?><a class="btn btn-secondary" href="languages.php?tab=translations">مسح</a><?php endif; ?></form><div class="lang-toolbar-actions"><a class="btn btn-primary" href="languages.php?tab=translations"><i class="fas fa-plus"></i> ترجمة جديدة</a></div></div><div class="lang-grid" style="grid-template-columns:minmax(280px,360px) 1fr"><div class="lang-card" style="background:var(--card2)"><h3><i class="fas fa-<?=$editTranslation?'edit':'plus-circle'?>" style="color:#76a7ff"></i><?=$editTranslation?'تعديل ترجمة':'إضافة ترجمة'?></h3><form method="post"><?=adminCsrfField()?><input type="hidden" name="tab" value="translations"><input type="hidden" name="id" value="<?=langAdminH($editTranslation['id']??0)?>"><div class="lang-field"><label>لغة الترجمة</label><select name="language_code" required><?php foreach($languages as $language): ?><option value="<?=langAdminH($language['language_code'])?>" <?=($editTranslation['language_code']??($filterLanguage?:'en'))===$language['language_code']?'selected':''?>><?=langAdminH($language['flag_emoji'])?> <?=langAdminH($language['language_name_native'])?> (<?=langAdminH($language['language_code'])?>)</option><?php endforeach; ?></select></div><div class="lang-field"><label>النص الأصلي / المفتاح العربي</label><textarea name="source_text" required><?=langAdminH($editTranslation['source_text']??'')?></textarea></div><div class="lang-field"><label>الترجمة أو المرادف</label><textarea name="translated_text" required><?=langAdminH($editTranslation['translated_text']??'')?></textarea></div><div class="lang-field"><label>السياق الاختياري</label><input name="context" value="<?=langAdminH($editTranslation['context']??'')?>" placeholder="entity:service:12:name أو menu:orders"><div class="lang-small">لترجمة عنصر ديناميكي استخدم السياق الظاهر في الجدول، مثل <code>entity:category:3:name</code> أو <code>entity:field:8:option:0</code>.</div></div><div class="lang-checks"><label><input type="checkbox" name="status" value="1" <?=(!$editTranslation || (int)$editTranslation['status']===1)?'checked':''?>> مفعلة</label></div><button class="btn btn-primary" type="submit" name="save_translation"><i class="fas fa-save"></i> حفظ الترجمة</button><?php if($editTranslation): ?> <a class="btn btn-secondary" href="languages.php?tab=translations">إلغاء</a><?php endif; ?></form></div><div class="lang-card" style="padding:0;background:transparent;border:0"><div class="lang-table-wrap"><div class="lang-scroll-hint"><i class="fas fa-arrows-left-right"></i> اسحب الجدول يميناً أو يساراً لإظهار أزرار التعديل والإجراءات</div><table class="lang-table"><thead><tr><th>اللغة</th><th>النص الأصلي</th><th>الترجمة / المرادف</th><th>السياق</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody><?php if(!$translationRows): ?><tr><td colspan="6" style="text-align:center;padding:35px">لا توجد ترجمات مطابقة. استخدم المزامنة أو أضف ترجمة جديدة.</td></tr><?php endif; ?><?php foreach($translationRows as $translation): $lang=$languageByCode[$translation['language_code']]??null; ?><tr><td><span class="lang-code"><?=langAdminH($translation['language_code'])?></span><div class="lang-muted"><?=langAdminH($lang['language_name_native']??'')?></div></td><td style="max-width:220px"><div style="white-space:pre-wrap;word-break:break-word"><?=langAdminH($translation['source_text'])?></div></td><td style="max-width:220px"><div style="white-space:pre-wrap;word-break:break-word;color:var(--text)"><?=langAdminH($translation['translated_text'])?></div></td><td><?=langAdminH($translation['context']?:'—')?></td><td><span class="lang-badge <?=$translation['status']?'on':'off'?>"><?=$translation['status']?'مفعلة':'متوقفة'?></span></td><td><div class="lang-actions"><a class="lang-action" href="languages.php?tab=translations&edit_translation=<?=$translation['id']?>&language=<?=rawurlencode($filterLanguage)?>&q=<?=rawurlencode($search)?>"><i class="fas fa-edit"></i></a><form method="post" style="display:inline"><?=adminCsrfField()?><input type="hidden" name="tab" value="translations"><input type="hidden" name="id" value="<?=$translation['id']?>"><button class="lang-action <?=$translation['status']?'danger':'success'?>" name="toggle_translation"><i class="fas fa-power-off"></i></button></form><form method="post" style="display:inline" onsubmit="return confirm('حذف الترجمة؟')"><?=adminCsrfField()?><input type="hidden" name="tab" value="translations"><input type="hidden" name="id" value="<?=$translation['id']?>"><button class="lang-action danger" name="delete_translation"><i class="fas fa-trash"></i></button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php $windowStart=max(1,$page-2); $windowEnd=min($pages,$page+2); $pageQuery='&language='.rawurlencode($filterLanguage).'&q='.rawurlencode($search).($showAllRows?'&all=1':''); ?><div class="lang-pagination"><?php if($page>1): ?><a href="languages.php?tab=translations<?=$pageQuery?>&page=<?=$page-1?>">السابق</a><?php endif; ?><?php if($windowStart>1): ?><a href="languages.php?tab=translations<?=$pageQuery?>&page=1">1</a><?php if($windowStart>2): ?><span class="lang-small" style="padding:7px 2px">…</span><?php endif; ?><?php endif; ?><?php for($p=$windowStart;$p<=$windowEnd;$p++): ?><a class="<?=$p===$page?'active':''?>" href="languages.php?tab=translations<?=$pageQuery?>&page=<?=$p?>"><?=$p?></a><?php endfor; ?><?php if($windowEnd<$pages): ?><?php if($windowEnd<$pages-1): ?><span class="lang-small" style="padding:7px 2px">…</span><?php endif; ?><a href="languages.php?tab=translations<?=$pageQuery?>&page=<?=$pages?>"><?=$pages?></a><?php endif; ?><?php if($page<$pages): ?><a href="languages.php?tab=translations<?=$pageQuery?>&page=<?=$page+1?>">التالي</a><?php endif; ?></div></div></div></div>
<?php endif; ?>

<div id="importModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;align-items:center;justify-content:center;padding:16px"><div class="lang-card" style="width:min(760px,100%);max-height:90vh;overflow:auto"><div class="lang-toolbar"><h3><i class="fas fa-file-import" style="color:#76a7ff"></i> استيراد JSON</h3><button type="button" class="lang-action" onclick="document.getElementById('importModal').style.display='none'">✕</button></div><p class="lang-muted">الصيغة المدعومة هي ملف التصدير الناتج من هذه الصفحة، أو كائن <code>translations</code> يحتوي على خرائط اللغة.</p><form method="post"><?=adminCsrfField()?><input type="hidden" name="tab" value="languages"><textarea name="json_payload" style="width:100%;min-height:280px;box-sizing:border-box;background:var(--bg2);border:1px solid var(--border2);border-radius:10px;padding:12px;color:var(--text);font-family:monospace;font-size:.78rem" placeholder='{"languages":[],"translations":{}}' required></textarea><div style="margin-top:12px"><button class="btn btn-primary" type="submit" name="import_json"><i class="fas fa-upload"></i> استيراد وحفظ</button><button type="button" class="btn btn-secondary" onclick="document.getElementById('importModal').style.display='none'">إلغاء</button></div></form></div></div>
<script>window.addEventListener('click',function(e){var m=document.getElementById('importModal');if(e.target===m)m.style.display='none';});</script>
