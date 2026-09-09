<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
<script>
// ══════════════════════════════════════════════════
// الطلبات — فلتر + تفاصيل
// ══════════════════════════════════════════════════

// فلتر الحالة
function filterPayments(f, btn) {
  document.querySelectorAll('.pay-card').forEach(c => {
    const filter = c.dataset.filter || '';
    const show = f === 'all' || filter.includes(f);
    c.style.display = show ? '' : 'none';
  });
  document.querySelectorAll('.pay-filter-btn').forEach(b => {
    const on = b.dataset.f === f;
    b.style.background   = on ? 'var(--primary)' : 'var(--card2)';
    b.style.borderColor  = on ? 'var(--primary)' : 'var(--border)';
    b.style.color        = on ? '#fff'            : 'var(--text2)';
  });
}

function filterOrders(status) {
  document.querySelectorAll('.order-card-full').forEach(c => {
    const matches = !status || c.dataset.status === status || (status === 'cancelled' && c.dataset.status === 'failed');
    c.style.display = matches ? '' : 'none';
  });
  document.querySelectorAll('.order-filter-btn').forEach(b => {
    const on = b.dataset.filter === status;
    b.style.background    = on ? 'var(--primary)' : 'var(--card2)';
    b.style.borderColor   = on ? 'var(--primary)' : 'var(--border)';
    b.style.color         = on ? '#fff'            : 'var(--text2)';
  });
}

// فتح تفاصيل الطلب
async function openOrderDetail(id, source = 'standard') {
  const overlay = document.getElementById('orderDetailOverlay');
  const sheet   = document.getElementById('orderDetailSheet');
  const content = document.getElementById('orderDetailContent');

  content.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text3)"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i></div>';
  overlay.style.display = 'block';
  sheet.style.transform  = 'translateX(-50%) translateY(0)';
  document.body.style.overflow = 'hidden';

  try {
    const res  = await fetch(`<?= SITE_URL ?>/api/order_detail.php?id=${encodeURIComponent(id)}&source=${encodeURIComponent(source)}`);
    const data = await res.json();
    if (data.ok) renderOrderDetail(data.order, data.log, data.response_seconds, data.objection);
    else content.innerHTML = `<div style="padding:30px;text-align:center;color:var(--red)">خطأ: ${data.error}</div>`;
  } catch(e) {
    content.innerHTML = '<div style="padding:30px;text-align:center;color:var(--red)">فشل تحميل التفاصيل</div>';
  }
}

function closeOrderDetail() {
  const sheet = document.getElementById('orderDetailSheet');
  sheet.style.transform = 'translateX(-50%) translateY(105%)';
  document.getElementById('orderDetailOverlay').style.display = 'none';
  document.body.style.overflow = '';
}

function renderOrderDetail(o, log, responseSeconds, objection) {
  const statusCfg = {
    pending:    {label:'قيد الانتظار', color:'#f5a623', icon:'clock'},
    processing: {label:'قيد التنفيذ',  color:'#00d4ff', icon:'sync-alt fa-spin'},
    completed:  {label:'مكتمل',        color:'#00e676', icon:'check-circle'},
    cancelled:  {label:'ملغي',         color:'#ff4455', icon:'times-circle'},
    failed:     {label:'فشل',          color:'#ff4455', icon:'exclamation-circle'},
  };
  const st = statusCfg[o.status] || {label:o.status, color:'#8895a7', icon:'circle'};
  const fields = o.field_data ? JSON.parse(o.field_data) : {};

  // سبب الحالة
  let reason = (o.status_message || '').replace(/^(Oranos|SMM|AP4STOR|SMM_STANDARD|CUSTOM)\s*:\s*\S+\s*/i, '').trim();
  const reasonMap = {reject:'رُفض الطلب من المزود',rejected:'رُفض الطلب من المزود',cancel:'تم إلغاء الطلب',cancelled:'تم إلغاء الطلب',failed:'فشل تنفيذ الطلب',completed:'تم تنفيذ الطلب بنجاح',processing:'جاري تنفيذ الطلب',wait:'في انتظار التنفيذ'};
  if (!reason || /^[a-zA-Z_]+$/.test(reason)) reason = reasonMap[reason] || reasonMap[o.status] || '';

  let html = `
    <div style="padding:16px 14px 10px;text-align:center;position:relative">
      <button onclick="closeOrderDetail()" style="position:absolute;top:12px;left:12px;width:32px;height:32px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08);border-radius:50%;color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fas fa-times" style="font-size:.85rem"></i></button>
      <div style="font-size:13px;font-weight:900">${escH(o.service_name)}</div>
      <div style="font-size:11px;color:var(--text3);margin-top:3px">${escH(o.cat_name||'')}</div>
    </div>

    <!-- Status Banner -->
    <div style="margin:0 14px 12px;padding:13px;border-radius:14px;background:${st.color}14;border:1px solid ${st.color}22;display:flex;align-items:center;gap:12px">
      <div style="width:40px;height:40px;border-radius:12px;background:${st.color}20;color:${st.color};display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="fas fa-${st.icon}"></i>
      </div>
      <div style="flex:1">
        <div style="font-weight:900;color:${st.color}">${st.label}</div>
        ${reason ? `<div style="font-size:.78rem;color:var(--text2);margin-top:3px">${escH(reason)}</div>` : ''}
      </div>
      <div style="text-align:left">
        <div style="font-weight:900;color:#00d4aa;font-size:1rem">${parseFloat(o.total_price).toFixed(4)}</div>
        <div style="font-size:.65rem;color:var(--text3)">× ${parseInt(o.quantity).toLocaleString()}</div>
      </div>
    </div>

    <!-- بيانات -->
    <div style="margin:0 14px 12px;display:grid;grid-template-columns:1fr 1fr;gap:7px">
      <div style="background:var(--card2);border:1px solid var(--border);border-radius:11px;padding:9px 11px">
        <div style="font-size:.66rem;color:var(--text2);margin-bottom:2px">رقم الطلب</div>
        <div style="font-size:.78rem;font-weight:800;color:var(--primary);font-family:monospace;word-break:break-all">${o.ref_id ? o.ref_id.toUpperCase() : ('ID_ORD_'+String(o.id))}</div>
      </div>
      <div style="background:var(--card2);border:1px solid var(--border);border-radius:11px;padding:9px 11px">
        <div style="font-size:.66rem;color:var(--text2);margin-bottom:2px">التاريخ</div>
        <div style="font-size:.8rem;font-weight:700">${formatTimeLocal(o.created_at)}</div>
      </div>
      ${o.provider_order_id ? `
      <div style="background:var(--card2);border:1px solid var(--border);border-radius:11px;padding:9px 11px;grid-column:1/-1">
        <div style="font-size:.66rem;color:var(--text2);margin-bottom:2px">رقم المزود</div>
        <div style="font-size:.75rem;font-weight:700;color:var(--cyan);font-family:monospace;word-break:break-all">${escH(o.provider_order_id)}</div>
      </div>` : ''}
    </div>`;

  // الحقول المدخلة
  if (Object.keys(fields).length > 0) {
    html += `<div style="margin:0 14px 12px;background:var(--card2);border:1px solid var(--border);border-radius:12px;overflow:hidden">
      <div style="padding:9px 13px;border-bottom:1px solid var(--border);font-size:.7rem;color:var(--text2);font-weight:800">بيانات الطلب</div>`;
    for (const [k,v] of Object.entries(fields)) {
      html += `<div style="display:flex;justify-content:space-between;padding:9px 13px;border-bottom:1px solid rgba(255,255,255,.03)">
        <span style="font-size:.76rem;color:var(--text2)">${escH(k)}</span>
        <span style="font-size:.83rem;font-weight:800">${escH(v)}</span>
      </div>`;
    }
    html += '</div>';
  }

  // سجل الحالة
  if (log && log.length > 0) {
    html += `<div style="margin:0 14px 12px">
      <div style="font-size:.7rem;color:var(--text2);font-weight:800;margin-bottom:9px;display:flex;align-items:center;gap:5px"><i class="fas fa-history"></i> سجل التطورات</div>`;
    [...log].reverse().forEach(lg => {
      const ls = statusCfg[lg.status] || {label:lg.status,color:'#8895a7'};
      html += `<div style="display:flex;gap:9px;margin-bottom:9px">
        <div style="width:8px;height:8px;border-radius:50%;background:${ls.color};flex-shrink:0;margin-top:5px"></div>
        <div>
          <div style="font-size:.8rem;font-weight:800;color:${ls.color}">${ls.label}</div>
          ${lg.message ? `<div style="font-size:.76rem;color:var(--text2);margin-top:2px">${escH(lg.message)}</div>` : ''}
          <div style="font-size:.65rem;color:var(--text3);margin-top:2px">${formatTimeLocal(lg.created_at)}</div>
        </div>
      </div>`;
    });
    html += '</div>';
  }

  // ── مدة الاستجابة ──────────────────────────────────────────────
  if (responseSeconds !== null && responseSeconds !== undefined && o.status === 'completed') {
    const mins = Math.floor(responseSeconds / 60);
    const secs = responseSeconds % 60;
    const timeStr = mins > 0
      ? `${mins} دقيقة${secs > 0 ? ' و' + secs + ' ثانية' : ''}`
      : `${secs} ثانية`;
    html += `
    <div style="margin:0 14px 12px;display:flex;align-items:center;gap:10px;background:rgba(0,212,170,.07);border:1px solid rgba(0,212,170,.18);border-radius:12px;padding:10px 14px">
      <i class="fas fa-bolt" style="color:#00d4aa;font-size:.9rem"></i>
      <div>
        <div style="font-size:.67rem;color:var(--text2);margin-bottom:2px">مدة الاستجابة</div>
        <div style="font-size:.85rem;font-weight:800;color:#00d4aa">${timeStr}</div>
      </div>
    </div>`;
  }

  // ── أزرار الإجراءات (طباعة + اعتراض) ─────────────────────────
  html += `<div style="margin:0 14px 16px;display:flex;gap:8px">`;

  // زر الطباعة
  html += `
    <button onclick="printOrder(${o.id})"
      style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;border-radius:12px;border:1px solid rgba(107,163,255,.3);background:rgba(107,163,255,.08);color:#6ba3ff;font-family:var(--font);font-size:.78rem;font-weight:700;cursor:pointer">
      <i class="fas fa-print"></i> طباعة
    </button>`;

  // زر الاعتراض — فقط للطلبات العامة المكتملة
  if (o.source_type === 'standard' && o.status === 'completed') {
    if (objection) {
      const objStatusMap = {pending:'قيد المراجعة',reviewing:'جاري المراجعة',resolved:'تم الحل',rejected:'مرفوض'};
      const objColor = {pending:'#f5a623',reviewing:'#00d4ff',resolved:'#00e676',rejected:'#ff4455'};
      html += `
        <div style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;border-radius:12px;border:1px solid ${objColor[objection.status]||'#8895a7'}33;background:${objColor[objection.status]||'#8895a7'}11;color:${objColor[objection.status]||'#8895a7'};font-size:.75rem;font-weight:700">
          <i class="fas fa-flag"></i> اعتراض: ${objStatusMap[objection.status]||objection.status}
        </div>`;
    } else {
      html += `
        <button onclick="openObjectionForm(${o.id})"
          style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;border-radius:12px;border:1px solid rgba(255,100,100,.3);background:rgba(255,100,100,.08);color:#ff6677;font-family:var(--font);font-size:.78rem;font-weight:700;cursor:pointer">
          <i class="fas fa-flag"></i> اعتراض
        </button>`;
    }
  }

  html += `</div>`;

  const cont = document.getElementById('orderDetailContent');
  cont.innerHTML = html;

  // حفظ بيانات الطلب للطباعة
  cont.dataset.orderData = JSON.stringify(o);

  applyTimezone(parseFloat(loadSettings().timezone ?? 3), cont);
}

// ── نموذج الاعتراض ─────────────────────────────────────────────
function openObjectionForm(orderId) {
  const existing = document.getElementById('objectionFormWrap');
  if (existing) { existing.remove(); return; }

  const cont = document.getElementById('orderDetailContent');
  const wrap = document.createElement('div');
  wrap.id = 'objectionFormWrap';
  wrap.style.cssText = 'margin:0 14px 16px;background:rgba(255,100,100,.06);border:1px solid rgba(255,100,100,.2);border-radius:14px;padding:14px';
  wrap.innerHTML = `
    <div style="font-size:.8rem;font-weight:800;color:#ff6677;margin-bottom:8px"><i class="fas fa-flag"></i> تقديم اعتراض</div>
    <textarea id="objectionReason" placeholder="اكتب سبب اعتراضك بالتفصيل..."
      style="width:100%;min-height:90px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:10px;color:var(--text);font-family:var(--font);font-size:.8rem;resize:none;box-sizing:border-box"></textarea>
    <div style="display:flex;gap:8px;margin-top:8px">
      <button onclick="submitObjection(${orderId})"
        style="flex:1;padding:9px;border-radius:10px;border:none;background:#ff6677;color:#fff;font-family:var(--font);font-size:.8rem;font-weight:700;cursor:pointer">
        <i class="fas fa-paper-plane"></i> إرسال الاعتراض
      </button>
      <button onclick="document.getElementById('objectionFormWrap').remove()"
        style="padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);cursor:pointer">
        إلغاء
      </button>
    </div>`;
  cont.appendChild(wrap);
  wrap.scrollIntoView({behavior:'smooth', block:'end'});
}

async function submitObjection(orderId) {
  const reason = document.getElementById('objectionReason')?.value.trim();
  if (!reason || reason.length < 5) { alert('الرجاء كتابة سبب الاعتراض (5 أحرف على الأقل)'); return; }

  const fd = new FormData();
  fd.append('submit_objection', '1');
  fd.append('reason', reason);

  try {
    const res  = await fetch(`<?= SITE_URL ?>/api/order_detail.php?id=${orderId}`, {method:'POST', body:fd});
    const data = await res.json();
    if (data.ok) {
      document.getElementById('objectionFormWrap')?.remove();
      // استبدل زر الاعتراض بشارة "قيد المراجعة"
      const btns = document.querySelectorAll('#orderDetailContent button');
      btns.forEach(b => { if (b.textContent.includes('اعتراض')) {
        b.outerHTML = `<div style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;border-radius:12px;border:1px solid #f5a62333;background:#f5a62311;color:#f5a623;font-size:.75rem;font-weight:700"><i class="fas fa-flag"></i> اعتراض: قيد المراجعة</div>`;
      }});
      alert(data.msg);
    } else {
      alert(data.error || 'حدث خطأ');
    }
  } catch(e) { alert('فشل الاتصال'); }
}

// ── طباعة الطلب ────────────────────────────────────────────────
function printOrder(orderId) {
  const cont = document.getElementById('orderDetailContent');
  const o    = JSON.parse(cont.dataset.orderData || '{}');
  const siteName = '<?= getSetting("site_name") ?: SITE_NAME ?>';
  const siteUrl  = '<?= SITE_URL ?>';
  const logoUrl  = '<?= getSetting("site_logo") ? SITE_URL."/".getSetting("site_logo") : "" ?>';

  const statusMap = {pending:'قيد الانتظار',processing:'قيد التنفيذ',completed:'تمت بنجاح',cancelled:'ملغي',failed:'فشل'};
  const statusAr  = statusMap[o.status] || o.status;

  const fields = o.field_data ? JSON.parse(o.field_data) : {};
  let fieldsHtml = '';
  for (const [k,v] of Object.entries(fields)) {
    fieldsHtml += `<tr><td style="color:#666;padding:4px 0">${k}</td><td style="font-weight:700;text-align:left">${v}</td></tr>`;
  }

  const tz  = parseFloat(window.appSettings?.timezone ?? 3);
  const dateStr = o.created_at ? convertServerTime(o.created_at, tz) : o.created_at;

  const win = window.open('', '_blank', 'width=420,height=700');
  win.document.write(`<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>طلب #${o.id} — ${siteName}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; font-size: 13px; color: #111; background: #fff; padding: 24px 20px; max-width: 380px; margin: 0 auto; }
  .center { text-align: center; }
  .logo { max-height: 60px; max-width: 180px; }
  .site-name { font-size: 20px; font-weight: 900; margin: 6px 0 2px; letter-spacing: 1px; }
  .divider { border: none; border-top: 1px dashed #aaa; margin: 10px 0; }
  .label { color: #666; font-size: 12px; }
  .value { font-weight: 700; font-size: 14px; }
  .status { font-size: 16px; font-weight: 900; margin: 6px 0; }
  .completed { color: #008000; }
  .failed, .cancelled { color: #cc0000; }
  .pending, .processing { color: #cc6600; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 5px 0; vertical-align: top; }
  @media print {
    body { padding: 10px; }
    .no-print { display: none; }
  }
</style>
</head>
<body>
<div class="center">
  ${logoUrl ? `<img src="${logoUrl}" class="logo" onerror="this.style.display='none'"><br>` : ''}
  <div class="site-name">${siteName}</div>
  <div class="label">${siteUrl}</div>
</div>
<hr class="divider">

<table>
  <tr><td class="label">رقم الطلب</td><td class="value" style="text-align:left">${o.ref_id ? o.ref_id.toUpperCase() : ('ID_ORD_'+String(o.id))}</td></tr>
  <tr><td class="label">التاريخ</td><td class="value" style="text-align:left">${dateStr}</td></tr>
</table>
<hr class="divider">

<table>
  <tr>
    <td class="value" style="font-size:15px">${escH(o.service_name)}</td>
    <td class="value" style="text-align:left;font-size:15px">× ${parseInt(o.quantity).toLocaleString()}</td>
  </tr>
</table>
${fieldsHtml ? `<hr class="divider"><table>${fieldsHtml}</table>` : ''}
<hr class="divider">

<table>
  <tr><td class="label">المبلغ</td><td class="value" style="text-align:left">${parseFloat(o.total_price).toFixed(4)}</td></tr>
</table>
<hr class="divider">

<div class="center status ${o.status}">${statusAr}</div>
<hr class="divider">
<div class="center label" style="font-size:11px;margin-top:4px">وثيقة إلكترونية صادرة من ${siteName}</div>

<div class="no-print" style="margin-top:20px;text-align:center">
  <button onclick="window.print()" style="padding:10px 28px;background:#000;color:#fff;border:none;border-radius:8px;font-size:14px;cursor:pointer">🖨️ طباعة / حفظ PDF</button>
</div>
<script>
  window.onload = function() { setTimeout(function(){ window.print(); }, 400); };
<\/script>
</body></html>`);
  win.document.close();
}

