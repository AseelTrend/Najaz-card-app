  </div><!-- /admin-content -->

  <footer class="admin-footer">
    <div>لوحة إدارة <?= htmlspecialchars(getSetting('site_name') ?: SITE_NAME) ?> &copy; <?= date('Y') ?></div>
    <div style="display:flex;align-items:center;gap:12px">
      <span style="color:var(--green)"><i class="fas fa-circle" style="font-size:7px"></i> النظام يعمل</span>
      <span><?= date('Y/m/d H:i') ?></span>
    </div>
  </footer>
</div><!-- /admin-main -->
</div><!-- /admin-shell -->

<!-- Sidebar Overlay — خارج كل شيء على مستوى body -->
<div id="sidebarOverlay" onclick="toggleSidebar()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);
            z-index:199;backdrop-filter:blur(3px);transition:opacity .3s"></div>

<script>

function showToast(msg, type='success') {
  const c = document.getElementById('toastContainer');
  const icons = {success:'check-circle',error:'times-circle',warning:'exclamation-triangle',info:'info-circle'};
  const d = document.createElement('div');
  d.className = `toast-msg toast-${type}`;
  d.innerHTML = `<i class="fas fa-${icons[type]||'check-circle'}"></i> ${msg}`;
  c.appendChild(d);
  setTimeout(() => {
    d.style.animation = 'none'; d.style.opacity='0'; d.style.transform='translateX(-20px)';
    d.style.transition='all .3s'; setTimeout(()=>d.remove(), 300);
  }, 3000);
}

// Auto close sidebar on outside click (desktop)
document.addEventListener('click', function(e) {
  if (window.innerWidth <= 900) return;
});

// Confirm delete buttons
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', function(e) {
    if (!confirm(this.dataset.confirm || 'هل أنت متأكد؟')) e.preventDefault();
  });
});
</script>
</body>
</html>
