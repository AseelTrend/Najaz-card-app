<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
  <!-- ══════════════════════════════════════
       NOTIFICATIONS PAGE
  ══════════════════════════════════════ -->
  <div class="page" id="page-notif">
    <div class="notif-page-wrap">

      <!-- رأس الصفحة -->
      <div class="notif-page-topbar">
        <div class="notif-page-heading">
          <i class="fas fa-bell"></i> إشعاراتي
          <?php if ($unreadNotifCount > 0): ?>
          <span class="notif-unread-count" id="notifPageUnreadBadge"><?= $unreadNotifCount ?></span>
          <?php else: ?>
          <span class="notif-unread-count" id="notifPageUnreadBadge" style="display:none">0</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($myNotifications)): ?>
        <form method="POST" onsubmit="return confirm('حذف جميع الإشعارات؟')">
          <input type="hidden" name="notif_delete_id" value="0">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <button type="submit" class="notif-clear-btn"><i class="fas fa-trash-alt"></i> مسح الكل</button>
        </form>
        <?php endif; ?>
      </div>

      <?php if (!isLoggedIn()): ?>
      <!-- غير مسجل -->
      <div class="auth-gate-box" onclick="openAuth('login')" style="margin-top:20px">
        <div class="auth-gate-icon">🔔</div>
        <div class="auth-gate-title">سجل دخولك لرؤية الإشعارات</div>
        <div class="auth-gate-sub">ستصلك إشعارات طلباتك ومحفظتك</div>
        <div class="auth-gate-btn"><i class="fas fa-sign-in-alt"></i> دخول / تسجيل</div>
      </div>

      <?php elseif (empty($myNotifications)): ?>
      <!-- لا توجد إشعارات -->
      <div class="notif-empty-state">
        <span class="empty-icon">🔕</span>
        <p>لا توجد إشعارات بعد</p>
        <small>ستظهر هنا إشعارات طلباتك وحسابك</small>
      </div>

      <?php else: ?>
      <!-- فلتر -->
      <div class="notif-filter-row">
        <button class="notif-filter-btn active" id="nfAll" onclick="filterNotif('all')">الكل <span style="opacity:.6">(<?= count($myNotifications) ?>)</span></button>
        <button class="notif-filter-btn" id="nfUnread" onclick="filterNotif('unread')">غير مقروء <span style="opacity:.6">(<?= $unreadNotifCount ?>)</span></button>
      </div>

      <!-- قائمة الإشعارات -->
      <div class="notif-list-wrap" id="notifListWrap">
        <?php foreach ($myNotifications as $n):
          $refLink = '';
          if ($n['reference_type'] === 'order' && $n['reference_id'])
              $refLink = SITE_URL . '/orders.php?order=' . $n['reference_id'];
          elseif (in_array($n['reference_type'], ['wallet','topup']))
              $refLink = SITE_URL . '/wallet.php';
          elseif (!empty($n['action_url']))
              $refLink = $n['action_url'];
          $isUnread = !$n['is_read'];
        ?>
        <div class="notif-card <?= $isUnread ? 'unread' : '' ?>"
             data-read="<?= $isUnread ? '0' : '1' ?>"
             onclick="handleNotifCardClick(<?= $n['id'] ?>, '<?= htmlspecialchars(addslashes($refLink)) ?>')"
        >
          <div class="notif-card-icon" style="background:<?= htmlspecialchars($n['color']) ?>22;color:<?= htmlspecialchars($n['color']) ?>">
            <i class="fas fa-<?= htmlspecialchars($n['icon']) ?>"></i>
          </div>
          <div class="notif-card-body">
            <div class="notif-card-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="notif-card-msg"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
            <?php if ($refLink): ?>
            <div class="notif-card-link">
              <i class="fas fa-arrow-left"></i>
              <?php
                if ($n['reference_type'] === 'order') echo 'عرض الطلب #' . $n['reference_id'];
                elseif (in_array($n['reference_type'], ['wallet','topup'])) echo 'عرض المحفظة';
                else echo 'عرض التفاصيل';
              ?>
            </div>
            <?php endif; ?>
            <div class="notif-card-time">
              <i class="fas fa-clock"></i>
              <?= mobileNotifTimeAgo($n['created_at']) ?>
            </div>
          </div>
          <?php if ($isUnread): ?>
          <div class="notif-unread-dot"></div>
          <?php endif; ?>
          <form method="POST" onclick="event.stopPropagation()" style="position:absolute;top:8px;left:8px">
            <input type="hidden" name="notif_delete_id" value="<?= (int)$n['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <button type="submit" class="notif-card-del" title="حذف"><i class="fas fa-times"></i></button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div style="height:20px"></div>
    </div>
