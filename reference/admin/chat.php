<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_customers_view');
$pageTitle = 'المحادثات المباشرة — ' . SITE_NAME;
$staffList = $pdo->query("SELECT id, username, full_name FROM users WHERE role IN ('admin','staff') AND status=1 ORDER BY username")->fetchAll();
include 'header.php';
?>
<style>
.chat-shell{display:grid;grid-template-columns:320px 1fr;height:calc(100vh - var(--header-h) - 40px);gap:0;border-radius:16px;overflow:hidden;border:1px solid var(--border2)}
.chat-list-panel{background:var(--bg2);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
.chat-list-header{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-shrink:0}
.chat-list-search{flex:1;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:7px 10px;color:var(--text);font-size:13px;outline:none;font-family:var(--font)}
.chat-filter-tabs{display:flex;gap:4px;padding:8px 12px;border-bottom:1px solid var(--border);flex-shrink:0}
.chat-filter-tab{padding:4px 12px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);transition:.15s}
.chat-filter-tab.active{background:var(--primary);border-color:var(--primary);color:#fff}
.chat-list{overflow-y:auto;flex:1}
.chat-item{padding:12px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;position:relative}
.chat-item:hover{background:rgba(255,255,255,.03)}
.chat-item.active{background:rgba(30,111,255,.1);border-right:3px solid var(--primary)}
.chat-item-name{font-weight:700;font-size:.88rem;margin-bottom:3px;display:flex;align-items:center;gap:6px}
.chat-item-preview{font-size:.75rem;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chat-item-time{font-size:.65rem;color:var(--text3);position:absolute;top:12px;left:12px}
.chat-unread{background:#ff4455;color:#fff;border-radius:10px;font-size:10px;font-weight:900;padding:1px 6px;min-width:18px;text-align:center}
.chat-status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.status-open{background:#00e676}.status-pending{background:#f5a623}.status-closed{background:#8895a7}

.chat-main{background:var(--bg);display:flex;flex-direction:column;overflow:hidden}
.chat-main-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0;background:var(--bg2)}
.chat-main-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#7c3aed);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1rem;flex-shrink:0}
.chat-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;scrollbar-width:thin}
.chat-bubble{max-width:75%;padding:10px 14px;border-radius:16px;font-size:.87rem;line-height:1.6;position:relative;word-break:break-word}
.bubble-user{background:var(--card2);border-radius:16px 16px 16px 4px;align-self:flex-start}
.bubble-staff{background:linear-gradient(135deg,var(--primary),#2563eb);color:#fff;border-radius:16px 16px 4px 16px;align-self:flex-end}
.bubble-auto{background:rgba(0,212,170,.12);border:1px solid rgba(0,212,170,.25);border-radius:16px 16px 16px 4px;align-self:flex-start;color:var(--text)}
.bubble-time{font-size:.65rem;opacity:.6;margin-top:4px;display:block}
.bubble-auto-tag{font-size:.65rem;color:#00d4aa;font-weight:700;margin-bottom:3px}
.chat-input-area{padding:12px 16px;border-top:1px solid var(--border);background:var(--bg2);flex-shrink:0}
.chat-input-row{display:flex;gap:8px;align-items:flex-end}
.chat-textarea{flex:1;background:var(--bg3);border:1px solid var(--border2);border-radius:12px;padding:10px 14px;color:var(--text);font-family:var(--font);font-size:.88rem;outline:none;resize:none;max-height:120px;min-height:40px;transition:border-color .2s}
.chat-textarea:focus{border-color:var(--primary)}
.chat-send-btn{width:42px;height:42px;background:var(--primary);border:none;border-radius:12px;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .15s}
.chat-send-btn:active{opacity:.8}
.chat-tools{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;align-items:center}
.chat-tool-btn{padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;border:1px solid var(--border);background:var(--bg3);color:var(--text2);cursor:pointer;font-family:var(--font);transition:.15s}
.chat-tool-btn:hover{border-color:var(--primary);color:var(--primary)}
.chat-empty{flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:var(--text3)}
.chat-empty i{font-size:3rem;opacity:.1}
.quick-reply-chip{display:inline-block;padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid var(--border2);background:var(--bg2);color:var(--text2);cursor:pointer;margin:3px;transition:.15s;font-family:var(--font)}
.quick-reply-chip:hover{border-color:var(--primary);color:var(--primary);background:rgba(30,111,255,.08)}
@media(max-width:768px){.chat-shell{grid-template-columns:1fr;height:auto}.chat-list-panel{height:300px}}
</style>

<div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
  <div>
    <h2 style="margin:0;font-size:1.2rem;font-weight:900"><i class="fas fa-comments" style="color:var(--primary)"></i> المحادثات المباشرة</h2>
    <div style="font-size:12px;color:var(--text3);margin-top:3px">ردّ على استفسارات العملاء في الوقت الحقيقي</div>
  </div>
  <a href="chat_settings.php" class="btn btn-secondary btn-sm"><i class="fas fa-cog"></i> الإعدادات والردود التلقائية</a>
</div>

<div class="chat-shell" id="chatShell">
  <!-- قائمة المحادثات -->
  <div class="chat-list-panel">
    <div class="chat-list-header">
      <i class="fas fa-comments" style="color:var(--primary)"></i>
      <span style="font-weight:700;font-size:.9rem">المحادثات</span>
      <span id="totalBadge" style="background:var(--primary);color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:800;margin-right:auto">0</span>
      <button onclick="loadChats()" class="chat-tool-btn" title="تحديث"><i class="fas fa-sync-alt"></i></button>
    </div>
    <div class="chat-filter-tabs">
      <button class="chat-filter-tab active" onclick="setFilter('open',this)">مفتوحة</button>
      <button class="chat-filter-tab" onclick="setFilter('pending',this)">انتظار</button>
      <button class="chat-filter-tab" onclick="setFilter('closed',this)">مغلقة</button>
      <button class="chat-filter-tab" onclick="setFilter('all',this)">الكل</button>
    </div>
    <input type="text" class="chat-list-search" id="chatSearch" placeholder="🔍 بحث..." oninput="filterChatList(this.value)" style="margin:8px;width:calc(100% - 16px);box-sizing:border-box">
    <div class="chat-list" id="chatList">
      <div style="padding:30px;text-align:center;color:var(--text3);font-size:.8rem">جاري التحميل...</div>
    </div>
  </div>

  <!-- نافذة المحادثة -->
  <div class="chat-main" id="chatMain">
    <div class="chat-empty">
      <i class="fas fa-comment-dots"></i>
      <div style="font-weight:700">اختر محادثة للرد</div>
      <div style="font-size:.8rem">اضغط على أي محادثة من القائمة</div>
    </div>
  </div>
</div>

<script>
const CHAT_API = '<?= SITE_URL ?>/api/chat.php';
const STAFF_LIST = <?= json_encode(array_map(fn($s)=>['id'=>$s['id'],'name'=>$s['full_name']?:$s['username']], $staffList), JSON_UNESCAPED_UNICODE) ?>;

let currentFilter = 'open';
let currentChatId = null;
let lastMsgId     = 0;
let pollTimer     = null;
let allChats      = [];

// ── تحميل قائمة المحادثات ─────────────────────────────────────
async function loadChats() {
  const r = await fetch(`${CHAT_API}?action=staff_list&status=${currentFilter}`);
  const d = await r.json();
  allChats = d.chats || [];
  document.getElementById('totalBadge').textContent = allChats.length;
  renderChatList(allChats);
}

function renderChatList(chats) {
  const el = document.getElementById('chatList');
  if (!chats.length) {
    el.innerHTML = '<div style="padding:30px;text-align:center;color:var(--text3);font-size:.8rem">لا توجد محادثات</div>';
    return;
  }
  el.innerHTML = chats.map(c => {
    const name = c.full_name || c.username;
    const preview = c.last_msg ? c.last_msg.substring(0,40) + (c.last_msg.length>40?'...':'') : '—';
    const time = c.last_time ? formatTime(c.last_time) : '';
    const unread = parseInt(c.unread_staff) || 0;
    return `<div class="chat-item ${c.id==currentChatId?'active':''}" onclick="openChat(${c.id},'${escJ(name)}')">
      <div class="chat-item-name">
        <span class="chat-status-dot status-${c.status}"></span>
        ${escH(name)}
        ${unread > 0 ? `<span class="chat-unread">${unread}</span>` : ''}
        ${c.staff_name ? `<span style="font-size:.65rem;color:var(--cyan);font-weight:600">← ${escH(c.staff_name)}</span>` : ''}
      </div>
      <div class="chat-item-preview">${escH(preview)}</div>
      <div class="chat-item-time">${time}</div>
    </div>`;
  }).join('');
}

function filterChatList(q) {
  if (!q) { renderChatList(allChats); return; }
  const f = q.toLowerCase();
  renderChatList(allChats.filter(c =>
    (c.username||'').toLowerCase().includes(f) ||
    (c.full_name||'').toLowerCase().includes(f) ||
    (c.last_msg||'').toLowerCase().includes(f)
  ));
}

function setFilter(f, btn) {
  currentFilter = f;
  document.querySelectorAll('.chat-filter-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadChats();
}

// ── فتح محادثة ────────────────────────────────────────────────
async function openChat(chatId, name) {
  currentChatId = chatId;
  lastMsgId = 0;
  clearTimeout(pollTimer);

  // تحديث active في القائمة
  document.querySelectorAll('.chat-item').forEach(el => {
    el.classList.toggle('active', parseInt(el.getAttribute('onclick').match(/\d+/)[0]) === chatId);
  });

  // جلب الرسائل
  const r = await fetch(`${CHAT_API}?action=staff_messages&chat_id=${chatId}&after_id=0`);
  const d = await r.json();

  const chat = allChats.find(c => c.id == chatId) || {};
  renderChatWindow(chatId, name, chat, d.messages || []);

  if (d.messages && d.messages.length) lastMsgId = d.messages[d.messages.length-1].id;

  // بدء polling
  startPolling();
  // تحديث القائمة لإزالة الشارة
  loadChats();
}

function renderChatWindow(chatId, name, chat, messages) {
  const staffOptions = STAFF_LIST.map(s =>
    `<option value="${s.id}" ${chat.assigned_to==s.id?'selected':''}>${escH(s.name)}</option>`
  ).join('');

  document.getElementById('chatMain').innerHTML = `
    <div class="chat-main-header">
      <div class="chat-main-avatar">${name.charAt(0).toUpperCase()}</div>
      <div style="flex:1">
        <div style="font-weight:800;font-size:.92rem">${escH(name)}</div>
        <div style="font-size:.72rem;color:var(--text3)">${getStatusLabel(chat.status)} • ${chat.subject||'استفسار'}</div>
      </div>
      <select onchange="assignStaff(${chatId},this.value)" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:5px 8px;color:var(--text);font-size:12px;font-family:var(--font)">
        <option value="">تعيين موظف...</option>
        ${staffOptions}
      </select>
      <button onclick="closeChat(${chatId})" class="btn btn-secondary btn-sm" title="إغلاق المحادثة"><i class="fas fa-times-circle"></i> إغلاق</button>
    </div>
    <div class="chat-messages" id="chatMessages"></div>
    <div class="chat-input-area">
      <div class="chat-tools">
        <span style="font-size:11px;color:var(--text3)">ردود سريعة:</span>
        <span class="quick-reply-chip" onclick="insertReply('تم استلام رسالتك، سنرد عليك قريباً ✅')">تم الاستلام</span>
        <span class="quick-reply-chip" onclick="insertReply('شكراً لتواصلك معنا 😊')">شكراً</span>
        <span class="quick-reply-chip" onclick="insertReply('هل يمكنك توضيح المشكلة أكثر؟')">توضيح</span>
        <span class="quick-reply-chip" onclick="insertReply('تم حل المشكلة بنجاح ✅')">تم الحل</span>
      </div>
      <div class="chat-input-row">
        <textarea class="chat-textarea" id="staffMsgInput" placeholder="اكتب ردك هنا..." rows="1"
          onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();staffSend(${chatId})}"
          oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px'"></textarea>
        <button class="chat-send-btn" onclick="staffSend(${chatId})"><i class="fas fa-paper-plane"></i></button>
      </div>
    </div>`;

  renderMessages(messages);
}

function renderMessages(msgs) {
  const el = document.getElementById('chatMessages');
  if (!el) return;
  msgs.forEach(m => appendMessage(m));
  el.scrollTop = el.scrollHeight;
}

function appendMessage(m) {
  const el = document.getElementById('chatMessages');
  if (!el) return;
  const isStaff  = m.sender_type === 'staff';
  const isAuto   = parseInt(m.is_auto) === 1;
  const name     = m.sender_type === 'user' ? (m.full_name || m.username || 'العميل') : (isAuto ? '🤖 رد تلقائي' : (m.full_name || m.username || 'الدعم'));
  const cls      = isAuto ? 'bubble-auto' : (isStaff ? 'bubble-staff' : 'bubble-user');
  const time     = formatTime(m.created_at);
  const div = document.createElement('div');
  div.dataset.id = m.id;
  div.innerHTML = `<div class="chat-bubble ${cls}">
    ${isAuto ? '<div class="bubble-auto-tag"><i class="fas fa-robot"></i> رد تلقائي</div>' : ''}
    <div style="font-size:.7rem;font-weight:700;margin-bottom:4px;opacity:.7">${escH(name)}</div>
    ${escH(m.message).replace(/\n/g,'<br>')}
    <span class="bubble-time">${time}</span>
  </div>`;
  el.appendChild(div);
  el.scrollTop = el.scrollHeight;
}

async function staffSend(chatId) {
  const input = document.getElementById('staffMsgInput');
  const msg   = input.value.trim();
  if (!msg) return;
  input.value = '';
  input.style.height = 'auto';

  const fd = new FormData();
  fd.append('action','staff_send');
  fd.append('chat_id', chatId);
  fd.append('message', msg);
  await fetch(CHAT_API, {method:'POST', body:fd});
  loadChats();
}

function insertReply(text) {
  const el = document.getElementById('staffMsgInput');
  if (el) { el.value = text; el.focus(); }
}

async function assignStaff(chatId, staffId) {
  const fd = new FormData();
  fd.append('action','assign');
  fd.append('chat_id', chatId);
  fd.append('staff_id', staffId);
  await fetch(CHAT_API, {method:'POST', body:fd});
}

async function closeChat(chatId) {
  if (!confirm('إغلاق هذه المحادثة؟')) return;
  const fd = new FormData();
  fd.append('action','close');
  fd.append('chat_id', chatId);
  await fetch(CHAT_API, {method:'POST', body:fd});
  currentChatId = null;
  document.getElementById('chatMain').innerHTML = '<div class="chat-empty"><i class="fas fa-check-circle" style="color:var(--green)"></i><div style="font-weight:700">تم إغلاق المحادثة</div></div>';
  loadChats();
}

function startPolling() {
  clearTimeout(pollTimer);
  if (!currentChatId) return;
  pollTimer = setTimeout(async () => {
    const r = await fetch(`${CHAT_API}?action=staff_messages&chat_id=${currentChatId}&after_id=${lastMsgId}`);
    const d = await r.json();
    if (d.messages && d.messages.length) {
      d.messages.forEach(m => appendMessage(m));
      lastMsgId = d.messages[d.messages.length-1].id;
      loadChats(); // تحديث الشارات
    }
    startPolling();
  }, 3000);
}

// ── Helpers ───────────────────────────────────────────────────
function formatTime(dt) {
  if (!dt) return '';
  const d = new Date(dt.replace(' ','T'));
  const now = new Date();
  const diff = (now - d) / 1000;
  if (diff < 60)   return 'الآن';
  if (diff < 3600) return Math.floor(diff/60) + ' د';
  if (diff < 86400)return Math.floor(diff/3600) + ' س';
  return `${d.getDate()}/${d.getMonth()+1}`;
}
function getStatusLabel(s) {
  return {open:'مفتوحة',pending:'انتظار',closed:'مغلقة'}[s] || s;
}
function escH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escJ(s) { return String(s||'').replace(/'/g,"\\'"); }

// ── تحميل أولي ───────────────────────────────────────────────
loadChats();
setInterval(loadChats, 10000); // تحديث القائمة كل 10 ثوانٍ
</script>

<?php include 'footer.php'; ?>