function formatTimeLocal(dt) {
  if (!dt) return '—';
  const tz = parseFloat(loadSettings().timezone ?? 3);
  return convertServerTime(dt, tz);
}

function escH(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<script>
// ══════════════════════════════════════════════════════════════
// نظام المحادثة المباشرة — العميل
// ══════════════════════════════════════════════════════════════
const CHAT_API_URL = '<?= SITE_URL ?>/api/chat.php';
let _chatId    = null;
let _chatLastId = 0;
let _chatPoll  = null;

async function openChat() {
  const overlay = document.getElementById('chatOverlay');
  const win     = document.getElementById('chatWindow');
  if (!overlay || !win) return;

  overlay.classList.add('open');
  win.classList.add('open');
  document.body.style.overflow = 'hidden';

  // spinner
  const msgEl = document.getElementById('chatMessages');
  if (msgEl) msgEl.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text3)"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i></div>';

  try {
    const fd = new FormData();
    fd.append('action', 'open_chat');
    fd.append('subject', 'استفسار');
    const r = await fetch(CHAT_API_URL, {method:'POST', body:fd});
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch(e) {
      if (msgEl) msgEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--red)">خطأ في الاتصال</div>';
      return;
    }
    if (!d.ok) {
      if (msgEl) msgEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--red)">تعذر فتح المحادثة</div>';
      return;
    }
    _chatId     = d.chat.id;
    _chatLastId = 0;
    renderChatMessages(d.messages || []);

    // إذا مغلقة — عطّل الإرسال
    setChatInputState(d.chat.status);

    if (d.is_existing) {
      const el = document.getElementById('chatMessages');
      if (el) {
        const notice = document.createElement('div');
        notice.style.cssText = 'text-align:center;font-size:.72rem;color:var(--text3);padding:6px;margin:4px 0';
        notice.textContent = d.chat.status === 'closed' ? '🔒 هذه المحادثة مغلقة' : '← استكمال محادثتك السابقة';
        el.insertBefore(notice, el.firstChild);
      }
    }
    startChatPoll();
    setTimeout(() => document.getElementById('chatInput')?.focus(), 300);
  } catch(e) {
    if (msgEl) msgEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--red)">خطأ: ' + e.message + '</div>';
  }
}

function closeChat() {
  const overlay = document.getElementById('chatOverlay');
  const win     = document.getElementById('chatWindow');
  overlay?.classList.remove('open');
  win?.classList.remove('open');
  document.body.style.overflow = '';
  clearTimeout(_chatPoll);
}

function renderChatMessages(msgs) {
  const el = document.getElementById('chatMessages');
  if (!el) return;
  el.innerHTML = '';
  msgs.forEach(m => appendChatMsg(m));
  el.scrollTop = el.scrollHeight;
  if (msgs.length) _chatLastId = msgs[msgs.length-1].id;
}

function appendChatMsg(m) {
  const el = document.getElementById('chatMessages');
  if (!el) return;
  // رسالة نظام — تُعرض في المنتصف
  if (m.sender_type === 'system') {
    const sysDiv = document.createElement('div');
    sysDiv.className = 'cwb-system';
    sysDiv.innerHTML = `<i class="fas fa-link" style="font-size:.65rem"></i> ${escHTML(m.message)}`;
    el.appendChild(sysDiv);
    el.scrollTop = el.scrollHeight;
    if (parseInt(m.id) > _chatLastId) _chatLastId = parseInt(m.id);
    return;
  }
  const isOut  = m.sender_type === 'user';
  const isAuto = parseInt(m.is_auto) === 1;
  const cls    = isAuto ? 'cwb-auto' : (isOut ? 'cwb-out' : 'cwb-in');
  const name   = isOut ? 'أنت' : (isAuto ? '🤖 ردّ تلقائي' : 'فريق الدعم');
  const time   = chatFormatTime(m.created_at);
  const div = document.createElement('div');
  div.innerHTML = `<div class="cwb ${cls}">
    ${isAuto ? '<div class="cwb-auto-tag"><i class="fas fa-robot"></i> رد تلقائي</div>' : ''}
    <div class="cwb-name">${name}</div>
    ${escHTML(m.message).replace(/\n/g,'<br>')}
    <span class="cwb-time">${time}</span>
  </div>`;
  el.appendChild(div);
  el.scrollTop = el.scrollHeight;
  if (parseInt(m.id) > _chatLastId) _chatLastId = parseInt(m.id);
}

function setChatInputState(status) {
  const input  = document.getElementById('chatInput');
  const sendBtn = document.querySelector('.chat-win-send');
  const isClosed = status === 'closed';
  if (input) {
    input.disabled    = isClosed;
    input.placeholder = isClosed ? '🔒 المحادثة مغلقة' : 'اكتب رسالتك...';
    input.style.opacity = isClosed ? '0.5' : '1';
  }
  if (sendBtn) sendBtn.disabled = isClosed;
  // أضف زر "فتح محادثة جديدة" إذا مغلقة
  const inputArea = document.querySelector('.chat-win-input');
  if (inputArea) {
    const existing = document.getElementById('newChatBtn');
    if (isClosed && !existing) {
      const btn = document.createElement('button');
      btn.id = 'newChatBtn';
      btn.onclick = startNewChat;
      btn.style.cssText = 'width:100%;padding:10px;background:var(--primary);border:none;border-radius:10px;color:#fff;font-family:var(--font);font-size:.88rem;font-weight:700;cursor:pointer;margin-top:8px';
      btn.innerHTML = '<i class="fas fa-plus"></i> بدء محادثة جديدة';
      inputArea.appendChild(btn);
    } else if (!isClosed && existing) {
      existing.remove();
    }
  }
}

async function startNewChat() {
  // أغلق المحادثة الحالية وابدأ جديدة
  _chatId = null;
  _chatLastId = 0;
  const msgEl = document.getElementById('chatMessages');
  if (msgEl) msgEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3)"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i></div>';
  document.getElementById('newChatBtn')?.remove();

  try {
    const fd = new FormData();
    fd.append('action', 'new_chat'); // محادثة جديدة دائماً
    fd.append('subject', 'استفسار');
    const r  = await fetch(CHAT_API_URL, {method:'POST', body:fd});
    const d  = await r.json();
    if (!d.ok) return;
    _chatId = d.chat.id;
    renderChatMessages(d.messages || []);
    setChatInputState(d.chat.status);
    startChatPoll();
  } catch(e) {}
}

async function sendChatMsg() {
  const input = document.getElementById('chatInput');
  const msg   = input?.value.trim();
  if (!msg || !_chatId) return;
  input.value = '';
  input.style.height = 'auto';

  // أضف الرسالة فوراً للواجهة
  appendChatMsg({
    id: Date.now(),
    sender_type: 'user',
    message: msg,
    is_auto: 0,
    created_at: new Date().toISOString().replace('T',' ').substring(0,19)
  });

  try {
    const fd = new FormData();
    fd.append('action','send');
    fd.append('chat_id', _chatId);
    fd.append('message', msg);
    const r = await fetch(CHAT_API_URL, {method:'POST', body:fd});
    const d = await r.json();
    if (!d.ok && d.error === 'closed') {
      setChatInputState('closed');
    }
  } catch(e) {}
}

function startChatPoll() {
  clearTimeout(_chatPoll);
  if (!_chatId) return;
  _chatPoll = setTimeout(async () => {
    const r = await fetch(`${CHAT_API_URL}?action=poll&chat_id=${_chatId}&after_id=${_chatLastId}`);
    const d = await r.json();
    if (d.messages && d.messages.length) {
      d.messages.forEach(m => appendChatMsg(m));
      // صوت للرسائل الجديدة من الموظف
      if (d.messages.some(m => m.sender_type === 'staff' && !m.is_auto)) {
        try { if(typeof playSuccessSound==='function') playSuccessSound(); } catch(e){}
      }
    }
    startChatPoll();
  }, 1500);
}

async function showChatHistory() {
  const panel = document.getElementById('chatHistoryPanel');
  const list  = document.getElementById('chatHistoryList');
  if (!panel) return;
  panel.style.display = 'flex';
  list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3)"><i class="fas fa-spinner fa-spin"></i></div>';

  try {
    const r = await fetch(`${CHAT_API_URL}?action=user_history`);
    const d = await r.json();
    if (!d.chats || !d.chats.length) {
      list.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text3)">لا توجد محادثات سابقة</div>';
      return;
    }
    list.innerHTML = d.chats.map(c => `
      <div onclick="loadOldChat(${c.id})" style="background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:8px;cursor:pointer">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
          <span style="font-weight:700;font-size:.88rem">${escHTML(c.subject||'محادثة')}</span>
          <span style="font-size:.65rem;color:var(--text3)">${chatFormatDate(c.updated_at)}</span>
        </div>
        <div style="font-size:.75rem;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHTML(c.last_msg||'—')}</div>
        <div style="margin-top:4px">
          <span style="font-size:.65rem;padding:2px 8px;border-radius:10px;background:${c.status==='open'?'rgba(0,230,118,.15)':c.status==='pending'?'rgba(245,166,35,.15)':'rgba(136,149,167,.15)'};color:${c.status==='open'?'#00e676':c.status==='pending'?'#f5a623':'var(--text3)'}">
            ${c.status==='open'?'مفتوحة':c.status==='pending'?'انتظار':'مغلقة'}
          </span>
        </div>
      </div>`).join('');
  } catch(e) {
    list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--red)">خطأ في التحميل</div>';
  }
}

function hideChatHistory() {
  const panel = document.getElementById('chatHistoryPanel');
  if (panel) panel.style.display = 'none';
}

async function loadOldChat(chatId) {
  hideChatHistory();
  clearTimeout(_chatPoll);

  const msgEl = document.getElementById('chatMessages');
  if (msgEl) msgEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3)"><i class="fas fa-spinner fa-spin"></i></div>';

  const r = await fetch(`${CHAT_API_URL}?action=load_chat&chat_id=${chatId}`);
  const d = await r.json();
  if (!d.ok) return;
  _chatId = chatId;
  _chatLastId = 0;
  renderChatMessages(d.messages || []);
  startChatPoll();
}

