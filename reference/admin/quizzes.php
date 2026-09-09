<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'المسابقات — ' . SITE_NAME;

// إنشاء الجداول
foreach ([
"CREATE TABLE IF NOT EXISTS `quizzes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `title` VARCHAR(300) NOT NULL,
  `prize_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `time_seconds` INT NOT NULL DEFAULT 60,
  `winners_count` INT NOT NULL DEFAULT 1,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS `quiz_questions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `quiz_id` INT NOT NULL,
  `sort_order` INT DEFAULT 0,
  `question` TEXT NOT NULL,
  `option_a` VARCHAR(500) NOT NULL,
  `option_b` VARCHAR(500) NOT NULL,
  `option_c` VARCHAR(500) DEFAULT NULL,
  `option_d` VARCHAR(500) DEFAULT NULL,
  `correct` ENUM('a','b','c','d') NOT NULL DEFAULT 'a',
  KEY `idx_quiz` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS `quiz_entries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `quiz_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `answers` TEXT NOT NULL,
  `correct_count` INT DEFAULT 0,
  `total_questions` INT DEFAULT 0,
  `time_taken` INT DEFAULT 0,
  `is_winner` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_entry` (`quiz_id`,`user_id`),
  KEY `idx_quiz` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
] as $sql) { try { $pdo->exec($sql); } catch(Exception $e) {} }

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── حذف ──────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM quiz_questions WHERE quiz_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM quiz_entries WHERE quiz_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM quizzes WHERE id=?")->execute([$id]);
    flashMessage('success','تم الحذف');
    redirect(SITE_URL.'/admin/quizzes.php');
}

// ── تبديل الحالة السريع ───────────────────────────────────────
if ($action === 'setstatus' && $id) {
    $s = (int)($_GET['s'] ?? 0);
    $pdo->prepare("UPDATE quizzes SET status=? WHERE id=?")->execute([$s,$id]);
    redirect(SITE_URL.'/admin/quizzes.php');
}

// ── اختيار الفائزين ───────────────────────────────────────────
if ($action === 'pick_winners' && $id) {
    $quiz = $pdo->prepare("SELECT * FROM quizzes WHERE id=?"); $quiz->execute([$id]); $quiz=$quiz->fetch();
    if ($quiz) {
        $wCount = (int)$quiz['winners_count'];
        // المتسابقون مرتبون حسب الإجابات الصحيحة ثم الوقت
        $entries = $pdo->query("SELECT qe.*, u.full_name, u.username FROM quiz_entries qe JOIN users u ON qe.user_id=u.id WHERE qe.quiz_id=$id ORDER BY qe.correct_count DESC, qe.time_taken ASC LIMIT $wCount")->fetchAll();
        $totalEntries = (int)$pdo->query("SELECT COUNT(*) FROM quiz_entries WHERE quiz_id=$id")->fetchColumn();
        $totalCorrect = (int)$pdo->query("SELECT COUNT(*) FROM quiz_entries WHERE quiz_id=$id AND correct_count=(SELECT total_questions FROM quiz_entries WHERE quiz_id=$id LIMIT 1)")->fetchColumn();
        
        // تعيين الفائزين + إضافة الجائزة للرصيد
        $pdo->prepare("UPDATE quiz_entries SET is_winner=0 WHERE quiz_id=?")->execute([$id]);
        $prizePerWinner = $wCount > 0 ? round($quiz['prize_amount'] / $wCount, 4) : 0;
        $winnerNames = [];
        foreach ($entries as $e) {
            $pdo->prepare("UPDATE quiz_entries SET is_winner=1 WHERE id=?")->execute([$e['id']]);
            $winnerNames[] = $e['full_name'] ?: $e['username'];

            // إضافة الجائزة للرصيد
            if ($prizePerWinner > 0) {
                // جلب الرصيد الحالي
                $ub = $pdo->prepare("SELECT balance FROM users WHERE id=?");
                $ub->execute([$e['user_id']]); $ub=$ub->fetch();
                $balBefore = (float)($ub['balance'] ?? 0);
                $balAfter  = $balBefore + $prizePerWinner;

                // تحديث الرصيد
                $pdo->prepare("UPDATE users SET balance=? WHERE id=?")
                    ->execute([$balAfter, $e['user_id']]);

                // تسجيل المعاملة
                $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
                    ->execute([
                        $e['user_id'],
                        'prize',
                        $prizePerWinner,
                        $balBefore,
                        $balAfter,
                        "🏆 جائزة مسابقة: {$quiz['name']}",
                        $quiz['id']
                    ]);

                // إشعار شخصي للفائز
                if (function_exists('sendNotification')) {
                    sendNotification($pdo, $e['user_id'], 'admin',
                        "🏆 مبروك! فزت في مسابقة {$quiz['name']}",
                        "تم إضافة " . number_format($prizePerWinner,2) . "$ لرصيدك. الرصيد الحالي: " . number_format($balAfter,2) . "$",
                        'trophy', '#f5a623'
                    );
                }
            }
        }
        
        // إغلاق المسابقة
        $pdo->prepare("UPDATE quizzes SET status=2 WHERE id=?")->execute([$id]);
        
        // إشعار منبثق + مباشر لكل العملاء
        $msg = "🏆 انتهت مسابقة \"{$quiz['name']}\"!\nإجمالي المشاركين: {$totalEntries}\nعدد الإجابات الصحيحة: {$totalCorrect}\nالفائزون: " . implode('، ', $winnerNames);
        $popup_title = "🎉 نتائج مسابقة {$quiz['name']}";
        $popup_msg   = "الفائزون: " . implode('، ', $winnerNames) . "\nتهانينا للفائزين! 🎊";
        
        if (function_exists('sendNotification')) {
            $users = $pdo->query("SELECT id FROM users WHERE role='customer' AND status=1")->fetchAll();
            foreach ($users as $u) {
                sendNotification($pdo,$u['id'],'admin',$popup_title,$popup_msg,'trophy','#f5a623');
            }
        }
        // إشعار منبثق
        try {
            $pdo->prepare("INSERT INTO notification_broadcasts (title,message,icon,color,btn_label,target,show_once,created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$popup_title,$popup_msg,'trophy','#f5a623','رائع! 🎉','all',1,$_SESSION['user_id']]);
        } catch(Exception $e) {}
        
        flashMessage('success',"✅ تم اختيار ".count($winnerNames)." فائز من أصل {$totalEntries} مشارك. المسابقة أُغلقت.");
        redirect(SITE_URL.'/admin/quizzes.php?action=entries&id='.$id);
    }
}

// ── حفظ المسابقة ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_quiz'])) {
    $name     = trim($_POST['name']     ?? '');
    $title    = trim($_POST['title']    ?? '');
    $prize    = (float)($_POST['prize_amount'] ?? 0);
    $secs     = (int)($_POST['time_seconds'] ?? 60);
    $winners  = (int)($_POST['winners_count'] ?? 1);
    $status   = (int)($_POST['status'] ?? 0);
    $notify   = isset($_POST['notify']) ? 1 : 0;
    $recalc   = isset($_POST['recalc']) ? 1 : 0;
    $editId   = (int)($_POST['edit_id'] ?? 0);

    if (!$name || !$title) { flashMessage('danger','الاسم والعنوان مطلوبان'); redirect(SITE_URL.'/admin/quizzes.php?action='.($editId?'edit&id='.$editId:'add')); }

    if ($editId) {
        $pdo->prepare("UPDATE quizzes SET name=?,title=?,prize_amount=?,time_seconds=?,winners_count=?,status=? WHERE id=?")
            ->execute([$name,$title,$prize,$secs,$winners,$status,$editId]);
        $qid = $editId;
    } else {
        $pdo->prepare("INSERT INTO quizzes (name,title,prize_amount,time_seconds,winners_count,status,created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$name,$title,$prize,$secs,$winners,$status,$_SESSION['user_id']]);
        $qid = $pdo->lastInsertId();
    }

    // حفظ الأسئلة
    if ($editId) $pdo->prepare("DELETE FROM quiz_questions WHERE quiz_id=?")->execute([$editId]);
    $questions = $_POST['questions'] ?? [];
    $qOrder = 0;
    foreach ($questions as $q) {
        $qtext = trim($q['question'] ?? '');
        $oa    = trim($q['option_a'] ?? '');
        $ob    = trim($q['option_b'] ?? '');
        $oc    = trim($q['option_c'] ?? '');
        $od    = trim($q['option_d'] ?? '');
        $cor   = $q['correct'] ?? 'a';
        if (!$qtext || !$oa || !$ob) continue;
        $pdo->prepare("INSERT INTO quiz_questions (quiz_id,sort_order,question,option_a,option_b,option_c,option_d,correct) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$qid,$qOrder++,$qtext,$oa,$ob,$oc?:null,$od?:null,$cor]);
    }

    // إعادة احتساب النتائج
    if ($recalc && $editId) {
        $allQuestions = $pdo->query("SELECT * FROM quiz_questions WHERE quiz_id=$qid")->fetchAll();
        $allEntries   = $pdo->query("SELECT * FROM quiz_entries WHERE quiz_id=$qid")->fetchAll();
        foreach ($allEntries as $entry) {
            $answers = json_decode($entry['answers'], true) ?? [];
            $correct = 0;
            foreach ($allQuestions as $q) {
                if (isset($answers[$q['id']]) && $answers[$q['id']] === $q['correct']) $correct++;
            }
            $pdo->prepare("UPDATE quiz_entries SET correct_count=?,total_questions=? WHERE id=?")->execute([$correct,count($allQuestions),$entry['id']]);
        }
    }

    // إشعار للعملاء
    if ($notify) {
        $notifTitle = "🎯 مسابقة جديدة: {$name}";
        $notifMsg   = "{$title}\n🏆 الجائزة: {$prize} $\n⏱ الوقت: {$secs} ثانية";
        try {
            $pdo->prepare("INSERT INTO notification_broadcasts (title,message,icon,color,btn_label,target,show_once,created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$notifTitle,$notifMsg,'trophy','#f5a623','شارك الآن!','all',1,$_SESSION['user_id']]);
        } catch(Exception $e) {}
        if (function_exists('sendNotification')) {
            $users = $pdo->query("SELECT id FROM users WHERE role='customer' AND status=1")->fetchAll();
            foreach ($users as $u) sendNotification($pdo,$u['id'],'admin',$notifTitle,$notifMsg,'trophy','#f5a623');
        }
    }

    flashMessage('success','✅ تم حفظ المسابقة');
    redirect(SITE_URL.'/admin/quizzes.php');
}

// ── جلب البيانات ──────────────────────────────────────────────
$editQuiz = null; $editQuestions = [];
if (($action==='edit'||$action==='add') && $id) {
    $eq=$pdo->prepare("SELECT * FROM quizzes WHERE id=?");$eq->execute([$id]);$editQuiz=$eq->fetch();
    $editQuestions=$pdo->query("SELECT * FROM quiz_questions WHERE quiz_id=$id ORDER BY sort_order")->fetchAll();
}

$quizList = $pdo->query("SELECT q.*, (SELECT COUNT(*) FROM quiz_entries WHERE quiz_id=q.id) as entries_count FROM quizzes q ORDER BY q.created_at DESC")->fetchAll();

include 'header.php';
?>
<style>
.quiz-status-0{background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3)}
.quiz-status-1{background:rgba(0,230,118,.15);color:#00e676;border:1px solid rgba(0,230,118,.3)}
.quiz-status-2{background:rgba(136,149,167,.15);color:#8895a7;border:1px solid rgba(136,149,167,.3)}
.q-card{background:var(--bg2);border:1.5px solid var(--border);border-radius:12px;padding:14px;margin-bottom:10px}
.q-card-header{display:flex;align-items:center;gap:8px;margin-bottom:10px;font-weight:700}
.q-num{width:28px;height:28px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:900;flex-shrink:0}
.correct-opt{display:flex;align-items:center;gap:6px;padding:5px 10px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;font-size:.83rem;transition:.15s}
.correct-opt input{display:none}
.correct-opt.selected{border-color:var(--green);background:rgba(0,230,118,.1);color:var(--green)}
.opt-label{display:inline-block;width:22px;height:22px;border-radius:50%;background:var(--border);color:var(--text);font-size:.7rem;font-weight:900;text-align:center;line-height:22px;flex-shrink:0}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(245,166,35,.15);color:#f5a623"><i class="fas fa-trophy"></i></div>
      المسابقات
    </div>
  </div>
  <?php if($action==='list'): ?>
  <a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> إضافة مسابقة</a>
  <?php endif; ?>
</div>

<?php if ($action==='list'): ?>
<!-- ══ قائمة المسابقات ══ -->
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> المسابقات (<?= count($quizList) ?>)</div></div>
  <?php if(empty($quizList)): ?>
  <div class="card-body" style="text-align:center;padding:3rem;color:var(--text2)">
    <i class="fas fa-trophy" style="font-size:3rem;opacity:.1;display:block;margin-bottom:12px"></i>
    لا توجد مسابقات بعد
    <br><a href="?action=add" class="btn btn-primary" style="margin-top:16px"><i class="fas fa-plus"></i> أضف أول مسابقة</a>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>الاسم</th><th>الجائزة</th><th>الوقت</th><th>المشاركون</th><th>الحالة</th><th>إجراءات</th></tr></thead>
      <tbody>
      <?php foreach($quizList as $q): ?>
      <tr>
        <td><?= $q['id'] ?></td>
        <td>
          <strong><?= htmlspecialchars($q['name']) ?></strong>
          <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($q['title']) ?></div>
        </td>
        <td style="color:#f5a623;font-weight:700"><?= number_format($q['prize_amount'],2) ?> $</td>
        <td><?= $q['time_seconds'] ?>ث</td>
        <td>
          <a href="?action=entries&id=<?= $q['id'] ?>" style="color:var(--cyan);font-weight:700;text-decoration:none">
            <?= $q['entries_count'] ?> <i class="fas fa-users" style="font-size:.75rem"></i>
          </a>
        </td>
        <td>
          <span class="badge quiz-status-<?= $q['status'] ?>">
            <?= ['انتظار','نشطة ✓','مغلقة'][($q['status'])] ?>
          </span>
        </td>
        <td>
          <div style="display:flex;gap:5px;flex-wrap:wrap">
            <!-- تغيير الحالة -->
            <?php if($q['status']===0): ?>
            <a href="?action=setstatus&id=<?= $q['id'] ?>&s=1" class="btn btn-sm btn-success" title="تفعيل"><i class="fas fa-play"></i></a>
            <?php elseif($q['status']===1): ?>
            <a href="?action=setstatus&id=<?= $q['id'] ?>&s=2" class="btn btn-sm btn-secondary" title="إغلاق"><i class="fas fa-stop"></i></a>
            <?php endif; ?>
            <!-- اختيار فائزين -->
            <?php if($q['entries_count']>0): ?>
            <a href="?action=pick_winners&id=<?= $q['id'] ?>" class="btn btn-sm" style="background:rgba(245,166,35,.2);color:#f5a623;border:1px solid rgba(245,166,35,.4)" onclick="return confirm('اختيار الفائزين وإغلاق المسابقة؟')" title="اختيار فائزين">
              <i class="fas fa-award"></i>
            </a>
            <?php endif; ?>
            <a href="?action=edit&id=<?= $q['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
            <a href="?action=entries&id=<?= $q['id'] ?>" class="btn btn-sm btn-primary" title="المشاركات"><i class="fas fa-users"></i></a>
            <a href="?action=delete&id=<?= $q['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف هذه المسابقة؟')"><i class="fas fa-trash"></i></a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif($action==='entries'): ?>
<!-- ══ مشاركات المتسابقين ══ -->
<?php
$quiz = $pdo->prepare("SELECT * FROM quizzes WHERE id=?"); $quiz->execute([$id]); $quiz=$quiz->fetch();
$entries = $pdo->query("SELECT qe.*, u.full_name, u.username, u.email FROM quiz_entries qe JOIN users u ON qe.user_id=u.id WHERE qe.quiz_id=$id ORDER BY qe.correct_count DESC, qe.time_taken ASC")->fetchAll();
$totalQ = (int)$pdo->query("SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=$id")->fetchColumn();
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
  <div>
    <h3 style="margin:0;font-size:1rem;font-weight:900">مشاركات: <?= htmlspecialchars($quiz['name']??'') ?></h3>
    <div style="font-size:12px;color:var(--text3)"><?= count($entries) ?> مشارك — جائزة: <?= $quiz['prize_amount'] ?> $</div>
  </div>
  <div style="display:flex;gap:8px">
    <?php if(count($entries)>0 && $quiz['status']!=2): ?>
    <a href="?action=pick_winners&id=<?= $id ?>" class="btn btn-warning" onclick="return confirm('اختيار الفائزين الآن؟')">
      <i class="fas fa-award"></i> اختيار الفائزين تلقائياً
    </a>
    <?php endif; ?>
    <a href="?" class="btn btn-secondary"><i class="fas fa-arrow-right"></i> رجوع</a>
  </div>
</div>
<div class="card">
  <?php if(empty($entries)): ?>
  <div class="card-body" style="text-align:center;padding:2rem;color:var(--text3)">لا توجد مشاركات بعد</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>المتسابق</th><th>الإجابات الصحيحة</th><th>الوقت</th><th>الحالة</th><th>تاريخ</th></tr></thead>
      <tbody>
      <?php foreach($entries as $i=>$e): ?>
      <tr style="<?= $e['is_winner']?'background:rgba(245,166,35,.08)':'' ?>">
        <td><?= $i+1 ?></td>
        <td>
          <strong><?= htmlspecialchars($e['full_name']?:$e['username']) ?></strong>
          <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($e['email']) ?></div>
        </td>
        <td>
          <span style="font-size:1rem;font-weight:900;color:<?= $e['correct_count']==$totalQ?'#00e676':'var(--text)' ?>">
            <?= $e['correct_count'] ?> / <?= $totalQ ?>
          </span>
        </td>
        <td><?= $e['time_taken'] ?>ث</td>
        <td>
          <?php if($e['is_winner']): ?>
          <span class="badge" style="background:rgba(245,166,35,.2);color:#f5a623;border:1px solid rgba(245,166,35,.4)">🏆 فائز</span>
          <?php else: ?>
          <span class="badge" style="background:var(--bg2);color:var(--text3)">مشارك</span>
          <?php endif; ?>
        </td>
        <td style="font-size:11px;color:var(--text3)"><?= date('d/m H:i',strtotime($e['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif($action==='add'||$action==='edit'): ?>
<!-- ══ فورم إضافة/تعديل ══ -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-<?= $editQuiz?'edit':'plus-circle' ?>"></i> <?= $editQuiz?'تعديل مسابقة':'إضافة مسابقة جديدة' ?></div>
    <a href="?" class="btn btn-secondary btn-sm"><i class="fas fa-list"></i> القائمة</a>
  </div>
  <div class="card-body">
    <form method="POST" id="quizForm">
      <input type="hidden" name="save_quiz" value="1">
      <input type="hidden" name="edit_id" value="<?= $editQuiz['id']??0 ?>">

      <div style="background:rgba(30,111,255,.06);border:1px solid rgba(30,111,255,.2);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:var(--text2);line-height:2">
        <strong style="color:var(--primary)"><i class="fas fa-info-circle"></i> تعليمات:</strong><br>
        • يجب إضافة سؤال واحد على الأقل مع خيارين على الأقل<br>
        • يجب اختيار الإجابة الصحيحة لكل سؤال<br>
        • عدد الثواني هو الفترة الزمنية الكاملة للمسابقة<br>
        • الحالة: 0=انتظار الإطلاق، 1=نشطة، 2=مغلقة
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label><i class="fas fa-tag"></i> اسم المسابقة *</label>
          <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editQuiz['name']??'') ?>" placeholder="مثال: مسابقة رمضان" required>
        </div>
        <div class="form-group">
          <label><i class="fas fa-heading"></i> عنوان المسابقة *</label>
          <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($editQuiz['title']??'') ?>" placeholder="مثال: اختبر معلوماتك واربح الجائزة!" required>
        </div>
        <div class="form-group">
          <label><i class="fas fa-dollar-sign" style="color:#f5a623"></i> مبلغ الجائزة ($)</label>
          <input type="number" name="prize_amount" class="form-control" value="<?= $editQuiz['prize_amount']??0 ?>" min="0" step="0.01">
        </div>
        <div class="form-group">
          <label><i class="fas fa-clock"></i> الفترة الزمنية (ثانية)</label>
          <input type="number" name="time_seconds" class="form-control" value="<?= $editQuiz['time_seconds']??60 ?>" min="10" max="3600">
          <div class="form-hint">الدقيقة = 60 ثانية</div>
        </div>
        <div class="form-group">
          <label><i class="fas fa-award" style="color:#f5a623"></i> عدد الفائزين</label>
          <input type="number" name="winners_count" class="form-control" value="<?= $editQuiz['winners_count']??1 ?>" min="1">
        </div>
        <div class="form-group">
          <label><i class="fas fa-toggle-on"></i> الحالة</label>
          <select name="status" class="form-control">
            <option value="0" <?= ($editQuiz['status']??0)==0?'selected':'' ?>>0 — في انتظار الإطلاق</option>
            <option value="1" <?= ($editQuiz['status']??0)==1?'selected':'' ?>>1 — نشطة (مفتوحة للمشاركة)</option>
            <option value="2" <?= ($editQuiz['status']??0)==2?'selected':'' ?>>2 — مغلقة</option>
          </select>
        </div>
      </div>

      <!-- خيارات الإشعار وإعادة الاحتساب -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
        <label style="display:flex;align-items:center;gap:10px;padding:12px;border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:.2s" class="form-check-label">
          <input type="checkbox" name="notify" value="1" style="width:16px;height:16px">
          <div>
            <div style="font-weight:700;font-size:.88rem">📢 إرسال إشعار وإعلان للعملاء</div>
            <div style="font-size:.75rem;color:var(--text3)">سيصل إشعار + popup لجميع العملاء</div>
          </div>
        </label>
        <?php if($editQuiz): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:12px;border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:.2s" class="form-check-label">
          <input type="checkbox" name="recalc" value="1" style="width:16px;height:16px">
          <div>
            <div style="font-weight:700;font-size:.88rem">🔄 إعادة احتساب النتائج</div>
            <div style="font-size:.75rem;color:var(--text3)">بعد تعديل الإجابات الصحيحة</div>
          </div>
        </label>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
      </div>

      <!-- الأسئلة -->
      <div style="margin-bottom:10px;display:flex;align-items:center;justify-content:space-between">
        <h4 style="margin:0;font-size:.95rem;font-weight:900"><i class="fas fa-question-circle" style="color:var(--primary)"></i> الأسئلة</h4>
        <button type="button" onclick="addQuestion()" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> سؤال جديد</button>
      </div>
      <div id="questionsContainer">
      <?php
      $maxQ = max(count($editQuestions), 1);
      for ($qi=0; $qi<$maxQ; $qi++):
        $eq = $editQuestions[$qi] ?? null;
      ?>
      <div class="q-card" id="qcard_<?= $qi ?>">
        <div class="q-card-header">
          <div class="q-num"><?= $qi+1 ?></div>
          السؤال (<?= $qi ?>)
          <button type="button" onclick="removeQ(<?= $qi ?>)" style="margin-right:auto;background:rgba(255,68,85,.1);border:1px solid rgba(255,68,85,.3);color:#ff4455;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:11px">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <div class="form-group">
          <input type="text" name="questions[<?= $qi ?>][question]" class="form-control" value="<?= htmlspecialchars($eq['question']??'') ?>" placeholder="نص السؤال">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
          <?php foreach(['a'=>'أ','b'=>'ب','c'=>'ج','d'=>'د'] as $opt=>$label): ?>
          <div style="display:flex;gap:6px;align-items:center">
            <span style="width:22px;height:22px;border-radius:50%;background:var(--bg3);color:var(--text2);font-size:.75rem;font-weight:900;text-align:center;line-height:22px;flex-shrink:0"><?= $label ?></span>
            <input type="text" name="questions[<?= $qi ?>][option_<?= $opt ?>]" class="form-control" value="<?= htmlspecialchars($eq["option_$opt"]??'') ?>" placeholder="الخيار <?= strtoupper($opt) ?>" style="font-size:.85rem">
          </div>
          <?php endforeach; ?>
        </div>
        <!-- الإجابة الصحيحة -->
        <div>
          <label style="font-size:.75rem;color:var(--text3);margin-bottom:5px;display:block">✅ الإجابة الصحيحة</label>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php foreach(['a'=>'أ','b'=>'ب','c'=>'ج','d'=>'د'] as $opt=>$label): ?>
            <label class="correct-opt <?= ($eq['correct']??'a')===$opt?'selected':'' ?>" id="correct_<?= $qi ?>_<?= $opt ?>">
              <input type="radio" name="questions[<?= $qi ?>][correct]" value="<?= $opt ?>" <?= ($eq['correct']??'a')===$opt?'checked':'' ?> onchange="markCorrect(<?= $qi ?>,'<?= $opt ?>')">
              <span class="opt-label"><?= $label ?></span> الخيار <?= strtoupper($opt) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endfor; ?>
      </div>

      <div style="display:flex;gap:10px;margin-top:16px">
        <button type="submit" class="btn btn-primary btn-lg">
          <i class="fas fa-save"></i> <?= $editQuiz?'حفظ التعديلات':'إنشاء المسابقة' ?>
        </button>
        <a href="?" class="btn btn-secondary">إلغاء</a>
      </div>
    </form>
  </div>
</div>

<script>
let qCount = <?= $maxQ ?>;

function addQuestion() {
  const qi = qCount++;
  const html = `<div class="q-card" id="qcard_${qi}">
    <div class="q-card-header">
      <div class="q-num">${qi+1}</div> السؤال (${qi})
      <button type="button" onclick="removeQ(${qi})" style="margin-right:auto;background:rgba(255,68,85,.1);border:1px solid rgba(255,68,85,.3);color:#ff4455;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:11px"><i class="fas fa-times"></i></button>
    </div>
    <div class="form-group">
      <input type="text" name="questions[${qi}][question]" class="form-control" placeholder="نص السؤال">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
      ${[['a','أ'],['b','ب'],['c','ج'],['d','د']].map(([o,l])=>`
      <div style="display:flex;gap:6px;align-items:center">
        <span style="width:22px;height:22px;border-radius:50%;background:var(--bg3);color:var(--text2);font-size:.75rem;font-weight:900;text-align:center;line-height:22px;flex-shrink:0">${l}</span>
        <input type="text" name="questions[${qi}][option_${o}]" class="form-control" placeholder="الخيار ${o.toUpperCase()}" style="font-size:.85rem">
      </div>`).join('')}
    </div>
    <div>
      <label style="font-size:.75rem;color:var(--text3);margin-bottom:5px;display:block">✅ الإجابة الصحيحة</label>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        ${[['a','أ'],['b','ب'],['c','ج'],['d','د']].map(([o,l])=>`
        <label class="correct-opt ${o==='a'?'selected':''}" id="correct_${qi}_${o}">
          <input type="radio" name="questions[${qi}][correct]" value="${o}" ${o==='a'?'checked':''} onchange="markCorrect(${qi},'${o}')">
          <span class="opt-label">${l}</span> الخيار ${o.toUpperCase()}
        </label>`).join('')}
      </div>
    </div>
  </div>`;
  document.getElementById('questionsContainer').insertAdjacentHTML('beforeend', html);
}

function removeQ(qi) {
  const el = document.getElementById('qcard_'+qi);
  if (el) el.remove();
}

function markCorrect(qi, opt) {
  ['a','b','c','d'].forEach(o => {
    const el = document.getElementById(`correct_${qi}_${o}`);
    if (el) el.classList.toggle('selected', o===opt);
  });
}
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>