function chatFormatDate(dt) {
  if (!dt) return '';
  const d = new Date(dt.replace(' ','T'));
  return `${d.getDate()}/${d.getMonth()+1} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}

function chatFormatTime(dt) {
  if (!dt) return '';
  const d = new Date(dt.replace ? dt.replace(' ','T') : dt);
  return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
}
function copyRefCode(code) {
  if (!code) code = (document.getElementById('myRefCode')||{}).textContent||'';
  code = code.trim();
  if (!code) return;
  var ta = document.createElement('textarea');
  ta.value = code;
  ta.setAttribute('readonly','');
  ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px';
  document.body.appendChild(ta);
  ta.focus(); ta.select();
  try { document.execCommand('copy'); } catch(e){}
  document.body.removeChild(ta);
  if (navigator.clipboard) {
    navigator.clipboard.writeText(code).catch(function(){});
  }
  showToast('✅ تم نسخ الكود: ' + code);
}
function shareRefCode(code) {
  if (!code) code = (document.getElementById('myRefCode')||{}).textContent||'';
  code = code.trim();
  if (!code) return;
  var base = '<?= SITE_URL ?>/mobile.php';
  var link = base + '?ref=' + code;
  var msg  = '🎁 سجّل معنا وستحصل على رصيد ترحيبي! كود الدعوة: ' + code + '\n' + link;
  if (navigator.share) {
    navigator.share({ title: 'دعوة صديق', text: msg, url: link }).catch(function(){});
  } else {
    copyRefCode(msg);
    showToast('✅ تم نسخ رابط الدعوة');
  }
}

function escHTML(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<script>
// ══════════════════════════════════════════════════════════════
// نظام المسابقات
// ══════════════════════════════════════════════════════════════
const QUIZ_API = '<?= SITE_URL ?>/api/quiz.php';
let _quiz = null, _quizQuestions = [], _quizAnswers = {}, _quizTimer = null;
let _quizCurrent = 0, _quizTimeLeft = 0, _quizStartTime = 0;

async function openQuiz() {
  const overlay = document.getElementById('quizOverlay');
  if (!overlay) return;
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';

  document.getElementById('quizLoading').style.display = 'flex';
  document.getElementById('quizContent').style.display  = 'none';

  try {
    const r = await fetch(`${QUIZ_API}?action=active`);
    const d = await r.json();

    if (!d.ok) {
      showQuizError(d.error === 'no_active' ? 'لا توجد مسابقة نشطة حالياً 😔' : 'خطأ في تحميل المسابقة');
      return;
    }
    if (d.my_entry) {
      showQuizAlready(d.my_entry, d.quiz);
      return;
    }

    _quiz          = d.quiz;
    _quizQuestions = d.questions;
    _quizAnswers   = {};
    _quizCurrent   = 0;
    _quizTimeLeft  = parseInt(d.quiz.time_seconds);
    _quizStartTime = Date.now();

    showQuizIntro(d.quiz, d.questions.length);
  } catch(e) {
    showQuizError('خطأ في الاتصال');
  }
}

function closeQuiz() {
  clearInterval(_quizTimer);
  document.getElementById('quizOverlay')?.classList.remove('open');
  document.body.style.overflow = '';
}

function showQuizError(msg) {
  document.getElementById('quizLoading').innerHTML = `
    <i class="fas fa-exclamation-circle" style="font-size:2.5rem;color:#ff4455"></i>
    <div style="font-size:1rem;font-weight:700">${msg}</div>
    <button onclick="closeQuiz()" style="margin-top:10px;padding:10px 24px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:12px;color:#fff;cursor:pointer;font-family:var(--font)">إغلاق</button>`;
  document.getElementById('quizLoading').style.display = 'flex';
}

function showQuizAlready(entry, quiz) {
  const pct = Math.round((entry.correct_count/entry.total_questions)*100);
  document.getElementById('quizLoading').innerHTML = `
    <div style="text-align:center;padding:20px">
      <div style="font-size:3rem;margin-bottom:12px">${pct>=80?'🏆':pct>=50?'👍':'😔'}</div>
      <div style="font-size:1.1rem;font-weight:900;margin-bottom:8px">شاركت في هذه المسابقة</div>
      <div style="font-size:2rem;font-weight:900;color:#f5a623;margin:10px 0">${entry.correct_count}/${entry.total_questions}</div>
      <div style="font-size:.85rem;color:rgba(255,255,255,.6);margin-bottom:20px">إجابة صحيحة</div>
      ${entry.is_winner?'<div style="background:rgba(245,166,35,.2);border:1px solid rgba(245,166,35,.4);border-radius:12px;padding:12px;color:#f5a623;font-weight:700;margin-bottom:16px">🏆 مبروك! أنت من الفائزين</div>':''}
      <button onclick="closeQuiz()" style="padding:12px 30px;background:var(--primary);border:none;border-radius:12px;color:#fff;cursor:pointer;font-family:var(--font);font-size:.95rem;font-weight:700">إغلاق</button>
    </div>`;
  document.getElementById('quizLoading').style.display = 'flex';
}

function showQuizIntro(quiz, qCount) {
  const content = document.getElementById('quizContent');
  const mins = Math.floor(quiz.time_seconds/60);
  const secs = quiz.time_seconds%60;
  const timeStr = mins>0 ? `${mins}د ${secs>0?secs+'ث':''}` : `${secs}ث`;

  content.innerHTML = `
    <div class="quiz-header">
      <span class="quiz-trophy">🏆</span>
      <div>
        <div style="font-weight:900;font-size:1rem;color:#fff">${escQ(quiz.name)}</div>
        <div style="font-size:.72rem;color:rgba(255,255,255,.5)">${escQ(quiz.title)}</div>
      </div>
      <button onclick="closeQuiz()" style="margin-right:auto;background:rgba(255,255,255,.1);border:none;width:32px;height:32px;border-radius:50%;color:#fff;cursor:pointer;font-size:.85rem">✕</button>
    </div>
    <div class="quiz-intro">
      <div style="font-size:1rem;color:rgba(255,255,255,.6)">الجائزة الكبرى</div>
      <div class="quiz-intro-prize">💰 ${parseFloat(quiz.prize_amount).toFixed(2)} $</div>
      <div class="quiz-info-grid">
        <div class="quiz-info-card">
          <div class="quiz-info-val">⏱ ${timeStr}</div>
          <div class="quiz-info-lbl">وقت المسابقة</div>
        </div>
        <div class="quiz-info-card">
          <div class="quiz-info-val">❓ ${qCount}</div>
          <div class="quiz-info-lbl">عدد الأسئلة</div>
        </div>
        <div class="quiz-info-card">
          <div class="quiz-info-val">🥇 ${quiz.winners_count}</div>
          <div class="quiz-info-lbl">عدد الفائزين</div>
        </div>
        <div class="quiz-info-card">
          <div class="quiz-info-val">✅ KYC</div>
          <div class="quiz-info-lbl">حساب موثق</div>
        </div>
      </div>
      <div style="font-size:.78rem;color:rgba(255,255,255,.4);text-align:center;max-width:280px;line-height:1.7">
        يُختار الفائزون تلقائياً بناءً على أعلى نسبة إجابات صحيحة وأقل وقت
      </div>
      <button onclick="startQuiz()" style="padding:14px 40px;background:linear-gradient(135deg,#f5a623,#ff6b35);border:none;border-radius:16px;color:#fff;font-family:var(--font);font-size:1rem;font-weight:800;cursor:pointer;box-shadow:0 6px 20px rgba(245,166,35,.4);width:100%;max-width:280px">
        🚀 ابدأ المسابقة
      </button>
    </div>`;

  document.getElementById('quizLoading').style.display = 'none';
  content.style.display = 'flex';
}

function startQuiz() {
  _quizCurrent  = 0;
  _quizAnswers  = {};
  _quizTimeLeft = parseInt(_quiz.time_seconds);
  _quizStartTime = Date.now();
  startQuizTimer();
  renderQuestion();
}

function startQuizTimer() {
  clearInterval(_quizTimer);
  _quizTimer = setInterval(() => {
    _quizTimeLeft--;
    const timerEl = document.getElementById('quizTimerEl');
    if (timerEl) {
      const m = Math.floor(_quizTimeLeft/60), s = _quizTimeLeft%60;
      timerEl.textContent = (m>0?m+'د ':'')+s+'ث';
      timerEl.parentElement.className = 'quiz-timer ' + (_quizTimeLeft>10?'ok':'');
    }
    if (_quizTimeLeft <= 0) {
      clearInterval(_quizTimer);
      submitQuiz();
    }
  }, 1000);
}

function renderQuestion() {
  if (_quizCurrent >= _quizQuestions.length) { submitQuiz(); return; }
  const q      = _quizQuestions[_quizCurrent];
  const total  = _quizQuestions.length;
  const pct    = Math.round((_quizCurrent/total)*100);
  const opts   = [['a','أ'],['b','ب'],['c','ج'],['d','د']].filter(([o])=>q['option_'+o]);
  const mins   = Math.floor(_quizTimeLeft/60), secs=_quizTimeLeft%60;
  const timeStr= (mins>0?mins+'د ':'')+secs+'ث';
  const content = document.getElementById('quizContent');

  content.innerHTML = `
    <div class="quiz-header">
      <span class="quiz-trophy">🏆</span>
      <div style="flex:1">
        <div style="font-weight:700;font-size:.85rem;color:#fff">${escQ(_quiz.name)}</div>
      </div>
      <div class="quiz-timer ${_quizTimeLeft>10?'ok':''}" id="quizTimerEl">${timeStr}</div>
    </div>
    <div class="quiz-progress"><div class="quiz-progress-fill" style="width:${pct}%"></div></div>
    <div class="quiz-body">
      <div class="quiz-q-counter">السؤال ${_quizCurrent+1} من ${total}</div>
      <div class="quiz-question">${escQ(q.question)}</div>
      ${opts.map(([o,l])=>`
      <button class="quiz-option ${_quizAnswers[q.id]===o?'selected':''}" id="opt_${o}" onclick="selectAnswer('${q.id}','${o}')">
        <span class="quiz-opt-letter">${l}</span>
        ${escQ(q['option_'+o])}
      </button>`).join('')}
    </div>
    <div class="quiz-nav">
      ${_quizCurrent>0?`<button class="quiz-btn quiz-btn-skip" onclick="prevQuestion()" style="flex:.4">السابق</button>`:''}
      <button class="quiz-btn quiz-btn-next" onclick="nextQuestion()">
        ${_quizCurrent<total-1?'التالي ←':'إنهاء وإرسال 🚀'}
      </button>
    </div>`;
}

function selectAnswer(qid, opt) {
  _quizAnswers[qid] = opt;
  document.querySelectorAll('.quiz-option').forEach(el => el.classList.remove('selected'));
  document.getElementById('opt_'+opt)?.classList.add('selected');
}

function nextQuestion() {
  if (_quizCurrent < _quizQuestions.length-1) { _quizCurrent++; renderQuestion(); }
  else submitQuiz();
}
function prevQuestion() {
  if (_quizCurrent > 0) { _quizCurrent--; renderQuestion(); }
}

async function submitQuiz() {
  clearInterval(_quizTimer);
  const timeTaken = Math.round((Date.now()-_quizStartTime)/1000);
  const content = document.getElementById('quizContent');
  content.innerHTML = `<div style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:#fff">
    <i class="fas fa-spinner fa-spin" style="font-size:2rem;opacity:.6"></i>
    <div>جاري إرسال إجاباتك...</div>
  </div>`;

  try {
    const fd = new FormData();
    fd.append('action','submit');
    fd.append('quiz_id', _quiz.id);
    fd.append('answers', JSON.stringify(_quizAnswers));
    fd.append('time_taken', timeTaken);
    const r = await fetch(QUIZ_API, {method:'POST',body:fd});
    const d = await r.json();

    if (!d.ok) {
      const msgs = {kyc:'يجب توثيق حسابك (KYC) للمشاركة في المسابقات', balance:'يجب أن يكون لديك رصيد في حسابك', already:'شاركت في هذه المسابقة مسبقاً', closed:'المسابقة مغلقة'};
      content.innerHTML = `<div class="quiz-result">
        <div class="quiz-result-icon">😔</div>
        <div style="font-size:1.1rem;font-weight:700;margin-bottom:10px">${msgs[d.error]||d.message||'خطأ'}</div>
        <button onclick="closeQuiz()" style="padding:12px 30px;background:var(--primary);border:none;border-radius:12px;color:#fff;cursor:pointer;font-family:var(--font);font-weight:700">إغلاق</button>
      </div>`;
      return;
    }

    const pct = Math.round((d.correct/d.total)*100);
    const icon = pct>=80?'🏆':pct>=50?'👍':'😊';
    content.innerHTML = `<div class="quiz-result">
      <div class="quiz-result-icon">${icon}</div>
      <div style="color:rgba(255,255,255,.6);font-size:.9rem;margin-bottom:4px">نتيجتك</div>
      <div class="quiz-result-score">${d.correct}/${d.total}</div>
      <div style="font-size:.88rem;color:rgba(255,255,255,.6);margin:8px 0 20px">${d.message}</div>
      <div style="font-size:.8rem;color:rgba(255,255,255,.4);margin-bottom:20px">
        ⏱ الوقت المستغرق: ${timeTaken}ث<br>
        سيتم الإعلان عن الفائزين قريباً 🎉
      </div>
      <button onclick="closeQuiz()" style="padding:12px 30px;background:linear-gradient(135deg,var(--primary),#7c3aed);border:none;border-radius:12px;color:#fff;cursor:pointer;font-family:var(--font);font-size:.95rem;font-weight:700;width:100%;max-width:280px">إغلاق</button>
    </div>`;
  } catch(e) {
    content.innerHTML = `<div class="quiz-result"><div class="quiz-result-icon">😔</div><div>خطأ في الإرسال</div><button onclick="closeQuiz()" style="margin-top:16px;padding:10px 24px;background:var(--primary);border:none;border-radius:12px;color:#fff;cursor:pointer;font-family:var(--font)">إغلاق</button></div>`;
  }
}

function escQ(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<script>
// ══════════════════════════════════════════════════════════════
// تفاصيل المدفوعات
// ══════════════════════════════════════════════════════════════
function openPayDetail(card) {
  const d = card.dataset;
  const overlay = document.getElementById('payDetailOverlay');
  const sheet   = document.getElementById('payDetailSheet');
  const content = document.getElementById('payDetailContent');
  if (!overlay) return;

  const isDirect  = d.type === 'direct';
  const isCard    = d.type === 'card';
  const statusColor = d.statusColor || '#8895a7';
  const tz = parseFloat(loadSettings().timezone ?? 3);

  let html = `
    <!-- هيدر -->
    <div style="padding:16px 16px 10px;text-align:center;position:relative">
      <button onclick="closePayDetail()" style="position:absolute;top:12px;left:12px;width:32px;height:32px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08);border-radius:50%;color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fas fa-times"></i></button>
      <div style="font-size:2rem;margin-bottom:8px">${isDirect ? '💳' : isCard ? '🎫' : '🏦'}</div>
      <div style="font-weight:900;font-size:1rem">${escH(d.method)}</div>
      <div style="font-size:.75rem;color:var(--text3);margin-top:3px">${isDirect ? 'دفع مباشر — فلوسك' : isCard ? 'شحن بكود بطاقة — مباشر' : 'تحويل يدوي'}</div>
    </div>

    <!-- شارة الحالة -->
    <div style="margin:0 14px 14px;padding:13px;border-radius:14px;background:${statusColor}14;border:1px solid ${statusColor}22;display:flex;align-items:center;gap:12px">
      <div style="width:40px;height:40px;border-radius:12px;background:${statusColor}20;color:${statusColor};display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">
        <i class="fas fa-${getPayIcon(d.status)}"></i>
      </div>
      <div style="flex:1">
        <div style="font-weight:900;color:${statusColor};font-size:.98rem">${escH(d.statusLabel)}</div>
        ${d.adminNote ? `<div style="font-size:.78rem;color:var(--text2);margin-top:3px">${escH(d.adminNote)}</div>` : ''}
      </div>
      <div style="text-align:left;flex-shrink:0">
        <div style="font-weight:900;color:#00d4aa;font-size:1.05rem">+${escH(d.amount)}</div>
        ${d.amountSub ? `<div style="font-size:.65rem;color:var(--text3)">${escH(d.amountSub)}</div>` : ''}
      </div>
    </div>

    <!-- تفاصيل -->
    <div style="margin:0 14px 12px;display:grid;grid-template-columns:1fr 1fr;gap:7px">
      <div style="background:var(--card2);border:1px solid var(--border);border-radius:11px;padding:9px 11px">
        <div style="font-size:.66rem;color:var(--text2);margin-bottom:2px">رقم العملية</div>
        <div style="font-size:.82rem;font-weight:700;color:var(--primary);word-break:break-all">#${escH(d.ref||d.id)}</div>
      </div>
      <div style="background:var(--card2);border:1px solid var(--border);border-radius:11px;padding:9px 11px">
        <div style="font-size:.66rem;color:var(--text2);margin-bottom:2px">التاريخ</div>
        <div style="font-size:.8rem;font-weight:700">${convertServerTime(d.date, tz)}</div>
      </div>
      ${isDirect && d.phone ? `
      <div style="background:var(--card2);border:1px solid var(--border);border-radius:11px;padding:9px 11px">
        <div style="font-size:.66rem;color:var(--text2);margin-bottom:2px">رقم الهاتف</div>
        <div style="font-size:.82rem;font-weight:700;font-family:monospace">${escH(d.phone)}</div>
      </div>` : ''}
      ${isCard && d.ref ? `
      <div style="background:var(--card2);border:1px solid rgba(0,212,170,.2);border-radius:11px;padding:9px 11px;grid-column:1/-1">
        <div style="font-size:.66rem;color:var(--text2);margin-bottom:4px">🎫 كود البطاقة</div>
        <div style="font-size:.88rem;font-weight:900;font-family:monospace;color:var(--cyan);letter-spacing:1px;word-break:break-all">${escH(d.ref)}</div>
      </div>` : ''}
      ${isDirect && d.amountYer ? `
      <div style="background:var(--card2);border:1px solid var(--border);border-radius:11px;padding:9px 11px">
        <div style="font-size:.66rem;color:var(--text2);margin-bottom:2px">المبلغ بالريال</div>
        <div style="font-size:.82rem;font-weight:700">${escH(d.amountYer)} ريال</div>
      </div>` : ''}
    </div>`;

  // ملاحظة العميل
  if (d.note) {
    html += `<div style="margin:0 14px 12px;background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:11px 13px">
      <div style="font-size:.68rem;color:var(--text2);font-weight:700;margin-bottom:4px"><i class="fas fa-comment-alt"></i> ملاحظتك</div>
      <div style="font-size:.84rem">${escH(d.note)}</div>
    </div>`;
  }

  // الإيصال
  if (!isDirect && d.receipt) {
    html += `<div style="margin:0 14px 14px">
      <div style="font-size:.68rem;color:var(--text2);font-weight:700;margin-bottom:8px"><i class="fas fa-image"></i> إيصال الدفع</div>
      <img src="${escH(d.receipt)}" onclick="window.open('${escH(d.receipt)}','_blank')"
           style="width:100%;border-radius:12px;border:1px solid var(--border);cursor:zoom-in;max-height:300px;object-fit:contain;background:#000">
      <div style="font-size:.68rem;color:var(--text3);margin-top:5px;text-align:center">اضغط على الصورة للتكبير</div>
    </div>`;
  }

  // رسالة للعمليات المعلقة
  if (d.status === 'pending' && isDirect) {
    html += `<div style="margin:0 14px 14px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:12px;padding:11px 13px;font-size:.8rem;color:#f5a623">
      <i class="fas fa-info-circle"></i> العملية قيد المعالجة — تنتهي خلال 10 دقائق
    </div>`;
  } else if (d.status === 'pending' && !isDirect && !isCard) {
    html += `<div style="margin:0 14px 14px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:12px;padding:11px 13px;font-size:.8rem;color:#f5a623">
      <i class="fas fa-clock"></i> طلبك قيد المراجعة من فريق الدعم
    </div>`;
  } else if (isCard) {
    html += `<div style="margin:0 14px 14px;background:rgba(0,212,170,.06);border:1px solid rgba(0,212,170,.2);border-radius:12px;padding:11px 13px;font-size:.8rem;color:#00d4aa">
      <i class="fas fa-check-circle"></i> تم الشحن فوراً — العملية مكتملة
    </div>`;
  }

  content.innerHTML = html;
  overlay.style.display = 'block';
  sheet.style.transform = 'translateX(-50%) translateY(0)';
  document.body.style.overflow = 'hidden';
}

function closePayDetail() {
  document.getElementById('payDetailOverlay').style.display = 'none';
  document.getElementById('payDetailSheet').style.transform = 'translateX(-50%) translateY(105%)';
  document.body.style.overflow = '';
}

function getPayIcon(status) {
  const icons = {completed:'check-circle', approved:'check-circle', pending:'clock', failed:'times-circle', expired:'ban', rejected:'times-circle'};
  return icons[status] || 'circle';
}
</script>

<script>
// ══════════════════════════════════════════════════════════════
// شحن بكود البطاقة
// ══════════════════════════════════════════════════════════════
function openCardRedeem() {
  document.getElementById('cardRedeemOverlay').style.display = 'block';
  document.getElementById('cardRedeemSheet').style.transform = 'translateX(-50%) translateY(0)';
  document.body.style.overflow = 'hidden';
  setTimeout(function(){ document.getElementById('cardCodeInput').focus(); }, 300);
}
function closeCardRedeem() {
  document.getElementById('cardRedeemOverlay').style.display = 'none';
  document.getElementById('cardRedeemSheet').style.transform = 'translateX(-50%) translateY(105%)';
  document.body.style.overflow = '';
  document.getElementById('cardCodeInput').value = '';
  document.getElementById('cardRedeemMsg').innerHTML = '';
}
function formatSheetCode(el) {
  var v = el.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
  var f = '';
  for (var i=0; i<v.length && i<24; i++) {
    if (i===8 || i===16) f += '-';
    f += v[i];
  }
  el.value = f;
}

function redeemSheetCard() {
  if (KYC_STATUS !== 'approved') { _showKycRequiredToast(); return; }
  var code = (document.getElementById('sheetCardCode')||{value:''}).value.trim();
  var msg  = document.getElementById('sheetCardMsg');
  var btn  = document.getElementById('sheetCardBtn');
  if (!code || code.replace(/-/g,'').length < 16) {
    msg.style.color='#ff4455'; msg.textContent='❌ أدخل الكود كاملاً'; return;
  }
  btn.disabled=true; btn.textContent='⏳ جارٍ التحقق...';
  msg.textContent='';

  var fd = new FormData();
  fd.append('code', code);

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '<?= SITE_URL ?>/api/redeem_card.php', true);
  xhr.withCredentials = true;
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) return;
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-bolt"></i> شحن الرصيد';
    try {
      var d = JSON.parse(xhr.responseText);
      if (d.ok) {
        msg.style.color = '#00e676';
        msg.textContent = '✅ ' + d.message;
        btn.textContent = '✓ تم الشحن!';
        btn.style.background = '#00c853';
        btn.disabled = true;
        // تحديث الرصيد
        updateAllBalances(d.new_balance);
        setTimeout(function(){
          closeTopupSheet();
          var inp = document.getElementById('sheetCardCode');
          if (inp) inp.value = '';
          msg.textContent = '';
          btn.disabled = false;
          btn.innerHTML = '<i class="fas fa-bolt"></i> شحن الرصيد';
          btn.style.background = '';
        }, 2500);
      } else {
        msg.style.color = '#ff4455';
        msg.textContent = '❌ ' + (d.error || 'كود غير صحيح');
      }
    } catch(e) {
      msg.style.color = '#ff4455';
      msg.textContent = '❌ ' + (xhr.responseText ? xhr.responseText.substring(0,80) : 'خطأ غير معروف');
    }
  };
  xhr.onerror = function() {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-bolt"></i> شحن الرصيد';
    msg.style.color = '#ff4455';
    msg.textContent = '❌ تعذر الاتصال بالخادم';
  };
  xhr.send(fd);
}

function formatCardCode(el) {
  // تنسيق تلقائي XXXXXXXX-XXXXXXXX-XXXXXXXX
  var v = el.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
  var formatted = '';
  for (var i=0; i<v.length && i<24; i++) {
    if (i===8 || i===16) formatted += '-';
    formatted += v[i];
  }
  el.value = formatted;
}
async function redeemCard() {
  var code = document.getElementById('cardCodeInput').value.trim();
  var msg  = document.getElementById('cardRedeemMsg');
  var btn  = document.getElementById('cardRedeemBtn');
  if (!code || code.replace(/-/g,'').length < 16) {
    msg.style.color = '#ff4455';
    msg.innerHTML = '❌ أدخل كود البطاقة كاملاً';
    return;
  }
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ التحقق...';
  msg.innerHTML = '';
  try {
    var fd = new FormData();
    fd.append('code', code);
    var r = await fetch('<?= SITE_URL ?>/api/redeem_card.php', {method:'POST',body:fd,credentials:'same-origin'});
    var d = await r.json();
    if (d.ok) {
      msg.style.color = '#00e676';
      msg.innerHTML = '✅ ' + d.message;
      btn.innerHTML = '✓ تم الشحن!';
      btn.style.background = 'linear-gradient(135deg,#00e676,#00c853)';
      // تحديث الرصيد في الواجهة
      updateAllBalances(d.new_balance);
      setTimeout(closeCardRedeem, 2500);
    } else {
      msg.style.color = '#ff4455';
      msg.innerHTML = '❌ ' + (d.error || 'كود غير صحيح');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-bolt"></i> شحن الرصيد';
    }
  } catch(e) {
    msg.style.color = '#ff4455';
    msg.innerHTML = '❌ خطأ في الاتصال';
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-bolt"></i> شحن الرصيد';
  }
}
</script>

<script>
// ═══════════════════════════════════════════════
// Web Push Notifications
// ═══════════════════════════════════════════════
const VAPID_PUBLIC_KEY = 'BPTgBFW3uX0C_e9UAVPg_K_vi8ydvg91HT-_DzTi__2AXfjT-7g0_cDiqy10lSupwDLbAE4sY2SdIL7Nh8CjD_E';

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  return Uint8Array.from(Array.from(rawData).map(c => c.charCodeAt(0)));
}

// ══ بانر طلب الإشعارات ══
function showPushBanner(swReg) {
  if (!('PushManager' in window)) return;
  if (Notification.permission === 'granted') {
    // مسجّل بالفعل — تأكد من الاشتراك
    setupPushNotifications(swReg);
    return;
  }
  if (Notification.permission === 'denied') return;
  if (localStorage.getItem('push_dismissed')) return;

  // أنشئ البانر
  const banner = document.createElement('div');
  banner.id = 'pushBanner';
  banner.innerHTML = `
    <div style="position:fixed;bottom:70px;left:50%;transform:translateX(-50%);width:calc(100% - 32px);max-width:398px;background:linear-gradient(135deg,#1e3a5f,#0d1f3c);border:1px solid rgba(30,111,255,.4);border-radius:16px;padding:14px 14px 14px 16px;z-index:9999;box-shadow:0 8px 32px rgba(0,0,0,.5);display:flex;align-items:center;gap:12px;animation:slideUp .3s ease">
      <div style="font-size:1.8rem;flex-shrink:0">🔔</div>
      <div style="flex:1">
        <div style="font-weight:800;font-size:.88rem;color:#fff">فعّل الإشعارات</div>
        <div style="font-size:.75rem;color:#8fa3bf;margin-top:2px">احصل على تحديثات طلباتك فوراً</div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <button onclick="enablePush()" style="padding:7px 14px;background:var(--primary);border:none;border-radius:10px;color:#fff;font-family:var(--font);font-size:.8rem;font-weight:800;cursor:pointer">تفعيل</button>
        <button onclick="dismissPushBanner()" style="padding:7px 10px;background:rgba(255,255,255,.1);border:none;border-radius:10px;color:#8fa3bf;font-family:var(--font);font-size:.8rem;cursor:pointer">✕</button>
      </div>
    </div>`;
  document.body.appendChild(banner);
}

function dismissPushBanner() {
  const b = document.getElementById('pushBanner');
  if (b) b.remove();
  localStorage.setItem('push_dismissed', '1');
}

async function enablePush() {
  const b = document.getElementById('pushBanner');
  if (b) b.remove();

  try {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return;
    const swReg = window._swReg || await navigator.serviceWorker.ready;
    await setupPushNotifications(swReg);
    showToast('✅ تم تفعيل الإشعارات!', 'success');
  } catch(e) {}
}

async function setupPushNotifications(swReg) {
  try {
    const existing = await swReg.pushManager.getSubscription();
    if (existing) { await savePushSubscription(existing); return; }

    const sub = await swReg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
    });
    await savePushSubscription(sub);
  } catch(e) {}
}

async function savePushSubscription(sub) {
  try {
    await fetch('<?= SITE_URL ?>/api/push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sub.toJSON()),
      credentials: 'same-origin'
    });
  } catch(e) {}
}

// ══════════════ Referral Sheet ══════════════

let _refSheetOpen = false;

async function openRefSheet(userId, maskedName, totalEarned) {
  _refSheetOpen = true;
  const sheet = document.getElementById('refSheet');
  const bg    = document.getElementById('refSheetBg');
  const panel = document.getElementById('refSheetPanel');
  const body  = document.getElementById('refSheetBody');

  // إظهار
  sheet.style.pointerEvents = 'all';
  bg.style.opacity    = '1';
  panel.style.transform = 'translateY(0)';

  // Header
  document.getElementById('refSheetName').textContent  = maskedName;
  document.getElementById('refSheetTotal').textContent = '+' + totalEarned + '$';

  // Loading
  body.innerHTML = '<div style="padding:3rem;text-align:center;color:var(--text3)"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i></div>';

  // جلب البيانات
  try {
    const fd = new FormData();
    fd.append('referred_id', userId);
    const r = await fetch('<?= SITE_URL ?>/api/referral_sheet.php', {method:'POST', body:fd, credentials:'same-origin'});
    const d = await r.json();

    if (!d.ok || !d.rows || d.rows.length === 0) {
      body.innerHTML = '<div style="padding:3rem;text-align:center;color:var(--text3)"><i class="fas fa-receipt" style="font-size:2rem;opacity:.15;display:block;margin-bottom:10px"></i>لا توجد عمليات بعد</div>';
      return;
    }

    // بناء الجدول
    let html = '';
    d.rows.forEach((row, i) => {
      const even = i % 2 === 0;
      html += `
        <div style="display:grid;grid-template-columns:1.1fr 1.7fr 1fr 1fr;padding:10px 14px;font-size:.76rem;border-bottom:1px solid rgba(255,255,255,.04);background:${even?'transparent':'rgba(255,255,255,.02)'};align-items:center">
          <div style="font-family:monospace;font-size:.65rem;color:var(--text3)">${row.order_num}</div>
          <div style="font-weight:700;color:var(--text)">${row.service_masked}</div>
          <div style="text-align:center">
            <div style="color:var(--text2)">${row.order_amount}$</div>
            <div style="font-size:.6rem;color:var(--text3)">${row.percent}%</div>
          </div>
          <div style="text-align:left">
            <div style="font-weight:900;color:#00e676">+${row.commission}$</div>
            <div style="font-size:.6rem;color:var(--text3)">${row.date}</div>
          </div>
        </div>`;
    });

    body.innerHTML = html;

    // Footer
    const footer = document.getElementById('refSheetFooter');
    footer.style.display = 'flex';
    document.getElementById('refSheetFooterTotal').textContent = '+' + d.total + '$';

  } catch(e) {
    body.innerHTML = '<div style="padding:2rem;text-align:center;color:#ff8898"><i class="fas fa-exclamation-circle"></i> خطأ في التحميل</div>';
  }
}

function closeRefSheet() {
  if (!_refSheetOpen) return;
  _refSheetOpen = false;
  const sheet = document.getElementById('refSheet');
  const bg    = document.getElementById('refSheetBg');
  const panel = document.getElementById('refSheetPanel');
  bg.style.opacity      = '0';
  panel.style.transform = 'translateY(100%)';
  setTimeout(() => {
    sheet.style.pointerEvents = 'none';
    document.getElementById('refSheetFooter').style.display = 'none';
  }, 350);
}

// ══════════════════════════════════════════════════════
//  إدارة الأجهزة المصرّحة
// ══════════════════════════════════════════════════════
const DEVICES_API = SITE_URL + '/api/user_devices.php';

async function loadUserDevices() {
  const list = document.getElementById('devicesList');
  if (!list) return;
  try {
    const r = await fetch(DEVICES_API + '?action=list', {credentials:'same-origin'});
    const d = await r.json();
    if (!d.ok || !d.devices?.length) {
      list.innerHTML = '<div style="text-align:center;padding:12px;color:var(--text3);font-size:12px"><i class="fas fa-desktop"></i><br>لا توجد أجهزة مسجّلة</div>';
      return;
    }
    list.innerHTML = d.devices.map(dev => {
      const isBlocked = dev.status === 'blocked';
      const isCurrent = dev.device_fingerprint === getDeviceId();
      const icon = dev.device_type === 'mobile' ? 'fa-mobile-alt' : dev.device_type === 'tablet' ? 'fa-tablet-alt' : 'fa-desktop';
      const statusColor = dev.status === 'approved' ? '#00d4aa' : dev.status === 'pending' ? '#f5a623' : '#ff4455';
      const statusLabel = dev.status === 'approved' ? 'مصرّح' : dev.status === 'pending' ? 'معلّق' : 'محظور';
      return `<div style="background:var(--bg2);border:1.5px solid ${isCurrent?'var(--primary)':'var(--border2)'};border-radius:12px;padding:12px 14px;margin-bottom:8px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
          <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
            <div style="width:36px;height:36px;background:rgba(108,63,224,.12);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="fas ${icon}" style="color:#a78bfa;font-size:15px"></i>
            </div>
            <div style="min-width:0">
              <div style="font-size:12px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                ${dev.device_name || 'جهاز غير معروف'}${isCurrent ? ' <span style="color:var(--primary);font-size:10px">(هذا الجهاز)</span>' : ''}
              </div>
              <div style="font-size:10px;color:var(--text3);margin-top:2px;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${dev.device_fingerprint}</div>
              <div style="font-size:10px;color:var(--text3);margin-top:2px">${dev.last_seen ? 'آخر دخول: ' + dev.last_seen : ''}</div>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0">
            <span style="font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;background:${statusColor}22;color:${statusColor}">${statusLabel}</span>
            ${!isCurrent ? `<button onclick="toggleDeviceBlock(${dev.id}, '${dev.status}')"
              style="font-size:10px;padding:4px 10px;border-radius:8px;border:1px solid ${isBlocked?'rgba(0,212,170,.3)':'rgba(255,68,85,.3)'};background:${isBlocked?'rgba(0,212,170,.08)':'rgba(255,68,85,.08)'};color:${isBlocked?'#00d4aa':'#ff4455'};cursor:pointer;font-family:var(--font);font-weight:700">
              <i class="fas ${isBlocked?'fa-unlock':'fa-ban'}"></i> ${isBlocked?'رفع الحظر':'حظر'}
            </button>` : ''}
          </div>
        </div>
      </div>`;
    }).join('');
  } catch(e) {
    list.innerHTML = '<div style="text-align:center;padding:12px;color:#ff4455;font-size:12px">خطأ في تحميل الأجهزة</div>';
  }
}

async function toggleDeviceBlock(deviceId, currentStatus) {
  const action = currentStatus === 'blocked' ? 'unblock' : 'block';
  const label  = action === 'block' ? 'حظر هذا الجهاز؟' : 'رفع الحظر عن هذا الجهاز؟';
  if (!confirm(label)) return;
  try {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('device_id', deviceId);
    const r = await fetch(DEVICES_API, {method:'POST', body:fd, credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) { showToast(d.msg, 'success'); loadUserDevices(); }
    else showToast(d.msg || 'حدث خطأ', 'error');
  } catch(e) { showToast('خطأ في الاتصال', 'error'); }
}

async function approveNewDevice() {
  const inp = document.getElementById('newDeviceIdInput');
  const did = inp?.value.trim();
  if (!did) { showToast('أدخل رقم الجهاز', 'warning'); return; }
  if (!did.startsWith('did_') && !did.startsWith('ua_')) {
    showToast('رقم الجهاز غير صحيح — يبدأ بـ did_', 'error'); return;
  }
  try {
    const fd = new FormData();
    fd.append('action', 'approve_by_fingerprint');
    fd.append('fingerprint', did);
    const r = await fetch(DEVICES_API, {method:'POST', body:fd, credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) { showToast(d.msg, 'success'); inp.value = ''; loadUserDevices(); }
    else showToast(d.msg || 'حدث خطأ', 'error');
  } catch(e) { showToast('خطأ في الاتصال', 'error'); }
}

// تحميل الأجهزة عند فتح صفحة الإعدادات
const _origNavTo = typeof navTo === 'function' ? navTo : null;

// ══════════════ Push Toggle في صفحة الإعدادات ══════════════

function setPushToggleUI(enabled, statusText, color) {
  const track = document.getElementById('pushToggleTrack');
  const thumb = document.getElementById('pushToggleThumb');
  const dot   = document.getElementById('pushDot');
  const txt   = document.getElementById('pushStatusText');
  const chk   = document.getElementById('pushToggle');
  if (!track) return;

  chk.checked = enabled;
  track.style.background = enabled ? '#f5a623' : 'var(--border2)';
  thumb.style.transform  = enabled ? 'translateX(-22px)' : 'translateX(0)';
  dot.style.color        = color || (enabled ? '#00e676' : 'var(--text3)');
  if (txt && statusText) txt.textContent = statusText;
}

function showPushMsg(text, type) {
  const el = document.getElementById('pushMsg');
  if (!el) return;
  el.style.display = 'block';
  el.style.background = type === 'success' ? 'rgba(0,230,118,.12)' : 'rgba(255,68,85,.12)';
  el.style.color      = type === 'success' ? '#00e676' : '#ff8898';
  el.style.border     = '1px solid ' + (type === 'success' ? 'rgba(0,230,118,.3)' : 'rgba(255,68,85,.3)');
  el.innerHTML = (type === 'success' ? '<i class="fas fa-check-circle"></i> ' : '<i class="fas fa-exclamation-circle"></i> ') + text;
  setTimeout(() => { el.style.display = 'none'; }, 4000);
}

async function initPushToggle() {
  const denied = document.getElementById('pushDeniedMsg');
  if (!('Notification' in window) || !('serviceWorker' in navigator)) {
    setPushToggleUI(false, 'غير مدعوم في هذا المتصفح', '#ff8898');
    document.getElementById('pushToggle').disabled = true;
    return;
  }

  const perm = Notification.permission;
  if (perm === 'denied') {
    setPushToggleUI(false, 'محظور — راجع إعدادات المتصفح', '#ff4455');
    if (denied) denied.style.display = 'block';
    document.getElementById('pushToggle').disabled = true;
    return;
  }

  try {
    const swReg = await navigator.serviceWorker.ready;
    const existing = await swReg.pushManager.getSubscription();
    if (existing) {
      setPushToggleUI(true, 'مفعّل ✅', '#00e676');
    } else {
      setPushToggleUI(false, 'غير مفعّل', 'var(--text3)');
    }
  } catch(e) {
    setPushToggleUI(false, 'غير مفعّل', 'var(--text3)');
  }
}

async function handlePushToggle(wantEnabled) {
  const denied = document.getElementById('pushDeniedMsg');
  if (denied) denied.style.display = 'none';

  if (!wantEnabled) {
    // إلغاء الاشتراك
    try {
      const swReg = await navigator.serviceWorker.ready;
      const sub   = await swReg.pushManager.getSubscription();
      if (sub) await sub.unsubscribe();
      setPushToggleUI(false, 'تم إلغاء التفعيل', 'var(--text3)');
      showPushMsg('تم إيقاف الإشعارات الخلفية', 'error');
    } catch(e) {
      setPushToggleUI(false, 'غير مفعّل', 'var(--text3)');
    }
    return;
  }

  // طلب الإذن والاشتراك
  setPushToggleUI(false, 'جارٍ التفعيل...', '#f5a623');

  const perm = await Notification.requestPermission();
  if (perm !== 'granted') {
    if (perm === 'denied') {
      setPushToggleUI(false, 'محظور — راجع إعدادات المتصفح', '#ff4455');
      if (denied) denied.style.display = 'block';
      document.getElementById('pushToggle').disabled = true;
    } else {
      setPushToggleUI(false, 'غير مفعّل', 'var(--text3)');
    }
    return;
  }

  try {
    const swReg = window._swReg || await navigator.serviceWorker.ready;
    let sub = await swReg.pushManager.getSubscription();
    if (!sub) {
      sub = await swReg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
      });
    }
    await savePushSubscription(sub);
    setPushToggleUI(true, 'مفعّل ✅', '#00e676');
    showPushMsg('تم تفعيل الإشعارات الخلفية بنجاح! 🔔', 'success');
    localStorage.removeItem('push_dismissed');
  } catch(e) {
    setPushToggleUI(false, 'فشل التفعيل — حاول مجدداً', '#ff8898');
    showPushMsg('فشل التفعيل: ' + (e.message || 'خطأ غير معروف'), 'error');
  }
}

// تهيئة الـ toggle عند فتح صفحة الإعدادات
document.addEventListener('DOMContentLoaded', () => {
  // نهيئ عند أول مرة تُفتح صفحة الإعدادات
  const settingsNavBtns = document.querySelectorAll('[onclick*="settings"], [data-page="settings"]');
  settingsNavBtns.forEach(btn => {
    btn.addEventListener('click', () => setTimeout(initPushToggle, 100));
  });
  // أو إذا كانت الصفحة مفتوحة مسبقاً
  if (document.getElementById('page-settings')?.classList.contains('active')) {
    initPushToggle();
  }
});

// ══════════════════════════════════════════════════════════════
// NumbersApp Live — شراء رقم مباشر (بدون اختيار دولة)
// ══════════════════════════════════════════════════════════════
var _naLive = {
  svc: null, orderId: 0, number: '', accessId: '', code: '',
  timer: 300, timerInterval: null, autoCheckInterval: null,
  autoCheck: false, step: 'idle', refund: null
};

function openNumbersAppLive(svc) {
  if (!<?= isLoggedIn() ? 'true' : 'false' ?>) { openAuth('login'); return; }
  _naLive = { svc:svc, orderId:0, number:'', accessId:'', code:'',
              timer:300, timerInterval:null, autoCheckInterval:null,
              autoCheck:false, step:'confirm', refund:null };
  _naRender();
  document.getElementById('naLiveOverlay').style.display = 'block';
  var sheet = document.getElementById('naLiveSheet');
  sheet.style.display = 'block';
  requestAnimationFrame(function(){ sheet.style.transform = 'translateX(-50%) translateY(0)'; });
  document.body.style.overflow = 'hidden';
}

function closeNumbersAppLive() {
  clearInterval(_naLive.timerInterval);
  clearInterval(_naLive.autoCheckInterval);
  var sheet = document.getElementById('naLiveSheet');
  sheet.style.transform = 'translateX(-50%) translateY(105%)';
  document.getElementById('naLiveOverlay').style.display = 'none';
  setTimeout(function(){ sheet.style.display = 'none'; }, 400);
  document.body.style.overflow = '';
}
document.getElementById('naLiveOverlay').addEventListener('click', function(){
  if (_naLive.step === 'confirm' || _naLive.step === 'code_received' || _naLive.step === 'cancelled' || _naLive.step === 'error') closeNumbersAppLive();
});

function _naConfirmBuy() {
  _naLive.step = 'buying';
  _naRender();
  _naBuyDirect(_naLive.svc.id);
}

async function _naBuyDirect(serviceId) {
  try { playSuccessSound(); } catch(e){}
  try {
    var fd = new FormData();
    fd.append('action', 'buy');
    fd.append('service_id', serviceId);
    var r = await fetch(SITE_URL + '/api/numbersapp_live.php', {method:'POST', body:fd, credentials:'same-origin'});
    var d = await r.json();
    if (d.ok) {
      _naLive.orderId = d.order_id; _naLive.number = d.number;
      _naLive.accessId = d.access_id; _naLive.step = 'waiting';
      _naLive.timer = 300; _naLive.autoCheck = true;
      if (d.new_balance !== undefined) {
        updateAllBalances(d.new_balance);
      }
      _naStartTimer(); _naStartAutoCheck();
      try { launchConfetti(); triggerVibration([80, 40, 180]); } catch(e){}
    } else {
      _naLive.step = 'error'; _naLive.errorMsg = d.error || 'فشل الشراء';
      try { triggerVibration([100, 50, 100]); } catch(e){}
    }
  } catch(e) { _naLive.step = 'error'; _naLive.errorMsg = 'خطأ في الاتصال'; }
  _naRender();
}
function _naStartTimer() {
  clearInterval(_naLive.timerInterval);
  _naLive.timerInterval = setInterval(function(){
    _naLive.timer--;
    var el = document.getElementById('naTimerText');
    if (el) { var m=Math.floor(_naLive.timer/60), s=_naLive.timer%60; el.textContent = m+':'+String(s).padStart(2,'0'); }
    var circle = document.getElementById('naTimerCircle');
    if (circle) { var pct=_naLive.timer/300, circ=2*Math.PI*52; circle.style.strokeDashoffset = circ*(1-pct); circle.style.stroke = _naLive.timer>60?'#00e676':_naLive.timer>30?'#f5a623':'#ff4757'; }
    if (_naLive.timer <= 0) {
      clearInterval(_naLive.timerInterval);
      clearInterval(_naLive.autoCheckInterval);
      _naLive.autoCheck = false;
      showToast('انتهى الوقت — جارٍ الإلغاء التلقائي...', 'warning');
      _naRender();
      // إلغاء تلقائي من الـ backend
      _naAutoCancel(_naLive.orderId);
    }
  }, 1000);
}

function _naStartAutoCheck() {
  clearInterval(_naLive.autoCheckInterval);
  if (!_naLive.autoCheck) return;
  _naLive.autoCheckInterval = setInterval(function(){ _naCheckCode(true); }, 5000);
}

async function _naCheckCode(silent) {
  if (_naLive.step !== 'waiting') return;
  if (!silent) { var b=document.getElementById('naCheckBtn'); if(b) b.innerHTML='<i class="fas fa-spinner fa-spin"></i> فحص...'; }
  try {
    var r = await fetch(SITE_URL + '/api/numbersapp_live.php?action=check&order_id=' + _naLive.orderId, {credentials:'same-origin'});
    var d = await r.json();
    if (d.ok && d.status === 'completed' && d.code) {
      _naLive.code = d.code; _naLive.step = 'code_received'; _naLive.autoCheck = false;
      clearInterval(_naLive.timerInterval); clearInterval(_naLive.autoCheckInterval);
      try { playSuccessSound(); launchConfetti(); triggerVibration([80,40,180,40,80]); } catch(e){}
      _naRender(); return;
    }
    if (!silent) { var msg=document.getElementById('naWaitMsg'); if(msg){msg.style.display='block';msg.textContent='⏳ لم يصل الكود بعد...';setTimeout(function(){msg.style.display='none';},2500);} }
  } catch(e) {}
  if (!silent) { var b2=document.getElementById('naCheckBtn'); if(b2) b2.innerHTML='<i class="fas fa-search"></i> فحص الكود'; }
}

async function _naCancel() {
  var btn=document.getElementById('naCancelBtn'); if(btn) btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> جاري الإلغاء...';
  try {
    var fd = new FormData(); fd.append('action','cancel'); fd.append('order_id',_naLive.orderId);
    var r = await fetch(SITE_URL + '/api/numbersapp_live.php', {method:'POST', body:fd, credentials:'same-origin'});
    var d = await r.json();
    if (d.ok) {
      _naLive.step='cancelled'; _naLive.refund=d.refund; _naLive.autoCheck=false;
      clearInterval(_naLive.timerInterval); clearInterval(_naLive.autoCheckInterval);
      if (d.new_balance !== undefined) {
        updateAllBalances(d.new_balance);
      }
    } else showToast(d.error||'خطأ','error');
  } catch(e) { showToast('خطأ في الاتصال','error'); }
  _naRender();
}

// _naToggleAutoCheck removed — auto-check always on

function _naCopy(text, btnId) {
  if (navigator.clipboard) navigator.clipboard.writeText(text);
  else { var t=document.createElement('textarea');t.value=text;document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t); }
  var btn=document.getElementById(btnId);
  if(btn){var o=btn.innerHTML;btn.innerHTML='<i class="fas fa-check"></i> تم!';btn.style.color='#00e676';setTimeout(function(){btn.innerHTML=o;btn.style.color='';},1500);}
  showToast('تم النسخ','success');
}

function _naRender() {
  var c=document.getElementById('naLiveContent'); if(!c) return;
  var na=_naLive, svc=na.svc||{}, G='#00e676', C='#00d4ff', R='#ff4757';

  // ── تأكيد الشراء ──
  if (na.step==='confirm') {
    var svcImg = svc.image ? SITE_URL+'/'+svc.image : '';
    var catImg = svc.cat_image ? SITE_URL+'/'+svc.cat_image : '';
    var useImg = svcImg || catImg;
    var desc = svc.description || '';
    var priceText = _ptag(svc.price);
    c.innerHTML=''
      +'<div style="padding:16px 20px 10px;display:flex;align-items:center;justify-content:space-between">'
        +'<div style="font-size:15px;font-weight:900">📱 شراء رقم</div>'
        +'<button onclick="closeNumbersAppLive()" style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);color:#8fa3bf;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fas fa-times" style="font-size:11px"></i></button>'
      +'</div>'
      // بطاقة الخدمة
      +'<div style="margin:0 16px 14px;background:linear-gradient(135deg,#111827,#161d2e);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:18px;text-align:center">'
        // صورة
        +(useImg ? '<div style="width:64px;height:64px;border-radius:16px;overflow:hidden;margin:0 auto 12px;border:2px solid rgba(255,255,255,.1)"><img src="'+useImg+'" style="width:100%;height:100%;object-fit:cover"></div>' : '<div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,'+G+'22,'+C+'22);border:1px solid '+G+'33;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:28px">📱</div>')
        // اسم الخدمة
        +'<div style="font-size:17px;font-weight:900;margin-bottom:4px">'+escH(svc.name||'')+'</div>'
        +'<div style="font-size:11px;color:#5a6880;margin-bottom:14px">'+escH(svc.cat_name||'')+'</div>'
        // السعر
        +'<div style="background:rgba(0,230,118,.08);border:1.5px solid rgba(0,230,118,.25);border-radius:14px;padding:14px;margin-bottom:'+(desc?'14':'0')+'px">'
          +'<div style="font-size:10px;color:#8fa3bf;margin-bottom:4px">سعر الخدمة</div>'
          +'<div style="font-size:28px;font-weight:900;color:'+G+'">'+priceText+'</div>'
        +'</div>'
        // وصف / ملاحظة
        +(desc ? '<div style="background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.15);border-radius:12px;padding:12px 14px;text-align:right"><div style="font-size:10px;color:'+C+';font-weight:700;margin-bottom:4px"><i class="fas fa-info-circle" style="margin-left:4px"></i> تفاصيل</div><div style="font-size:13px;color:#c8d6e5;line-height:1.8">'+escH(desc)+'</div></div>' : '')
      +'</div>'
      // زر إكمال الشراء
      +'<div style="padding:0 16px 20px">'
        +'<button onclick="_naConfirmBuy()" style="width:100%;padding:16px;border-radius:16px;border:none;background:linear-gradient(135deg,#00c853,'+G+');color:#fff;font-size:16px;font-weight:900;font-family:inherit;cursor:pointer;box-shadow:0 8px 30px '+G+'40;display:flex;align-items:center;justify-content:center;gap:10px"><i class="fas fa-bolt"></i> إكمال الشراء</button>'
        +'<button onclick="closeNumbersAppLive()" style="width:100%;padding:12px;margin-top:8px;border-radius:14px;border:1px solid rgba(255,255,255,.07);background:transparent;color:#8fa3bf;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer">إلغاء</button>'
      +'</div>';
  }

  // ── جاري الشراء ──
  else if (na.step==='buying') {
    c.innerHTML='<div style="padding:40px 16px;text-align:center">'
      +'<div style="width:70px;height:70px;margin:0 auto 18px;background:linear-gradient(135deg,'+G+'22,'+C+'22);border:1.5px solid '+G+'33;border-radius:50%;display:flex;align-items:center;justify-content:center"><i class="fas fa-spinner fa-spin" style="font-size:28px;color:'+G+'"></i></div>'
      +'<div style="font-size:17px;font-weight:900;margin-bottom:6px">جاري شراء الرقم...</div>'
      +'<div style="font-size:12px;color:#8fa3bf">'+escH(svc.name||'')+'</div>'
      +'</div>';
  }

  // ── خطأ ──
  else if (na.step==='error') {
    c.innerHTML='<div style="padding:30px 16px;text-align:center">'
      +'<div style="width:70px;height:70px;margin:0 auto 16px;background:'+R+'15;border:2px solid '+R+'33;border-radius:50%;display:flex;align-items:center;justify-content:center"><i class="fas fa-exclamation-triangle" style="font-size:28px;color:'+R+'"></i></div>'
      +'<div style="font-size:16px;font-weight:900;margin-bottom:8px;color:'+R+'">فشل الشراء</div>'
      +'<div style="font-size:13px;color:#8fa3bf;margin-bottom:20px">'+(na.errorMsg||'')+'</div>'
      +'<button onclick="closeNumbersAppLive()" style="width:100%;padding:13px;border-radius:14px;border:1px solid rgba(255,255,255,.07);background:#111827;color:#8fa3bf;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer"><i class="fas fa-times" style="margin-left:6px"></i> إغلاق</button>'
      +'</div>';
  }

  // ── انتظار الكود ──
  else if (na.step==='waiting') {
    var maxTime = na.resumed ? 1200 : 300;
    var pct=na.timer/maxTime, circ=2*Math.PI*52, off=circ*(1-pct), tc=na.timer>120?G:na.timer>60?'#f5a623':R;
    var m=Math.floor(na.timer/60), s=na.timer%60;
    var resumeBadge = na.resumed
      ? '<div style="margin:0 16px 10px;background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.25);border-radius:10px;padding:8px 12px;display:flex;align-items:center;gap:8px"><i class="fas fa-history" style="color:#f5a623;font-size:13px"></i><span style="font-size:11px;font-weight:700;color:#f5a623">استعادة طلب معلق — الوقت المتبقي</span></div>'
      : '';
    c.innerHTML='<div style="padding:14px 20px 6px;display:flex;align-items:center;justify-content:space-between"><div style="font-size:14px;font-weight:900"><i class="fas fa-phone-alt" style="color:'+G+';margin-left:6px"></i> '+escH(svc.name||'تم شراء الرقم')+'</div><button onclick="closeNumbersAppLive()" style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.06);border:none;color:#8fa3bf;cursor:pointer"><i class="fas fa-times" style="font-size:10px"></i></button></div>'
    +resumeBadge
    // الرقم
    +'<div style="margin:0 16px 12px;background:linear-gradient(135deg,#111827,#161d2e);border:1px solid '+G+'33;border-radius:18px;padding:16px;text-align:center;position:relative;overflow:hidden"><div style="position:absolute;top:-40px;left:50%;transform:translateX(-50%);width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,'+G+'12,transparent 70%);pointer-events:none"></div><div style="font-size:10px;color:#8fa3bf;margin-bottom:5px;font-weight:700"><i class="fas fa-sim-card" style="margin-left:4px"></i> الرقم</div><div style="font-size:24px;font-weight:900;letter-spacing:2px;color:'+G+';direction:ltr;font-family:monospace;margin-bottom:8px">'+escH(na.number)+'</div><button id="naCopyNumBtn" onclick="_naCopy(\''+na.number.replace(/\s/g,'')+'\',\'naCopyNumBtn\')" style="padding:6px 20px;border-radius:10px;border:1px solid '+G+'44;background:rgba(255,255,255,.04);color:#8fa3bf;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit"><i class="fas fa-copy" style="margin-left:4px"></i>نسخ</button></div>'
    // المؤقت
    +'<div style="text-align:center;margin-bottom:14px"><div style="position:relative;width:100px;height:100px;margin:0 auto"><svg width="100" height="100" viewBox="0 0 120 120" style="transform:rotate(-90deg)"><circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="6"/><circle id="naTimerCircle" cx="60" cy="60" r="52" fill="none" stroke="'+tc+'" stroke-width="6" stroke-linecap="round" stroke-dasharray="'+circ+'" stroke-dashoffset="'+off+'" style="transition:stroke-dashoffset 1s linear,stroke .5s ease"/></svg><div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center"><div id="naTimerText" style="font-size:22px;font-weight:900;font-family:monospace;color:'+(na.timer>120?'#fff':tc)+'">'+m+':'+String(s).padStart(2,'0')+'</div><div style="font-size:8px;color:#5a6880">متبقي</div></div></div></div>'
    // كود التفعيل
    +'<div style="margin:0 16px 12px;background:#111827;border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:14px"><div style="font-size:11px;color:#8fa3bf;font-weight:700;margin-bottom:8px"><i class="fas fa-key" style="margin-left:4px"></i> كود التفعيل</div><div style="background:#0a0e1a;border:2px dashed '+C+'33;border-radius:12px;padding:16px;text-align:center;margin-bottom:10px;min-height:50px;display:flex;align-items:center;justify-content:center">'+(na.autoCheck?'<div style="display:flex;align-items:center;gap:8px;color:#5a6880"><i class="fas fa-circle-notch fa-spin" style="color:'+C+'"></i><span style="font-size:12px;font-weight:700">بانتظار الكود...</span></div>':'<div style="color:#5a6880;font-size:12px">اضغط فحص الكود</div>')+'</div><div id="naWaitMsg" style="display:none;text-align:center;padding:5px;margin-bottom:6px;border-radius:8px;background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.2);font-size:11px;color:#f5a623;font-weight:700"></div><div style="display:flex;gap:8px"><button id="naCheckBtn" onclick="_naCheckCode(false)" style="width:100%;padding:11px;border-radius:12px;border:none;background:linear-gradient(135deg,'+C+',#0066ff);color:#fff;font-size:13px;font-weight:800;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 4px 14px '+C+'30"><i class="fas fa-search"></i> فحص الكود</button></div>'+(na.autoCheck?'<div style="margin-top:6px;padding:4px;border-radius:8px;background:'+G+'0d;border:1px solid '+G+'22;font-size:9px;color:'+G+';font-weight:700;text-align:center"><i class="fas fa-sync fa-spin" style="font-size:8px;margin-left:3px"></i> فحص تلقائي كل 5 ثوانٍ</div>':'')+'</div>'
    // إلغاء
    var canCancel = (na.timer <= 0);
    var cancelBtn = canCancel
      ? '<button id="naCancelBtn" onclick="_naCancel()" style="width:100%;padding:12px;border-radius:14px;border:1.5px solid '+R+'33;background:'+R+'10;color:'+R+';font-size:12px;font-weight:800;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px"><i class="fas fa-times-circle"></i> إلغاء واسترداد</button>'
      : '<div style="width:100%;padding:12px;border-radius:14px;border:1.5px solid rgba(255,255,255,.07);background:rgba(255,255,255,.02);color:#5a6880;font-size:11px;font-weight:700;text-align:center;box-sizing:border-box"><i class="fas fa-lock" style="margin-left:5px"></i> الإلغاء متاح بعد انتهاء الوقت بدون استلام كود</div>';
    c.innerHTML += '<div style="padding:0 16px 18px">'+cancelBtn+'</div>';
  }

  // ── تم استلام الكود ──
  else if (na.step==='code_received') {
    c.innerHTML='<div style="padding:18px 16px;text-align:center"><div style="width:80px;height:80px;margin:0 auto 14px;background:linear-gradient(135deg,'+G+'25,'+G+'08);border:2px solid '+G+'50;border-radius:50%;display:flex;align-items:center;justify-content:center;animation:_naPop .5s cubic-bezier(.175,.885,.32,1.275)"><i class="fas fa-check-circle" style="font-size:38px;color:'+G+'"></i></div><div style="font-size:20px;font-weight:900;margin-bottom:4px">تم استلام الكود! 🎉</div><div style="font-size:11px;color:#8fa3bf;margin-bottom:16px">'+escH(svc.name||'')+'</div>'
    // الرقم
    +'<div style="background:#111827;border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:10px 14px;margin-bottom:8px;display:flex;align-items:center"><div style="flex:1;text-align:right"><div style="font-size:8px;color:#5a6880">الرقم</div><div style="font-size:13px;font-weight:800;direction:ltr;color:#8fa3bf;font-family:monospace">'+escH(na.number)+'</div></div><button id="naCopyNum2" onclick="_naCopy(\''+na.number.replace(/\s/g,'')+'\',\'naCopyNum2\')" style="padding:4px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.04);color:#8fa3bf;font-size:10px;cursor:pointer;font-family:inherit"><i class="fas fa-copy"></i></button></div>'
    // الكود
    +'<div style="background:linear-gradient(135deg,'+G+'12,'+C+'08);border:2px solid '+G+'44;border-radius:18px;padding:20px 16px;margin-bottom:14px"><div style="font-size:10px;color:'+G+';font-weight:700;margin-bottom:6px"><i class="fas fa-key" style="margin-left:4px"></i> كود التفعيل</div><div style="font-size:38px;font-weight:900;letter-spacing:10px;color:#fff;font-family:monospace;direction:ltr;text-shadow:0 0 30px '+G+'40;margin-bottom:10px">'+escH(na.code)+'</div><button id="naCopyCode" onclick="_naCopy(\''+na.code+'\',\'naCopyCode\')" style="padding:10px 26px;border-radius:12px;border:none;background:linear-gradient(135deg,'+G+',#00c853);color:#fff;font-size:14px;font-weight:800;font-family:inherit;cursor:pointer;box-shadow:0 4px 16px '+G+'40"><i class="fas fa-copy" style="margin-left:6px"></i> نسخ الكود</button></div>'
    +'<button onclick="closeNumbersAppLive()" style="width:100%;padding:13px;border-radius:14px;border:1px solid rgba(255,255,255,.07);background:#111827;color:#8fa3bf;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer"><i class="fas fa-check" style="margin-left:6px"></i> تم</button></div>';
  }

  // ── تم الإلغاء ──
  else if (na.step==='cancelled') {
    c.innerHTML='<div style="padding:18px 16px;text-align:center"><div style="width:75px;height:75px;margin:14px auto;background:'+R+'15;border:2px solid '+R+'33;border-radius:50%;display:flex;align-items:center;justify-content:center"><i class="fas fa-undo" style="font-size:30px;color:'+R+'"></i></div><div style="font-size:18px;font-weight:900;margin-bottom:6px">تم إلغاء الطلب</div>'+(na.refund?'<div style="background:'+G+'12;border:1px solid '+G+'33;border-radius:14px;padding:12px 16px;margin:12px 0;display:flex;align-items:center;justify-content:center;gap:10px"><i class="fas fa-wallet" style="color:'+G+';font-size:16px"></i><div><div style="font-size:10px;color:#8fa3bf">تم استرداد</div><div style="font-size:18px;font-weight:900;color:'+G+'">'+na.refund+'</div></div></div>':'')+'<button onclick="closeNumbersAppLive()" style="width:100%;padding:13px;border-radius:14px;border:none;background:linear-gradient(135deg,'+C+',#0066ff);color:#fff;font-size:14px;font-weight:800;font-family:inherit;cursor:pointer;margin-top:6px;box-shadow:0 6px 20px '+C+'30"><i class="fas fa-times" style="margin-left:6px"></i> إغلاق</button></div>';
  }
}

// ── استعادة فورية إذا قادم من orders.php ─────────────────────────────────
(function() {
  if (!IS_LOGGED) return;
  var forcedId = sessionStorage.getItem('resume_numbers_order');
  if (!forcedId) return;
  sessionStorage.removeItem('resume_numbers_order');
  _checkPendingNumbersOrder(parseInt(forcedId));
})();
</script>
<style>@keyframes _naPop{0%{transform:scale(.3);opacity:0}60%{transform:scale(1.1)}100%{transform:scale(1);opacity:1}}</style>

<!-- ══ مودال الهدايا (صفحة المحفظة) ══ -->
<div id="walletGiftOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9000;display:flex;align-items:flex-end;justify-content:center;opacity:0;pointer-events:none;transition:opacity .3s;backdrop-filter:blur(8px)" onclick="if(event.target===this)closeWalletGiftDetail()">
  <div id="walletGiftSheet" style="background:#151827;width:100%;max-width:480px;border-radius:24px 24px 0 0;padding:0 0 40px;transform:translateY(100%);transition:transform .35s cubic-bezier(.32,1.2,.72,1)">
    <div style="width:40px;height:4px;background:rgba(255,255,255,.1);border-radius:4px;margin:12px auto 0"></div>
    <div style="margin:20px 20px 0;border-radius:20px;overflow:hidden;background:linear-gradient(135deg,#1a0533,#2d1060,#0d1a2d);border:1px solid rgba(108,63,224,.3);box-shadow:0 20px 60px rgba(0,0,0,.5)">
      <div style="padding:24px;position:relative">
        <div style="font-size:2.5rem;margin-bottom:6px">🎁</div>
        <div id="wgFromLabel" style="font-size:.72rem;color:rgba(255,255,255,.5);margin-bottom:4px"></div>
        <div id="wgPersonName" style="font-size:1rem;font-weight:900;color:#fff;margin-bottom:16px"></div>
        <div style="background:rgba(255,255,255,.08);border-radius:14px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <span style="font-size:.72rem;color:rgba(255,255,255,.5)">المبلغ</span>
          <span id="wgAmount" style="font-size:1.4rem;font-weight:900;color:#f5c842"></span>
        </div>
        <div id="wgMsg" style="background:rgba(255,255,255,.05);border-radius:12px;padding:10px 14px;font-size:.8rem;color:rgba(255,255,255,.7);font-style:italic;border-right:3px solid #ff6b9d;display:none"></div>
      </div>
      <div style="padding:10px 24px;background:rgba(0,0,0,.2);display:flex;align-items:center;justify-content:space-between">
        <span id="wgDate" style="font-size:.68rem;color:rgba(255,255,255,.4)"></span>
        <span id="wgStatus" style="font-size:.68rem;padding:3px 10px;border-radius:20px;font-weight:700"></span>
      </div>
    </div>
    <div style="padding:16px 20px 0">
      <button onclick="closeWalletGiftDetail()" style="width:100%;padding:12px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.5);font-family:inherit;font-size:.85rem;cursor:pointer">إغلاق</button>
    </div>
  </div>
</div>

<script>
function filterWalletTx(type, btn) {
  document.querySelectorAll('.wallet-filter-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  document.querySelectorAll('#walletTxList .wallet-tx-item').forEach(el => {
    el.style.display = (type === 'all' || el.dataset.type === type) ? '' : 'none';
  });
}

function showWalletGiftDetail(giftJson, desc, amount) {
  const overlay = document.getElementById('walletGiftOverlay');
  const sheet   = document.getElementById('walletGiftSheet');
  const sym     = '<?= addslashes($currSymbol) ?>';
  if (giftJson) {
    const g = typeof giftJson === 'string' ? JSON.parse(giftJson) : giftJson;
    const isSender  = g.is_sender;
    document.getElementById('wgFromLabel').textContent  = isSender ? 'هدية إلى' : 'هدية من';
    document.getElementById('wgPersonName').textContent = (isSender ? g.receiver_name : g.sender_name) || '—';
    document.getElementById('wgAmount').textContent     = parseFloat(g.amount).toFixed(2) + ' ' + sym;
    const msgEl = document.getElementById('wgMsg');
    if (g.message) { msgEl.textContent = '"' + g.message + '"'; msgEl.style.display = ''; }
    else { msgEl.style.display = 'none'; }
    const stMap = {pending:'⏳ لم تُفتح',opened:'✅ مفتوحة',rejected:'❌ مرفوضة'};
    const stCol = {pending:'rgba(245,200,66,.15)',opened:'rgba(0,212,170,.12)',rejected:'rgba(255,68,85,.12)'};
    const stTxt = {pending:'#f5c842',opened:'#00d4aa',rejected:'#ff4455'};
    const st = g.status || 'pending';
    const stEl = document.getElementById('wgStatus');
    stEl.textContent = stMap[st] || st;
    stEl.style.background = stCol[st] || 'rgba(255,255,255,.08)';
    stEl.style.color      = stTxt[st] || '#fff';
    document.getElementById('wgDate').textContent = g.created_at ? g.created_at.substring(0,16).replace('T',' ') : '';
  } else {
    document.getElementById('wgFromLabel').textContent  = '🎁';
    document.getElementById('wgPersonName').textContent = desc || 'هدية';
    document.getElementById('wgAmount').textContent     = parseFloat(amount).toFixed(2) + ' ' + sym;
    document.getElementById('wgMsg').style.display = 'none';
    document.getElementById('wgStatus').textContent = '';
    document.getElementById('wgDate').textContent   = '';
  }
  overlay.style.opacity = '1'; overlay.style.pointerEvents = 'all';
  requestAnimationFrame(() => sheet.style.transform = 'translateY(0)');
}

function closeWalletGiftDetail() {
  const overlay = document.getElementById('walletGiftOverlay');
  const sheet   = document.getElementById('walletGiftSheet');
  sheet.style.transform = 'translateY(100%)';
  setTimeout(() => { overlay.style.opacity = '0'; overlay.style.pointerEvents = 'none'; }, 350);
}
</script>
<script>
// ══════════════════════════════════════════════════
// نظام شحن الرصيد عبر SMS — مطابقة فورية
// ══════════════════════════════════════════════════
var _smsProviderId  = 0;
var _smsCurrency    = 'YER';
var _smsRateToUsd   = 0;
var _smsCsrfToken   = '<?= htmlspecialchars($csrfToken ?? "") ?>';
var _smsActiveRegion = 'north'; // المنطقة النشطة افتراضياً

// تهيئة أول مزود عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
  // تفعيل أول تبويب منطقة إن وجد، وإلا أول مزود عادي
  var northTab = document.getElementById('smsTabNorth');
  var southTab = document.getElementById('smsTabSouth');
  if (northTab) {
    switchSmsRegion('north');
  } else if (southTab) {
    switchSmsRegion('south');
  } else {
    var first = document.querySelector('.sms-provider-btn');
    if (first) {
      _smsProviderId = parseInt(first.dataset.id) || 0;
      _smsCurrency   = first.dataset.currency || 'YER';
      _activateSmsBtn(first);
    }
  }
});

function switchSmsRegion(region) {
  _smsActiveRegion = region;
  var northDiv = document.getElementById('smsProviders-north');
  var southDiv = document.getElementById('smsProviders-south');
  var northTab = document.getElementById('smsTabNorth');
  var southTab = document.getElementById('smsTabSouth');

  // إخفاء/إظهار الأقسام
  if (northDiv) northDiv.style.display = region === 'north' ? 'flex' : 'none';
  if (southDiv) southDiv.style.display = region === 'south' ? 'flex' : 'none';

  // تحديث شكل التبويبات
  if (northTab) {
    if (region === 'north') {
      northTab.style.background = '#00c85318';
      northTab.style.borderColor = '#00c853';
      northTab.style.color = '#00c853';
    } else {
      northTab.style.background = 'transparent';
      northTab.style.borderColor = 'var(--border)';
      northTab.style.color = 'var(--text2)';
    }
  }
  if (southTab) {
    if (region === 'south') {
      southTab.style.background = '#1e6fff18';
      southTab.style.borderColor = '#1e6fff';
      southTab.style.color = '#1e6fff';
    } else {
      southTab.style.background = 'transparent';
      southTab.style.borderColor = 'var(--border)';
      southTab.style.color = 'var(--text2)';
    }
  }

  // تفعيل أول مزود في المنطقة الجديدة تلقائياً
  var activeDiv = document.getElementById('smsProviders-' + region);
  if (activeDiv) {
    // إلغاء تفعيل كل الأزرار أولاً
    document.querySelectorAll('.sms-provider-btn').forEach(function(b) {
      b.style.borderColor = 'var(--border)';
      b.style.background  = 'transparent';
      b.style.color       = 'var(--text2)';
      b.classList.remove('active');
    });
    // تفعيل الأول في المنطقة
    var firstBtn = activeDiv.querySelector('.sms-provider-btn');
    if (firstBtn) _activateSmsBtn(firstBtn);
  }
}

function _activateSmsBtn(btn) {
  var color = btn.dataset.color || '#1e6fff';
  btn.style.borderColor = color;
  btn.style.background  = color + '18';
  btn.style.color       = color;
  btn.classList.add('active');
  _smsProviderId = parseInt(btn.dataset.id) || 0;
  _smsCurrency   = btn.dataset.currency || 'YER';
  _smsRateToUsd  = parseFloat(btn.dataset.rate) || 0;
  var lbl = document.getElementById('smsCurrencyLabel');
  if (lbl) lbl.textContent = _smsCurrency;
  calcSmsCredit(); // تحديث المعاينة عند تغيير المزود
}

var _smsProviderType = 'sms';
var _usdtSessionId = null, _usdtTimerInterval = null, _usdtUniqueAmt = null;

function selectSmsProvider(btn) {
  document.querySelectorAll('.sms-provider-btn').forEach(function(b) {
    b.style.borderColor = 'var(--border)';
    b.style.background  = 'transparent';
    b.style.color       = 'var(--text2)';
    b.classList.remove('active');
  });
  _activateSmsBtn(btn);

  var account     = btn.dataset.account || '';
  var accountName = btn.dataset.accountName || '';
  var color       = btn.dataset.color || '#1e6fff';
  var name        = btn.dataset.name || '';
  var ptype       = btn.dataset.ptype || 'sms';

  _smsProviderType = ptype;

  // حقل الهاتف
  var phoneWrap = document.getElementById('smsPhoneInput') ? document.getElementById('smsPhoneInput').closest('div') : null;
  var usdtBox   = document.getElementById('usdtTransferBox');
  var submitLbl = document.getElementById('smsSubmitLbl');
  var submitIcon= document.getElementById('smsSubmitIcon');

  if (ptype === 'usdt') {
    if (phoneWrap) phoneWrap.style.display = 'none';
    if (usdtBox)   usdtBox.style.display = 'none'; // يظهر بعد الضغط على الزر
    if (submitLbl)  submitLbl.textContent = 'طلب عنوان التحويل';
    if (submitIcon) submitIcon.className  = 'fas fa-wallet';
  } else {
    if (phoneWrap) phoneWrap.style.display = '';
    if (usdtBox)   usdtBox.style.display = 'none';
    clearInterval(_usdtTimerInterval);
    if (submitLbl)  submitLbl.textContent = 'تحقق وشحن الرصيد';
    if (submitIcon) submitIcon.className  = 'fas fa-search-dollar';
    if (account || accountName) showSmsTransferPopup(name, account, accountName, color);
  }
}

function copyUsdtWallet() {
  var addr = (document.getElementById('usdtWalletDisplay').textContent || '').trim();
  navigator.clipboard.writeText(addr).then(function(){ showToast('تم نسخ العنوان ✓','success'); });
}
function copyUsdtAmount() {
  var amt = _usdtUniqueAmt ? String(_usdtUniqueAmt) : '';
  navigator.clipboard.writeText(amt).then(function(){ showToast('تم نسخ المبلغ ✓','success'); });
}
function startUsdtTimer(seconds) {
  clearInterval(_usdtTimerInterval);
  var remaining = seconds;
  function tick() {
    var m = Math.floor(remaining/60), s = remaining%60;
    var el = document.getElementById('usdtTimer');
    if (el) { el.textContent = String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); el.style.color = remaining < 120 ? '#ff4455' : remaining < 300 ? '#f5a623' : 'var(--primary)'; }
    if (remaining <= 0) { clearInterval(_usdtTimerInterval); showToast('انتهى وقت الجلسة','error'); document.getElementById('usdtTransferBox').style.display='none'; }
    remaining--;
  }
  tick(); _usdtTimerInterval = setInterval(tick, 1000);
}

function submitSmsTopup() {
  // تحقق KYC
  if (typeof KYC_STATUS !== 'undefined' && KYC_STATUS !== 'approved') {
    _showKycRequiredToast();
    return;
  }

  var amount = (document.getElementById('smsAmountInput').value || '').trim();
  if (!amount || parseFloat(amount) <= 0) {
    _setSmsMsg('يرجى إدخال المبلغ', '#ff4455');
    return;
  }

  // ── USDT: إذا لم تكن جلسة — أنشئ واحدة ──
  if (_smsProviderType === 'usdt') {
    // إذا كانت الجلسة مفتوحة بالفعل — إرسال txID
    if (_usdtSessionId) {
      submitUsdtTx();
      return;
    }
    var btn2 = document.getElementById('smsSubmitBtn');
    btn2.disabled = true; btn2.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإعداد...';
    fetch('<?= SITE_URL ?>/api/usdt_deposit.php', {
      method: 'POST',
      body: new URLSearchParams({ action:'create', method_id: _smsProviderId, amount_local: parseFloat(amount), currency_code: _smsCurrency, csrf_token: _smsCsrfToken })
    }).then(function(r){ return r.json(); }).then(function(d){
      btn2.disabled = false; btn2.innerHTML = '<i class="fas fa-paper-plane" id="smsSubmitIcon"></i> <span id="smsSubmitLbl">إرسال رقم العملية</span>';
      if (!d.ok) { _setSmsMsg(d.msg || 'خطأ في إنشاء الجلسة','#ff4455'); return; }
      _usdtSessionId  = d.session_id;
      _usdtUniqueAmt  = d.unique_amount;
      var box = document.getElementById('usdtTransferBox');
      document.getElementById('usdtWalletDisplay').textContent  = d.wallet_address;
      document.getElementById('usdtUniqueAmount').textContent    = parseFloat(d.unique_amount).toString() + ' USDT';
      document.getElementById('usdtCreditAmount').textContent    = parseFloat(d.amount_credited).toFixed(4) + ' USDT';
      box.style.display = 'block';
      startUsdtTimer(d.ttl_seconds || 1200);
    }).catch(function(){ btn2.disabled=false; _setSmsMsg('خطأ في الاتصال','#ff4455'); });
    return;
  }

  // ── SMS العادي ──
  var phone  = (document.getElementById('smsPhoneInput').value  || '').replace(/[^0-9]/g, '');
  if (phone.length < 8) {
    _setSmsMsg('يرجى إدخال رقم الهاتف الذي حولت منه', '#ff4455');
    return;
  }

  var btn = document.getElementById('smsSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ التحقق...';
  _setSmsMsg('', '');

  fetch('<?= SITE_URL ?>/api/sms_topup_verify.php', {
    method:  'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body:    new URLSearchParams({
      action:      'submit',
      csrf_token:  _smsCsrfToken,
      phone:       phone,
      amount:      parseFloat(amount),
      currency:    _smsCurrency,
      provider_id: _smsProviderId
    }).toString()
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-search-dollar"></i> تحقق وشحن الرصيد';

    if (d.ok && d.data && d.data.credited) {
      // ✅ نجح فوراً
      var amt = parseFloat(d.data.amount_usd || 0).toFixed(4);
      if (typeof showToast === 'function') {
        showToast('✅ تم شحن رصيدك! +' + amt + ' $', 'success');
      }
      // تحديث الرصيد في الواجهة
      var balEl = document.querySelector('.wallet-balance-amount, .balance-value, [data-balance]');
      if (balEl && d.data.new_balance) {
        balEl.textContent = parseFloat(d.data.new_balance).toFixed(2);
      }
      // إعادة تعيين الحقول
      document.getElementById('smsPhoneInput').value  = '';
      document.getElementById('smsAmountInput').value = '';
      // الانتقال للمحفظة بعد ثانيتين
      setTimeout(function() {
        if (typeof navTo === 'function') navTo('wallet');
      }, 1800);
      return;
    }

    // ❌ لم يُوجد تطابق أو خطأ
    _setSmsMsg(d.message || 'لم يتم العثور على العملية', '#ff4455');
  })
  .catch(function() {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-search-dollar"></i> تحقق وشحن الرصيد';
    _setSmsMsg('خطأ في الاتصال، تحقق من الإنترنت', '#ff4455');
  });
}

function _setSmsMsg(txt, color) {
  var el = document.getElementById('smsFormMsg');
  if (!el) return;
  el.style.whiteSpace = 'pre-line';
  el.textContent = txt;
  el.style.color  = color || 'var(--text3)';
}
// stub — لا polling في هذا الإصدار
function cancelSmsCheck() {}

function submitUsdtTx() {
  var txId = (document.getElementById('usdtTxId').value || '').trim();
  if (!txId) { _setSmsMsg('يرجى إدخال رقم العملية (txID)','#ff4455'); return; }
  var btn = document.getElementById('smsSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';
  fetch('<?= SITE_URL ?>/api/usdt_deposit.php', {
    method: 'POST',
    body: new URLSearchParams({ action:'submit_tx', session_id: _usdtSessionId, tx_id: txId, csrf_token: _smsCsrfToken })
  }).then(function(r){ return r.json(); }).then(function(d){
    btn.disabled = false;
    if (d.ok) {
      showToast(d.msg || '✅ تم الإرسال، سيتم مراجعة العملية وإضافة رصيدك','success');
      clearInterval(_usdtTimerInterval);
      document.getElementById('usdtTransferBox').style.display = 'none';
      document.getElementById('smsAmountInput').value = '';
      _usdtSessionId = null;
      setTimeout(function(){ if (typeof navTo==='function') navTo('wallet'); }, 2000);
    } else {
      _setSmsMsg(d.msg || 'خطأ','#ff4455');
      btn.innerHTML = '<i class="fas fa-paper-plane"></i> <span>إرسال رقم العملية</span>';
    }
  }).catch(function(){ btn.disabled=false; _setSmsMsg('خطأ في الاتصال','#ff4455'); });
}

function calcSmsCredit() {
  var amount  = parseFloat(document.getElementById('smsAmountInput').value) || 0;
  var rate    = _smsRateToUsd || 0;
  var box     = document.getElementById('smsCreditPreviewBox');
  var valEl   = document.getElementById('smsCreditUSD');
  var barEl   = document.getElementById('smsCreditBar');
  if (!box || !valEl) return;

  if (amount > 0 && rate > 0) {
    var usd = amount * rate;
    valEl.textContent = '$' + usd.toFixed(4);
    // شريط التقدم — يمتلئ عند 100$ كحد أقصى بصري
    var pct = Math.min((usd / 100) * 100, 100);
    if (barEl) barEl.style.width = pct + '%';
    box.style.display = '';
  } else if (amount > 0 && rate === 0) {
    // المزود بدون سعر محدد — اعرض الصندوق بدون قيمة
    valEl.textContent = '—';
    if (barEl) barEl.style.width = '0%';
    box.style.display = '';
  } else {
    box.style.display = 'none';
  }
}
</script>

<!-- ══ بوب أب بيانات التحويل ══ -->
<div id="smsTransferOverlay" onclick="closeSmsTransferPopup()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px">
  <div onclick="event.stopPropagation()" style="background:var(--card);border-radius:24px;padding:28px 22px 22px;max-width:360px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.7);animation:popupIn .3s cubic-bezier(.34,1.56,.64,1)">

    <div style="text-align:center;margin-bottom:20px">
      <div id="stPopupIcon" style="width:64px;height:64px;border-radius:20px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;font-size:1.8rem;background:rgba(30,111,255,.12);border:1.5px solid rgba(30,111,255,.2)">💳</div>
      <div id="stPopupTitle" style="font-size:1.05rem;font-weight:900;color:var(--text)">بيانات التحويل</div>
      <div style="font-size:.8rem;color:var(--text3);margin-top:4px">أرسل المبلغ إلى الحساب التالي ثم ادخل البيانات</div>
    </div>

    <div style="background:var(--bg2);border:1.5px solid var(--primary);border-radius:16px;padding:18px;margin-bottom:16px">
      <div style="margin-bottom:14px">
        <div style="font-size:.72rem;color:var(--text3);font-weight:700;margin-bottom:8px;text-align:right">📲 رقم الحساب / المحفظة</div>
        <div style="display:flex;align-items:center;gap:8px">
          <div id="stAccNum" style="flex:1;font-family:'Courier New',monospace;font-size:1.2rem;font-weight:900;color:var(--text);letter-spacing:1.5px;text-align:center;direction:ltr;word-break:break-all">—</div>
          <button onclick="copySmsTxt('stAccNum')" style="flex-shrink:0;background:rgba(30,111,255,.15);border:1px solid rgba(30,111,255,.3);border-radius:10px;padding:8px 12px;color:var(--primary);font-family:var(--font);font-size:.78rem;font-weight:700;cursor:pointer">
            <i class="fas fa-copy"></i>
          </button>
        </div>
      </div>
      <div style="border-top:1px solid var(--border);padding-top:14px">
        <div style="font-size:.72rem;color:var(--text3);font-weight:700;margin-bottom:8px;text-align:right">👤 اسم صاحب الحساب</div>
        <div style="display:flex;align-items:center;gap:8px">
          <div id="stAccHolder" style="flex:1;font-size:1rem;font-weight:800;color:var(--text);text-align:center">—</div>
          <button onclick="copySmsTxt('stAccHolder')" style="flex-shrink:0;background:rgba(0,212,255,.1);border:1px solid rgba(0,212,255,.25);border-radius:10px;padding:8px 12px;color:var(--cyan);font-family:var(--font);font-size:.78rem;font-weight:700;cursor:pointer">
            <i class="fas fa-copy"></i>
          </button>
        </div>
      </div>
    </div>

    <div style="background:rgba(245,166,35,.07);border:1px solid rgba(245,166,35,.2);border-radius:12px;padding:10px 14px;font-size:.78rem;color:#f5a623;line-height:1.7;margin-bottom:18px;text-align:right">
      <i class="fas fa-exclamation-triangle"></i>
      أرسل التحويل أولاً، ثم أدخل رقم هاتفك والمبلغ بالأسفل وانقر "تحقق وشحن الرصيد".
    </div>

    <button onclick="closeSmsTransferPopup()" id="stPopupOkBtn" style="width:100%;padding:14px;border:none;border-radius:14px;color:#fff;font-family:var(--font);font-size:1rem;font-weight:800;cursor:pointer;background:linear-gradient(135deg,var(--primary),#7c3aed);box-shadow:0 6px 20px rgba(30,111,255,.3)">
      <i class="fas fa-check-circle"></i> فهمت — سأرسل الآن
    </button>
  </div>
</div>

<script>
// ══ بوب أب بيانات التحويل ══
function showSmsTransferPopup(providerName, account, accountHolder, color) {
  if (!account && !accountHolder) return; // لا تعرض إذا لا توجد بيانات
  var ov = document.getElementById('smsTransferOverlay');
  document.getElementById('stPopupTitle').textContent = 'بيانات ' + (providerName || 'التحويل');
  document.getElementById('stAccNum').textContent = account || '—';
  document.getElementById('stAccHolder').textContent = accountHolder || '—';
  // لون الأيقونة حسب المزود
  var icon = document.getElementById('stPopupIcon');
  icon.style.background = (color||'#1e6fff') + '18';
  icon.style.borderColor = (color||'#1e6fff') + '44';
  var okBtn = document.getElementById('stPopupOkBtn');
  okBtn.style.background = 'linear-gradient(135deg,' + (color||'#1e6fff') + ',#7c3aed)';
  ov.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeSmsTransferPopup() {
  var ov = document.getElementById('smsTransferOverlay');
  ov.style.display = 'none';
  document.body.style.overflow = '';
}

function copySmsTxt(elId) {
  var el = document.getElementById(elId);
  if (!el) return;
  var txt = el.textContent.trim();
  if (!txt || txt === '—') return;
  navigator.clipboard.writeText(txt).then(function() {
    if (typeof showToast === 'function') showToast('✅ تم النسخ');
  }).catch(function() {
    var ta = document.createElement('textarea');
    ta.value = txt;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    if (typeof showToast === 'function') showToast('✅ تم النسخ');
  });
}
</script>

<!-- ══ USDT BEP20 — JavaScript ══ -->
<script>
(function() {
var _usdtTimer   = null;
var _usdtPoll    = null;
var _usdtReqData = (typeof USDT_ACTIVE !== 'undefined') ? USDT_ACTIVE : null;

// ── تشغيل عند فتح التبويب ────────────────────────────────────
window.usdtOnTabOpen = function() {
  if (_usdtReqData) {
    _usdtShowActive(_usdtReqData);
  } else {
    _usdtShowForm();
  }
};

// ── معاينة المبلغ ─────────────────────────────────────────────
window.usdtCalcPreview = function() {
  var v   = parseFloat(document.getElementById('usdtAmtInput').value) || 0;
  var box = document.getElementById('usdtPreviewBox');
  var lbl = document.getElementById('usdtPreviewCredit');
  if (v > 0) {
    box.style.display = 'flex';
    lbl.textContent   = '$' + v.toFixed(2);
  } else {
    box.style.display = 'none';
  }
};

// ── إنشاء طلب ────────────────────────────────────────────────
window.usdtCreateRequest = function() {
  var amt   = parseFloat((document.getElementById('usdtAmtInput') || {}).value) || 0;
  var errEl = document.getElementById('usdtFormError');
  var minD  = (typeof USDT_MIN_DEP !== 'undefined') ? USDT_MIN_DEP : 1;
  errEl.style.display = 'none';

  if (!amt || amt < minD) {
    errEl.textContent   = 'الحد الأدنى ' + minD + ' USDT';
    errEl.style.display = 'block';
    return;
  }
  var btn = document.getElementById('usdtCreateBtn');
  btn.disabled  = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإنشاء...';

  var base = (typeof SITE_URL !== 'undefined') ? SITE_URL : '';
  fetch(base + '/usdt_deposit_request.php', {
    method:  'POST',
    headers: {'Content-Type':'application/json'},
    body:    JSON.stringify({amount: amt})
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> إنشاء طلب الإيداع';
    if (d.ok && d.request) {
      _usdtReqData = d.request;
      _usdtShowActive(d.request);
    } else {
      errEl.textContent   = d.error || 'حدث خطأ، حاول مرة أخرى';
      errEl.style.display = 'block';
    }
  })
  .catch(function(){
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> إنشاء طلب الإيداع';
    errEl.textContent   = 'خطأ في الاتصال، تحقق من الإنترنت';
    errEl.style.display = 'block';
  });
};

// ── عرض الطلب النشط ──────────────────────────────────────────
function _usdtShowActive(req) {
  var amtEl = document.getElementById('usdtDispAmount');
  if (amtEl) amtEl.textContent = (parseFloat(req.unique_amount || 0).toString()) || '—';

  var wltEl  = document.getElementById('usdtDispWallet');
  var wallet = req.wallet_address || (typeof USDT_WALLET !== 'undefined' ? USDT_WALLET : '');
  if (wltEl && wallet) wltEl.textContent = wallet;

  document.getElementById('usdtFormBox').style.display    = 'none';
  document.getElementById('usdtActiveBox').style.display  = 'block';
  document.getElementById('usdtSuccessBox').style.display = 'none';

  _usdtStartTimer(req.expires_at);
  clearInterval(_usdtPoll);
  // لا polling تلقائي — التحقق يتم فقط عند ضغط الزر
}

// ── إظهار الفورم ─────────────────────────────────────────────
function _usdtShowForm() {
  document.getElementById('usdtFormBox').style.display    = 'block';
  document.getElementById('usdtActiveBox').style.display  = 'none';
  document.getElementById('usdtSuccessBox').style.display = 'none';
}

// ── إرسال txID والتحقق من المبلغ ─────────────────────────────
window.usdtSubmitTx = function() {
  if (!_usdtReqData) return;
  var txId  = (document.getElementById('usdtNewTxId').value || '').trim();
  var errEl = document.getElementById('usdtTxError');
  errEl.style.display = 'none';

  if (!txId) {
    errEl.textContent   = 'يرجى إدخال رقم العملية (txID) من محفظتك';
    errEl.style.display = 'block';
    return;
  }
  if (!/^0x[0-9a-fA-F]{60,70}$/.test(txId)) {
    errEl.textContent   = 'txID غير صالح — يجب أن يبدأ بـ 0x ويكون 64 حرفاً';
    errEl.style.display = 'block';
    return;
  }

  var btn = document.getElementById('usdtSubmitTxBtn');
  btn.disabled  = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التحقق من البلوكشين...';

  var base = (typeof SITE_URL !== 'undefined') ? SITE_URL : '';

  // ── إظهار حالة الانتظار ──────────────────────────────────────
  var sbox = document.getElementById('usdtStatusBox');
  var sico = document.getElementById('usdtStatusIcon');
  var smsg = document.getElementById('usdtStatusMsg');
  if (sbox) sbox.style.display = 'block';
  if (sico) sico.textContent   = '🔍';
  if (smsg) { smsg.textContent = 'جاري التحقق من العملية على شبكة BSC...'; smsg.style.color = '#f5a623'; }

  fetch(base + '/usdt_verify_tx.php', {
    method:  'POST',
    headers: {'Content-Type': 'application/json'},
    body:    JSON.stringify({
      request_id: _usdtReqData.id,
      tx_id:      txId
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> تحقق وأضف رصيدي';
    if (d.ok) {
      clearInterval(_usdtTimer);
      _usdtShowSuccess(d.amount, txId);
    } else {
      if (sbox) sbox.style.display = 'none';
      errEl.textContent   = d.error || 'لم يتم التحقق — تأكد من صحة txID والمبلغ';
      errEl.style.display = 'block';
    }
  })
  .catch(function(){
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> تحقق وأضف رصيدي';
    if (sbox) sbox.style.display = 'none';
    errEl.textContent   = 'خطأ في الاتصال، حاول مرة أخرى';
    errEl.style.display = 'block';
  });
};

// ── إعادة تعيين ───────────────────────────────────────────────
window.usdtResetForm = function() {
  clearInterval(_usdtPoll);
  clearInterval(_usdtTimer);
  _usdtReqData = null;
  var i = document.getElementById('usdtAmtInput');
  if (i) i.value = '';
  var e = document.getElementById('usdtFormError');
  if (e) e.style.display = 'none';
  var p = document.getElementById('usdtPreviewBox');
  if (p) p.style.display = 'none';
  var s = document.getElementById('usdtStatusBox');
  if (s) s.style.display = 'none';
  // مسح حقل txID
  var tx = document.getElementById('usdtNewTxId');
  if (tx) tx.value = '';
  var te = document.getElementById('usdtTxError');
  if (te) te.style.display = 'none';
  _usdtShowForm();
};

// ── نجاح ─────────────────────────────────────────────────────
function _usdtShowSuccess(amount, txHash) {
  document.getElementById('usdtFormBox').style.display    = 'none';
  document.getElementById('usdtActiveBox').style.display  = 'none';
  document.getElementById('usdtSuccessBox').style.display = 'block';
  var msg = document.getElementById('usdtSuccessMsg');
  if (msg) {
    var tx = txHash ? txHash.substring(0,12) + '...' + txHash.slice(-6) : '';
    msg.innerHTML = 'تم إيداع <strong style="color:#00e676">' + amount + ' USDT</strong> بنجاح 🎉' +
      (tx ? '<br><small style="font-family:monospace;color:#4d6080;font-size:.72rem">' + tx + '</small>' : '');
  }
}

// ── عداد تنازلي ───────────────────────────────────────────────
function _usdtStartTimer(expiresAt) {
  clearInterval(_usdtTimer);
  var el = document.getElementById('usdtCountdown');
  if (!el) return;
  var expMs = new Date(expiresAt.replace(' ','T')).getTime();
  function tick() {
    var rem = Math.max(0, Math.floor((expMs - Date.now()) / 1000));
    var m = Math.floor(rem / 60), s = rem % 60;
    el.textContent = (m<10?'0':'')+m+':'+(s<10?'0':'')+s;
    el.style.color = rem < 120 ? '#ff4466' : '#f5a623';
    if (rem === 0) {
      clearInterval(_usdtTimer);
      clearInterval(_usdtPoll);
      el.textContent = 'انتهت الصلاحية';
      el.style.color = '#ff4466';
    }
  }
  tick();
  _usdtTimer = setInterval(tick, 1000);
}

// ── نسخ النص ─────────────────────────────────────────────────
window.usdtCopy = function(elId, btnId) {
  var el = document.getElementById(elId);
  var bn = document.getElementById(btnId);
  if (!el || !bn) return;
  var txt  = el.textContent.trim();
  var orig = bn.textContent;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(txt).then(function(){
      bn.textContent = '✅ تم النسخ';
      setTimeout(function(){ bn.textContent = orig; }, 2000);
    });
  } else {
    var ta = document.createElement('textarea');
    ta.value = txt; ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
    bn.textContent = '✅ تم النسخ';
    setTimeout(function(){ bn.textContent = orig; }, 2000);
  }
};

// ══ BINANCE PAY — مسار مستقل عن USDT on-chain ══
(function () {
  var _binanceRequest = null;
  var _binanceBusy = false;
  var binanceErrors = {
    feature_not_ready: 'خدمة Binance غير جاهزة حالياً.', invalid_amount: 'أدخل مبلغاً صحيحاً يساوي الحد الأدنى أو أكثر.',
    request_not_found: 'طلب الإيداع غير موجود.', too_many_pending: 'لديك طلبات Binance مفتوحة بالفعل. أكمل أو انتظر انتهاء أحدها قبل إنشاء طلب جديد.', request_expired: 'انتهت صلاحية طلب الإيداع. أنشئ طلباً جديداً.',
    attempt_limit: 'تم بلوغ عدد محاولات التحقق المسموح.', verification_in_progress: 'توجد محاولة تحقق قيد التنفيذ لهذا الطلب. انتظر قليلاً.', transaction_locked: 'هذا الطلب مرتبط بمعرّف عملية مختلف.',
    invalid_transaction_id: 'أدخل معرّف العملية كما يظهر في Binance Pay.', transaction_not_found: 'لم تظهر العملية في سجل Binance Pay ضمن النافذة الحالية.',
    transaction_unsuccessful: 'العملية موجودة لكنها غير ناجحة.', wrong_order_type: 'نوع العملية غير مقبول للإيداع. المقبول هو C2C الوارد فقط.',
    wrong_currency: 'عملة العملية ليست USDT.', amount_mismatch: 'المبلغ المستلم لا يطابق مبلغ طلب الإيداع.',
    time_outside_window: 'وقت العملية خارج مدة طلب الإيداع.', receiver_mismatch: 'حساب المستلم لا يطابق حساب الموقع.',
    api_error: 'تعذر الوصول إلى Binance حالياً. حاول لاحقاً.', transport_error: 'تعذر الاتصال بخدمة التحقق حالياً.',
    credentials_unavailable: 'خدمة Binance غير مهيأة من الإدارة.', credit_failed: 'تم العثور على العملية لكن تعذر قيد الرصيد. تواصل مع الإدارة.',
    csrf_failed: 'انتهت جلسة الأمان. أعد تحميل الصفحة وحاول مرة أخرى.', login_required: 'يجب تسجيل الدخول أولاً.'
  };
  function bmsg(code) { return binanceErrors[code] || 'تعذر إكمال التحقق حالياً.'; }
  function bsetMessage(text, good) {
    var el = document.getElementById('binanceMessage'); if (!el) return;
    el.textContent = text || ''; el.style.color = good ? '#00c853' : '#ff5577';
  }
  function bsetBusy(id, busy, busyText) {
    var el = document.getElementById(id); if (!el) return;
    el.disabled = busy; el.style.opacity = busy ? '.65' : '1';
    if (busyText) { if (!el.dataset.originalText) el.dataset.originalText = el.textContent; el.textContent = busy ? busyText : el.dataset.originalText; }
  }
  function bpost(data) {
    data.csrf_token = (typeof _smsCsrfToken !== 'undefined') ? _smsCsrfToken : '';
    return fetch('<?= SITE_URL ?>/api/binance_pay_deposit.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:new URLSearchParams(data)})
      .then(function(res) { return res.json().catch(function(){ return {ok:false,error_code:'api_error'}; }); });
  }
  function renderRequest(req, instructions) {
    _binanceRequest = req;
    var create = document.getElementById('binanceCreateBox'), box = document.getElementById('binanceRequestBox');
    if (create) create.style.display = 'none'; if (box) box.style.display = 'block';
    var amount = document.getElementById('binanceExpectedAmount'); if (amount) amount.textContent = req.expected_amount + ' ' + (req.expected_currency || 'USDT');
    var receiver = document.getElementById('binanceReceiver'); if (receiver) receiver.textContent = (instructions && instructions.receiver_identifier) || 'حساب الموقع';
    var txInput = document.getElementById('binanceTransactionInput');
    if (txInput) {
      txInput.value = req.submitted_transaction_id ? String(req.submitted_transaction_id) : '';
      txInput.readOnly = !!req.submitted_transaction_id;
      txInput.title = req.submitted_transaction_id ? 'هذا المعرّف مقفل على الطلب السابق' : '';
    }
  }
  window.binanceCreateDeposit = function () {
    if (_binanceBusy) return;
    var input = document.getElementById('binanceAmountInput'), amount = input ? input.value.trim() : '';
    if (!amount) { bsetMessage('أدخل مبلغ الإيداع أولاً.', false); return; }
    _binanceBusy = true; bsetBusy('binanceCreateBtn', true, 'جارٍ إنشاء الطلب...'); bsetMessage('', false);
    bpost({action:'create',amount:amount}).then(function(data) {
      if (data.ok && data.request) { renderRequest(data.request, data.instructions || {}); bsetMessage('تم إنشاء طلب الإيداع. نفّذ التحويل ثم أدخل transactionId.', true); }
      else bsetMessage(bmsg(data.error_code), false);
    }).catch(function(){ bsetMessage('تعذر الاتصال بالخادم.', false); }).finally(function(){ _binanceBusy = false; bsetBusy('binanceCreateBtn', false); });
  };
  window.binanceVerifyDeposit = function () {
    if (_binanceBusy || !_binanceRequest) return;
    var input = document.getElementById('binanceTransactionInput'), tx = input ? input.value.trim() : '';
    if (!tx) { bsetMessage('أدخل معرّف العملية أولاً.', false); return; }
    _binanceBusy = true; bsetBusy('binanceVerifyBtn', true, 'جارٍ التحقق من Binance...'); bsetMessage('يتم فحص سجل معاملات Binance Pay الواردة من نوع C2C فقط...', true);
    bpost({action:'verify',request_id:String(_binanceRequest.id),transaction_id:tx}).then(function(data) {
      if (data.ok && data.credited) { bsetMessage('تم التحقق وإضافة الرصيد بنجاح.', true); _binanceRequest.status = 'credited'; if (typeof showToast === 'function') showToast('تمت إضافة رصيد Binance Pay بنجاح'); }
      else bsetMessage(bmsg(data.error_code), false);
      if (['request_expired','attempt_limit','transaction_locked'].indexOf(data.error_code) >= 0) { var btn = document.getElementById('binanceVerifyBtn'); if (btn) btn.disabled = true; }
    }).catch(function(){ bsetMessage('تعذر الاتصال بالخادم.', false); }).finally(function(){ _binanceBusy = false; bsetBusy('binanceVerifyBtn', false); });
  };
  window.binanceResetDeposit = function () {
    _binanceRequest = null; var create = document.getElementById('binanceCreateBox'), box = document.getElementById('binanceRequestBox');
    if (create) create.style.display = 'block'; if (box) box.style.display = 'none';
    var tx = document.getElementById('binanceTransactionInput'); if (tx) { tx.value = ''; tx.readOnly = false; tx.title = ''; } bsetMessage('', false);
  };
  window.binanceResumeDeposit = function (req) {
    if (!req || !req.id) return;
    var status = String(req.status || '');
    var retryableRejected = status === 'rejected'
      && String(req.safe_error_code || '') === 'transaction_not_found'
      && String(req.submitted_transaction_id || '') !== '';
    if (['pending','verifying'].indexOf(status) < 0 && !retryableRejected) {
      bsetMessage('هذا الطلب غير قابل للمتابعة. الرفض بسبب المبلغ أو النوع أو المستلم لا يمكن إعادة محاولته.', false);
      return;
    }
    renderRequest(req, {});
    bsetMessage(retryableRejected
      ? 'يمكن إعادة فحص هذا الطلب مرة واحدة بالمعرّف نفسه بعد تحديث سجل Binance.'
      : 'تمت استعادة الطلب السابق. حوّل المبلغ نفسه ثم أدخل معرّف العملية من تفاصيل تحويل Binance.', true);
    var box = document.getElementById('binanceRequestBox');
    if (box && typeof box.scrollIntoView === 'function') box.scrollIntoView({behavior:'smooth', block:'center'});
  };
  window.binanceLoadHistory = function () {
    var list = document.getElementById('binanceHistory'); if (!list) return;
    bpost({action:'list'}).then(function(data) {
      if (!data.ok || !data.requests || !data.requests.length) { list.innerHTML = ''; return; }
      var labels = {pending:'قيد الانتظار',verifying:'جارٍ التحقق',verified:'تم التحقق',credited:'تمت الإضافة',rejected:'مرفوض',expired:'منتهي',error:'خطأ قابل لإعادة المحاولة'};
      list.innerHTML = '<div style="font-weight:800;color:var(--text2);font-size:.82rem;margin-bottom:7px">آخر طلبات Binance</div>' + data.requests.map(function(r) {
        var amount = String(r.expected_amount == null ? '' : r.expected_amount).replace(/[<>]/g,'');
        var status = String(r.status || '');
        var label = labels[status] || 'غير معروف';
        var canResume = ['pending','verifying'].indexOf(status) >= 0;
        var rid = String(r.id == null ? '' : r.id).replace(/[^0-9]/g,'');
        var action = canResume && rid ? '<button type="button" class="binance-resume-btn" data-request-id="' + rid + '" style="border:1px solid rgba(246,193,26,.55);background:transparent;color:#f6c11a;border-radius:8px;padding:4px 8px;font-family:var(--font);font-size:.72rem;font-weight:800;cursor:pointer">متابعة التحقق</button>' : '';
        return '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:8px 10px;margin-bottom:6px;font-size:.75rem"><span>' + amount + ' USDT</span><strong>' + label + '</strong>' + action + '</div>';
      }).join('');
      Array.prototype.forEach.call(list.querySelectorAll('.binance-resume-btn'), function(btn) {
        btn.addEventListener('click', function() {
          var id = String(btn.getAttribute('data-request-id') || '');
          var req = data.requests.find(function(item) { return String(item.id) === id; });
          if (req) window.binanceResumeDeposit(req);
        });
      });
    }).catch(function(){});
  };
  window.binanceOnTabOpen = function () { binanceLoadHistory(); };
})();

})();
</script>
