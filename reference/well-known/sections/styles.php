<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
<style>
/* ══════════════════════════════════════════════════════
   🌙 DARK MODE — النظام الافتراضي (وضع ليلي)
   ══════════════════════════════════════════════════════
   تدرج من أزرق كحلي عميق → طبقات متصاعدة الفاتحية
   ─────────────────────────────────────────────────── */
:root{
  /* ── Backgrounds ── */
  --bg:       #05080f;   /* أعمق طبقة - خلفية الجسم */
  --bg2:      #090d1a;   /* طبقة ثانية - nav جانبية، inputs */
  --card:     #0e1525;   /* بطاقات المستوى الأول */
  --card2:    #151f35;   /* بطاقات المستوى الثاني */
  --card3:    #1c2942;   /* بطاقات المستوى الثالث / hover */

  /* ── Borders ── */
  --border:   rgba(255,255,255,0.07);
  --border2:  rgba(255,255,255,0.12);

  /* ── Brand Colors ── */
  --primary:  #3b82f6;   /* أزرق أكثر إشراقاً وإدراكاً */
  --primary2: #1d4ed8;   /* أزرق أغمق للتدرجات */
  --primary-glow: rgba(59,130,246,0.25);
  --cyan:     #22d3ee;   /* سيان أكثر عمقاً */
  --gold:     #fbbf24;   /* ذهبي دافئ */
  --gold-glow:rgba(251,191,36,0.2);
  --green:    #34d399;   /* أخضر ناعم */
  --red:      #f87171;   /* أحمر ناعم غير صارخ */
  --purple:   #a78bfa;   /* بنفسجي للتمييز */

  /* ── Text ── */
  --text:     #e2e8f5;   /* أبيض مزرق ناعم (أقل إجهاداً من #fff) */
  --text2:    #7c93b5;   /* ثانوي */
  --text3:    #3d526e;   /* خافت */

  /* ── Layout ── */
  --radius:   18px;
  --radius-sm:12px;
  --shadow:   0 8px 32px rgba(0,0,0,0.6);
  --font:     'Cairo',sans-serif;
  --nav-h:    64px;
  --header-h: 60px;
  --topbar-h: 114px;
}
/* ── زر التبديل (الوضع الليلي الافتراضي) ── */
.theme-toggle-btn{
  width:38px;height:38px;background:var(--card2);border:1px solid var(--border);
  border-radius:12px;display:flex;align-items:center;justify-content:center;
  cursor:pointer;color:var(--text2);font-size:17px;transition:all .2s;
  flex-shrink:0;
}
.theme-toggle-btn:active{transform:scale(.92)}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
/* ══ Popup إشعار منبثق ══ */
.notif-popup-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(5,8,20,.72);
  z-index: 800;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.notif-popup-overlay.open { display: flex; }
.notif-popup-box {
  background: var(--card);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 26px;
  padding: 0 0 24px;
  max-width: 340px;
  width: 100%;
  text-align: center;
  box-shadow: 0 30px 70px -15px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.03) inset;
  animation: popupIn .42s cubic-bezier(.2,1.4,.4,1);
  position: relative;
  overflow: hidden;
}
@keyframes popupIn { 0%{opacity:0;transform:scale(.82) translateY(18px)} 60%{opacity:1} 100%{opacity:1;transform:scale(1) translateY(0)} }

/* شريط علوي بلون الإشعار */
.notif-popup-box::before {
  content: '';
  display: block;
  height: 5px;
  width: 100%;
  background: var(--np-color, #6c3fe0);
}

.notif-popup-close {
  position: absolute;
  top: 16px; left: 16px;
  width: 28px; height: 28px;
  border-radius: 50%;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.08);
  color: var(--text3);
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem;
  cursor: pointer;
  transition: background .15s, transform .15s;
  z-index: 2;
}
.notif-popup-close:active { transform: scale(.88); background: rgba(255,255,255,.12); }

.notif-popup-icon {
  width: 76px; height: 76px;
  border-radius: 22px;
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem;
  margin: 26px auto 18px;
  position: relative;
  animation: npIconPop .5s cubic-bezier(.2,1.6,.4,1) .1s both, npIconFloat 2.6s ease-in-out 1s infinite;
  box-shadow: 0 10px 26px -6px var(--np-color, #6c3fe0);
}
.notif-popup-icon::after {
  content: '';
  position: absolute; inset: -8px;
  border-radius: 26px;
  border: 1.5px solid var(--np-color, #6c3fe0);
  opacity: .35;
  animation: npIconRing 2.2s ease-out infinite;
}
@keyframes npIconPop { from{transform:scale(0) rotate(-12deg);opacity:0} to{transform:scale(1) rotate(0);opacity:1} }
@keyframes npIconFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-5px)} }
@keyframes npIconRing { 0%{transform:scale(.9);opacity:.5} 100%{transform:scale(1.25);opacity:0} }

.notif-popup-title {
  font-size: 1.15rem;
  font-weight: 900;
  margin-bottom: 8px;
  line-height: 1.4;
  padding: 0 22px;
}
.notif-popup-msg {
  font-size: .87rem;
  color: var(--text2);
  line-height: 1.75;
  margin-bottom: 22px;
  padding: 0 24px;
}
.notif-popup-btns {
  display: flex;
  gap: 8px;
  padding: 0 20px;
}
.notif-popup-btn-ok {
  flex: 1;
  padding: 13px;
  border-radius: 14px;
  border: none;
  color: #fff;
  font-family: var(--font);
  font-size: .9rem;
  font-weight: 800;
  cursor: pointer;
  transition: transform .15s, filter .15s;
  box-shadow: 0 8px 20px -6px var(--np-color, #6c3fe0);
}
.notif-popup-btn-ok:active { transform: scale(.96); filter: brightness(.92); }
.notif-popup-btn-later {
  padding: 13px 18px;
  border-radius: 14px;
  border: 1px solid var(--border2);
  background: var(--bg2);
  color: var(--text2);
  font-family: var(--font);
  font-size: .87rem;
  font-weight: 700;
  cursor: pointer;
  transition: background .15s;
}
.notif-popup-btn-later:active { background: var(--border); }

/* ══ نافذة المحادثة ══ */
.chat-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:700;display:none;backdrop-filter:blur(3px)}
.chat-overlay.open{display:block}
.chat-window{position:fixed;bottom:0;left:50%;transform:translateX(-50%) translateY(100%);width:100%;max-width:430px;height:90vh;background:var(--bg);border-radius:24px 24px 0 0;z-index:701;display:flex;flex-direction:column;transition:transform .3s cubic-bezier(.4,0,.2,1);overflow:hidden}
.chat-window.open{transform:translateX(-50%) translateY(0)}
.chat-win-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;background:linear-gradient(135deg,var(--primary),#7c3aed);border-radius:24px 24px 0 0}
.chat-win-avatar{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.chat-win-title{font-weight:800;color:#fff;font-size:.95rem}
.chat-win-sub{font-size:.7rem;color:rgba(255,255,255,.75);display:flex;align-items:center;gap:4px}
.chat-win-close{margin-right:auto;width:32px;height:32px;background:rgba(255,255,255,.15);border:none;border-radius:50%;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.chat-win-messages{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;scrollbar-width:thin}
.cwb{max-width:78%;padding:9px 13px;border-radius:14px;font-size:.86rem;line-height:1.6;word-break:break-word}
.cwb-in{background:var(--card2);border-radius:4px 14px 14px 14px;align-self:flex-start;text-align:right;margin-right:auto}
.cwb-out{background:linear-gradient(135deg,var(--primary),#2563eb);color:#fff;border-radius:14px 4px 14px 14px;align-self:flex-end;text-align:right;margin-left:auto}
.cwb-auto{background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.2);border-radius:4px 14px 14px 14px;align-self:flex-start;text-align:right;margin-right:auto}
.cwb-time{font-size:.62rem;opacity:.55;margin-top:3px;display:block}
.cwb-name{font-size:.68rem;font-weight:700;opacity:.65;margin-bottom:3px}
.cwb-auto-tag{font-size:.63rem;color:#00d4aa;font-weight:700;margin-bottom:2px}
.cwb-system{background:rgba(108,63,224,.1);border:1px solid rgba(108,63,224,.25);border-radius:20px;padding:5px 14px;font-size:.72rem;color:#a78bfa;text-align:center;align-self:center;margin:4px auto}
.chat-win-input{padding:10px 12px;border-top:1px solid var(--border);display:flex;gap:8px;align-items:flex-end;flex-shrink:0;background:var(--bg2)}
.chat-win-textarea{flex:1;background:var(--bg3);border:1.5px solid var(--border2);border-radius:10px;padding:9px 12px;color:var(--text);font-family:var(--font);font-size:.87rem;outline:none;resize:none;max-height:100px;min-height:38px}
.chat-win-textarea:focus{border-color:var(--primary)}
.chat-win-send{width:40px;height:40px;background:var(--primary);border:none;border-radius:10px;color:#fff;font-size:.95rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.chat-typing{display:flex;gap:4px;align-items:center;padding:6px 12px;background:var(--card2);border-radius:14px;align-self:flex-start;display:none}
.chat-typing span{width:6px;height:6px;border-radius:50%;background:var(--text3);animation:typingDot 1.2s infinite}
.chat-typing span:nth-child(2){animation-delay:.2s}
.chat-typing span:nth-child(3){animation-delay:.4s}
@keyframes typingDot{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-4px)}}
.chat-online-dot{width:8px;height:8px;border-radius:50%;background:#00e676;display:inline-block;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* ══ المسابقات ══ */
.quiz-overlay{position:fixed;inset:0;background:linear-gradient(135deg,#0a0d1f,#0d1035);z-index:900;display:none;flex-direction:column;overflow:hidden}
.quiz-overlay.open{display:flex}
.quiz-header{padding:16px;display:flex;align-items:center;gap:10px;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,.08)}
.quiz-trophy{font-size:1.8rem}
.quiz-timer{margin-right:auto;background:rgba(255,68,85,.15);border:1px solid rgba(255,68,85,.3);border-radius:20px;padding:5px 14px;font-size:.85rem;font-weight:900;color:#ff4455;display:flex;align-items:center;gap:5px}
.quiz-timer.ok{background:rgba(0,230,118,.1);border-color:rgba(0,230,118,.3);color:#00e676}
.quiz-progress{height:4px;background:rgba(255,255,255,.08);flex-shrink:0}
.quiz-progress-fill{height:100%;background:linear-gradient(90deg,var(--primary),#7c3aed);transition:width .3s}
.quiz-body{flex:1;overflow-y:auto;padding:20px 16px;scrollbar-width:none}
.quiz-q-counter{font-size:.75rem;color:rgba(255,255,255,.5);margin-bottom:6px}
.quiz-question{font-size:1.15rem;font-weight:800;margin-bottom:20px;line-height:1.6;color:#fff}
.quiz-option{width:100%;padding:14px 18px;border-radius:14px;border:1.5px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);color:#fff;font-family:var(--font);font-size:.92rem;font-weight:600;cursor:pointer;transition:.2s;text-align:right;margin-bottom:10px;display:flex;align-items:center;gap:12px}
.quiz-option:active{transform:scale(.98)}
.quiz-option.selected{border-color:var(--primary);background:rgba(30,111,255,.2);color:#fff}
.quiz-option.correct{border-color:#00e676;background:rgba(0,230,118,.2);color:#00e676}
.quiz-option.wrong{border-color:#ff4455;background:rgba(255,68,85,.15);color:#ff4455}
.quiz-opt-letter{width:32px;height:32px;border-radius:50%;border:1.5px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:900;flex-shrink:0}
.quiz-option.selected .quiz-opt-letter{background:var(--primary);border-color:var(--primary)}
.quiz-option.correct .quiz-opt-letter{background:#00e676;border-color:#00e676;color:#000}
.quiz-option.wrong .quiz-opt-letter{background:#ff4455;border-color:#ff4455}
.quiz-nav{padding:14px 16px;display:flex;gap:10px;flex-shrink:0;border-top:1px solid rgba(255,255,255,.08)}
.quiz-btn{flex:1;padding:13px;border-radius:14px;border:none;font-family:var(--font);font-size:.95rem;font-weight:800;cursor:pointer;transition:.2s}
.quiz-btn-next{background:linear-gradient(135deg,var(--primary),#7c3aed);color:#fff}
.quiz-btn-skip{background:rgba(255,255,255,.08);color:rgba(255,255,255,.6)}
/* نتيجة المسابقة */
.quiz-result{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:30px;text-align:center}
.quiz-result-icon{font-size:4rem;margin-bottom:16px;animation:bounceIn .5s}
@keyframes bounceIn{0%{transform:scale(0)}70%{transform:scale(1.2)}100%{transform:scale(1)}}
.quiz-result-score{font-size:3rem;font-weight:900;background:linear-gradient(135deg,#f5a623,#ff6b35);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
/* شاشة البداية */
.quiz-intro{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:30px;text-align:center;gap:16px}
.quiz-intro-prize{font-size:2.5rem;font-weight:900;color:#f5a623;text-shadow:0 0 30px rgba(245,166,35,.4)}
.quiz-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%;max-width:300px}
.quiz-info-card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:12px;text-align:center}
.quiz-info-val{font-size:1.3rem;font-weight:900;margin-bottom:4px}
.quiz-info-lbl{font-size:.72rem;color:rgba(255,255,255,.5)}

/* ══ زر التواصل العائم ══ */
.float-contact-btn {
  position: fixed;
  bottom: calc(var(--nav-h) + 16px);
  left: 16px;
  z-index: 400;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
}
.float-main-btn {
  width: 52px; height: 52px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--primary), #7c3aed);
  border: none;
  color: #fff;
  font-size: 1.3rem;
  cursor: pointer;
  box-shadow: 0 4px 20px rgba(30,111,255,.5);
  display: flex; align-items: center; justify-content: center;
  transition: transform .2s, box-shadow .2s;
  position: relative;
  z-index: 2;
}
.float-main-btn:active { transform: scale(.92); }
.float-main-btn.open { transform: rotate(45deg); background: linear-gradient(135deg,#ff4455,#c62828); box-shadow: 0 4px 20px rgba(255,68,85,.4); }
.float-contact-items {
  display: flex;
  flex-direction: column;
  gap: 9px;
  align-items: flex-start;
  overflow: hidden;
  max-height: 0;
  transition: max-height .35s cubic-bezier(.4,0,.2,1), opacity .25s;
  opacity: 0;
}
.float-contact-items.open {
  max-height: 500px;
  opacity: 1;
}
.float-contact-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 16px 9px 12px;
  border-radius: 30px;
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
  box-shadow: 0 3px 14px rgba(0,0,0,.3);
  white-space: nowrap;
  transform: translateX(-20px);
  transition: transform .25s, box-shadow .2s;
  animation: none;
}
.float-contact-items.open .float-contact-item {
  transform: translateX(0);
}
.float-contact-items.open .float-contact-item:nth-child(1) { transition-delay: .05s; }
.float-contact-items.open .float-contact-item:nth-child(2) { transition-delay: .10s; }
.float-contact-items.open .float-contact-item:nth-child(3) { transition-delay: .15s; }
.float-contact-items.open .float-contact-item:nth-child(4) { transition-delay: .20s; }
.float-contact-items.open .float-contact-item:nth-child(5) { transition-delay: .25s; }
.float-contact-item:active { box-shadow: 0 1px 6px rgba(0,0,0,.2); }
.float-contact-item i { font-size: 1rem; flex-shrink: 0; }
/* overlay خلف الأزرار */
.float-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 399;
}
.float-overlay.open { display: block; }

/* تعديل وضع فاتح */
body.light-mode .float-main-btn {
  box-shadow: 0 4px 20px rgba(30,111,255,.35);
}

/* ══ */
/* transition سلس عند تبديل الوضع */
body.theme-transitioning *{transition:background .25s,background-color .25s,color .2s,border-color .2s,box-shadow .25s !important}
html,body{height:100%;overflow:hidden;background:var(--bg);font-family:var(--font);color:var(--text);direction:rtl;transition:background .25s,color .25s}
#app{width:100%;max-width:430px;height:100dvh;margin:0 auto;display:flex;flex-direction:column;position:relative;overflow:hidden;background:var(--bg);box-shadow:0 0 80px rgba(0,0,0,0.8)}

/* ══════════════════════════════════════════════
   وضع الكمبيوتر — شاشة عريضة
══════════════════════════════════════════════ */
@media (min-width:769px){
  body{overflow:auto;background:var(--bg)}

  /* حاوية خارجية تملأ الشاشة بالكامل */
  #app{
    max-width:100%;
    width:100%;
    height:100dvh;
    flex-direction:row;
    overflow:hidden;
    box-shadow:none;
  }

  /* ── الشريط الجانبي (يحل محل شريط التنقل السفلي) ── */
  .bottom-nav{
    display:flex !important;
    flex-direction:column;
    width:220px;
    height:100%;
    min-height:100dvh;
    border-top:none;
    border-left:1px solid var(--border);
    border-right:none;
    padding:24px 0 16px;
    gap:4px;
    overflow-y:auto;
    flex-shrink:0;
    background:var(--bg2);
    order:-1;
  }

  /* رأس الشريط الجانبي */
  .bottom-nav::before{
    content:'';
    display:block;
    height:4px;
    order:-1;
  }

  .nav-item{
    flex-direction:row;
    justify-content:flex-start;
    padding:12px 20px;
    gap:12px;
    border-radius:12px;
    margin:0 8px;
    border-top:none !important;
    height:auto;
    min-height:46px;
    width:calc(100% - 16px);
  }

  .nav-item .nav-label{
    font-size:13px !important;
    font-weight:700;
    opacity:1 !important;
    display:block !important;
  }

  .nav-item i{
    font-size:18px;
    width:22px;
    text-align:center;
  }

  .nav-item.active{
    background:rgba(30,111,255,.15);
    border-right:3px solid var(--primary);
    border-radius:12px 0 0 12px;
    margin-right:0;
    padding-right:17px;
  }

  /* إخفاء dot التنقل */
  .nav-dot{display:none !important}

  /* ── المحتوى الرئيسي ── */
  .main-column{
    flex:1;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    min-width:0;
  }

  /* الهيدر يمتد عرضياً */
  .top-header{
    flex-shrink:0;
    width:100%;
  }

  .balance-bar{
    flex-shrink:0;
    width:100%;
  }

  /* منطقة المحتوى تمتد */
  .content{
    flex:1;
    overflow-y:auto;
  }

  /* الصفحات تملأ العرض */
  .page{width:100%;max-width:100%}

  /* الشبكات تتكيف مع العرض الكبير */
  .cat-grid{
    grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
  }

  .items-grid{
    grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
  }

  .quick-actions{
    grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
  }

  /* البانر أطول */
  .slider-wrap{height:220px}

  /* الباقات والبطاقات تتكيف */
  .pkgs-grid{
    grid-template-columns:repeat(auto-fill,minmax(180px,1fr)) !important;
  }

  /* الشاشة المنقسمة: خدمات + تفاصيل جنباً لجنب */
  .service-detail-wrap, .order-form-wrap{
    max-width:680px;
    margin:0 auto;
  }

  /* أوراق الـ bottom sheet تصبح مودال في المنتصف */
  .sheet{
    left:50%;
    transform:translateX(-50%) translateY(100%);
    max-width:600px;
    border-radius:var(--radius) var(--radius) 0 0;
  }
  .sheet.open{
    transform:translateX(-50%) translateY(0);
  }

  /* تحسين الـ modals */
  .modal-wrap{
    max-width:560px;
    margin:0 auto;
    border-radius:var(--radius);
    top:50%;
    bottom:auto;
    transform:translate(-50%,-50%);
    left:50%;
    right:auto;
    max-height:85vh;
  }

  /* نافذة الدردشة */
  .chat-window{
    max-width:520px;
    border-radius:var(--radius) var(--radius) 0 0;
  }

  /* زر التواصل العائم - موضع مناسب */
  .float-contact-btn{
    bottom:24px;
    left:24px;
  }
}

/* ── تغليف المحتوى بحاوية للكمبيوتر ── */
@media (min-width:769px){
  /* إنشاء عمود رئيسي حول header + balance + content */
  .top-header,
  .balance-bar,
  .content{
    order:0;
  }
}

/* ── Main Column (mobile: flex col wrapper, desktop: see media query) ── */
.main-column{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}

/* Header */
.top-header{height:var(--header-h);background:linear-gradient(180deg,#0c1526 0%,#080f1e 100%);border-bottom:1px solid rgba(59,130,246,0.1);display:flex;align-items:center;justify-content:space-between;padding:0 16px;flex-shrink:0;position:relative;z-index:100;box-shadow:0 2px 20px rgba(0,0,0,0.4)}
.header-logo{display:flex;align-items:center;gap:8px}
.logo-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--primary),var(--cyan));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 4px 14px var(--primary-glow)}
.logo-text{font-size:18px;font-weight:900;background:linear-gradient(90deg,#e2e8f5 0%,var(--cyan) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.header-right{display:flex;align-items:center;gap:10px}
.header-btn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:16px;transition:all .2s;position:relative;text-decoration:none}
.header-btn:active{transform:scale(.92);background:var(--card3)}
.notif-dot{position:absolute;top:7px;right:7px;width:7px;height:7px;background:var(--red);border-radius:50%;border:1.5px solid var(--bg)}

/* Balance */
.balance-bar{background:linear-gradient(135deg,#0e1d4a 0%,#080d28 100%);padding:10px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(59,130,246,0.12);flex-shrink:0;box-shadow:0 2px 16px rgba(0,0,0,0.3)}
.balance-info{display:flex;align-items:center;gap:10px}
.balance-icon{width:34px;height:34px;background:rgba(255,255,255,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
.balance-label{font-size:11px;color:rgba(255,255,255,0.6)}
.balance-value{font-size:18px;font-weight:900;color:#fff;line-height:1}
.balance-currency{font-size:11px;color:rgba(255,255,255,0.6)}
.balance-right{display:flex;align-items:center;gap:8px}
.balance-reload{background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.2);border-radius:10px;padding:6px 14px;color:#fff;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px}
.balance-reload:active{transform:scale(.95)}

/* Content */
.content{flex:1;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;scroll-behavior:smooth;position:relative}
.content::-webkit-scrollbar{display:none}
.page{display:none;animation:fadeIn .25s ease}
.page.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* Hero */
/* ── Hero Slider ── */
.slider-wrap{margin:12px;border-radius:var(--radius);overflow:hidden;position:relative;height:160px;cursor:pointer}
.slider-track{display:flex;height:100%;transition:transform .45s cubic-bezier(.4,0,.2,1);will-change:transform}
.slide{min-width:100%;height:100%;position:relative;overflow:hidden;flex-shrink:0}
.slide-bg{position:absolute;inset:0}
.slide-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:1}
.slide.has-text .slide-img{opacity:.45} /* خافتة فقط عندما يوجد نص فوقها */
.slide-content{position:absolute;inset:0;padding:16px 20px;display:flex;flex-direction:column;justify-content:space-between}
.slide-tag{font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-block;width:fit-content;border:1px solid}
.slide-title{font-size:26px;font-weight:900;line-height:1.2;text-shadow:0 2px 10px rgba(0,0,0,0.5)}
.slide-sub{font-size:12px;opacity:.75}
.slider-dots{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);display:flex;gap:5px;z-index:10}
.slider-dot{width:6px;height:6px;border-radius:3px;background:rgba(255,255,255,0.35);transition:all .3s;border:none;padding:0;cursor:pointer}
.slider-dot.active{width:18px;background:#fff}
.slider-arrow{position:absolute;top:50%;transform:translateY(-50%);width:28px;height:28px;background:rgba(0,0,0,0.3);border:none;border-radius:50%;color:#fff;font-size:12px;cursor:pointer;z-index:10;display:flex;align-items:center;justify-content:center;opacity:0;transition:.2s}
.slider-wrap:hover .slider-arrow{opacity:1}
.slider-arrow.prev{right:10px}
.slider-arrow.next{left:10px}

/* Sec */
.sec-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 8px}
.sec-title{font-size:15px;font-weight:800;color:#fff}
.sec-more{font-size:12px;color:var(--primary);font-weight:700;cursor:pointer;display:flex;align-items:center;gap:3px}

/* Cat Grid */
.cat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:0 12px 12px}
.cat-card{border-radius:var(--radius);overflow:hidden;cursor:pointer;transition:transform .2s,box-shadow .2s;border:1px solid rgba(255,255,255,0.08);box-shadow:0 4px 16px rgba(0,0,0,0.4);display:flex;flex-direction:column}
.cat-card:active{transform:scale(.96)}
.cat-card-imgwrap{position:relative;aspect-ratio:1;overflow:hidden}
.cat-card-bg{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:36px}
/* صندوق الاسم أسفل الصورة مباشرة (مستقل) — أبيض بالوضع الليلي، أسود بالوضع النهاري */
.cat-card-namebox{background:#ffffff;padding:7px 6px;text-align:center}
.cat-card-name{font-size:10px;font-weight:800;color:#0f172a;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cat-card-badge{display:none}
.cat-card-logo{display:none}

/* Quick Actions */
.quick-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 12px 12px}
.qa-item{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 8px;text-align:center;cursor:pointer;transition:all .2s;box-shadow:0 2px 10px rgba(0,0,0,0.25)}
.qa-item:active{transform:scale(.93);background:var(--card2)}
.qa-item:active{transform:scale(.93);background:var(--card2)}
.qa-icon{width:44px;height:44px;border-radius:14px;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;font-size:20px}
.qa-label{font-size:11px;color:var(--text2);font-weight:600}

/* Page Header */
.page-header-img{height:180px;position:relative;overflow:hidden}
.page-header-img-bg{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:80px;filter:blur(2px) brightness(.4);transform:scale(1.1)}
.page-header-img-overlay{position:absolute;inset:0;background:linear-gradient(180deg,rgba(8,12,26,.3) 0%,rgba(8,12,26,.95) 100%)}
.page-header-img-content{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:flex-end;padding:16px}
.phic-back{position:absolute;top:12px;right:12px;width:36px;height:36px;background:rgba(0,0,0,.5);border-radius:50%;border:none;color:#fff;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.phic-title{font-size:32px;font-weight:900;color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.5)}
.phic-sub{font-size:12px;color:rgba(255,255,255,0.6)}

/* Search */
.search-bar{margin:12px;background:var(--card);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;gap:10px;padding:10px 14px}
.search-bar i{color:var(--text3);font-size:16px}
.search-bar input{flex:1;background:none;border:none;outline:none;color:#fff;font-family:var(--font);font-size:14px;text-align:right}
.search-bar input::placeholder{color:var(--text3)}

/* Items Grid */
.items-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:0 12px 12px}
.game-item{cursor:pointer;text-align:center;transition:transform .2s}
.game-item:active{transform:scale(.93)}
.game-thumb{width:100%;aspect-ratio:1;border-radius:var(--radius-sm);overflow:hidden;position:relative;border:1.5px solid rgba(255,255,255,0.1);background:var(--card2);display:flex;align-items:center;justify-content:center}
.game-thumb-icon{font-size:34px}
.game-sub-badge{position:absolute;top:6px;right:6px;background:rgba(30,111,255,.85);backdrop-filter:blur(4px);color:#fff;font-size:.65rem;font-weight:800;padding:2px 7px;border-radius:8px;display:flex;align-items:center;gap:3px;z-index:2;border:1px solid rgba(255,255,255,.15)}
.game-sub-badge.svc{background:rgba(0,180,120,.85)}
.game-thumb-overlay{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(0deg,rgba(0,0,0,.8) 0%,transparent 100%);padding:6px}
.game-thumb-brand{font-size:7px;color:var(--cyan);font-weight:900;letter-spacing:.3px;text-align:right;line-height:1}
.game-thumb-brand-sub{font-size:5.5px;color:rgba(0,212,255,0.7)}
/* ── Unavailable items ── */
.unavail-badge{position:absolute;top:0;left:0;right:0;background:linear-gradient(90deg,rgba(120,80,20,0.97),rgba(160,110,30,0.97));color:#ffe082;font-size:8px;font-weight:900;padding:4px 0;text-align:center;letter-spacing:1px;z-index:4;backdrop-filter:blur(2px)}
.cat-card.unavail{filter:saturate(0.25) brightness(0.7)}
.cat-card.unavail .cat-unavail-strip{display:flex!important}
.cat-unavail-strip{display:none;position:absolute;bottom:0;left:0;right:0;background:linear-gradient(90deg,rgba(100,65,10,0.97),rgba(150,100,20,0.97));color:#ffe082;font-size:9px;font-weight:900;padding:5px 0;text-align:center;letter-spacing:1.5px;z-index:4;align-items:center;justify-content:center;gap:5px}
.game-item.unavail .game-thumb{filter:saturate(0.2) brightness(0.65)}
.game-item.unavail .game-name{color:#888}
.game-name{font-size:10px;color:var(--text2);margin-top:5px;font-weight:600}

/* Packages */
.action-tabs{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--border)}
.action-tab-btn{flex:1;text-align:center;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:8px;color:var(--text2);font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px}
.action-tab-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}
.packages-list{padding:10px 12px;display:flex;flex-direction:column;gap:8px}
.pkg-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);display:flex;align-items:center;gap:12px;padding:13px;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.3)}
.pkg-card:active{transform:scale(.97);background:var(--card2)}
.pkg-card:active{transform:scale(.97)}
.pkg-card.unavail{filter:saturate(0.2) brightness(0.65);pointer-events:all;cursor:pointer}
.pkg-unavail-ribbon{position:absolute;top:0;left:0;right:0;background:linear-gradient(90deg,rgba(120,80,20,0.97),rgba(160,110,30,0.97));color:#ffe082;font-size:8px;font-weight:900;padding:4px 0;text-align:center;letter-spacing:1px;z-index:4}
.pkg-icon{width:56px;height:56px;border-radius:12px;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:28px;background:var(--card2);border:1px solid var(--border)}
.pkg-info{flex:1}
.pkg-name{font-size:14px;font-weight:800}
.pkg-cat{font-size:10px;color:var(--primary);margin-bottom:2px}
.pkg-prices{display:flex;align-items:center;gap:8px;margin-top:6px}
.pkg-price-main{background:var(--primary);color:#fff;font-size:13px;font-weight:900;padding:4px 12px;border-radius:8px}
.pkg-price-usd{background:rgba(255,255,255,0.08);border:1px dashed rgba(255,255,255,0.2);color:var(--text2);font-size:12px;font-weight:700;padding:4px 10px;border-radius:8px}
.cur-picker-ov{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:8000;display:flex;align-items:flex-end;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s}
.cur-picker-ov.open{opacity:1;pointer-events:all}
.cur-picker-box{background:var(--bg2);border-radius:24px 24px 0 0;width:100%;max-width:440px;max-height:70vh;display:flex;flex-direction:column;transform:translateY(40px);transition:transform .3s}
.cur-picker-ov.open .cur-picker-box{transform:translateY(0)}
.cur-item{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .15s}
.cur-item:active{background:var(--card2)}
.cur-item.active{background:rgba(30,111,255,.08)}

/* Bottom Sheet */
.sheet-overlay{position:absolute;inset:0;background:rgba(0,0,0,.7);z-index:500;display:none;backdrop-filter:blur(2px)}
.sheet-overlay.show{display:block;animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.bottom-sheet{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(180deg,#141c30 0%,#0d1428 100%);border-radius:24px 24px 0 0;z-index:600;transform:translateY(100%);transition:transform .35s cubic-bezier(.34,1.15,.64,1);max-height:90dvh;overflow-y:auto}
.bottom-sheet.open{transform:translateY(0)}
.sheet-handle{width:40px;height:4px;background:rgba(255,255,255,0.15);border-radius:2px;margin:12px auto 0}
.sheet-header{padding:14px 16px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.sheet-title{font-size:16px;font-weight:800}
.sheet-pkg{font-size:12px;color:var(--primary)}
.sheet-close{width:32px;height:32px;background:rgba(255,255,255,0.07);border-radius:50%;border:none;color:var(--text2);font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.sheet-warning{margin:12px 14px;background:rgba(245,166,35,0.12);border:1px solid rgba(245,166,35,0.3);border-radius:12px;padding:10px 14px;display:flex;gap:10px;align-items:flex-start}
.sheet-warning i{color:var(--gold);margin-top:2px;flex-shrink:0}
.sheet-warning p{font-size:12px;color:rgba(255,255,255,0.8);line-height:1.5}
.sheet-fields{padding:0 14px}
.sheet-field{margin-bottom:12px}
.sheet-field label{display:block;font-size:12px;color:var(--text2);margin-bottom:6px;font-weight:600}
.field-icon-wrap{position:relative}
.field-icon{position:absolute;top:50%;right:12px;transform:translateY(-50%);color:var(--text3);font-size:18px}
.field-input{width:100%;background:rgba(255,255,255,0.05);border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;color:#fff;font-family:var(--font);font-size:15px;outline:none;transition:border .2s;direction:ltr;text-align:right}
.field-input:focus{border-color:var(--primary)}
.field-input.has-icon{padding-right:42px}
.sheet-total{margin:12px 14px;background:rgba(30,111,255,0.08);border:1px solid rgba(30,111,255,0.2);border-radius:12px;padding:12px 14px}
.sheet-total-row{display:flex;justify-content:space-between;align-items:center}
.sheet-total-label{font-size:12px;color:var(--text2)}
.sheet-total-value{font-size:14px;font-weight:900;color:var(--cyan)}
.sheet-balance-row{display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px solid var(--border)}
.sheet-buy-btn{margin:14px 14px 20px;width:calc(100% - 28px);background:linear-gradient(135deg,var(--primary) 0%,#0a3fbe 100%);border:none;border-radius:14px;padding:16px;color:#fff;font-family:var(--font);font-size:17px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 6px 20px rgba(30,111,255,0.4);transition:all .2s}
.sheet-buy-btn:active{transform:scale(.97)}
.sheet-buy-btn:disabled{background:#333;box-shadow:none;opacity:.6;cursor:not-allowed}

/* Orders */
.orders-page{padding:12px}
.order-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:10px;display:flex;gap:12px;align-items:center}
.order-icon{width:48px;height:48px;border-radius:12px;background:var(--card2);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.order-info{flex:1}
.order-name{font-size:13px;font-weight:700}
.order-id{font-size:10px;color:var(--text3)}
.order-price{font-size:14px;font-weight:900;color:var(--cyan);margin-top:4px}
.order-status{padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap}
.order-reason{font-size:10px;color:var(--text3);margin-top:4px;line-height:1.4;display:flex;align-items:flex-start;gap:4px}
.order-reason.cancelled{color:#ff6b6b}
.order-reason.processing{color:#00d4ff}
.order-reason.completed{color:#00e676}
.order-card{cursor:pointer;transition:background .15s}
.order-card:active{background:var(--card2)}
.status-pending{background:rgba(245,166,35,0.15);color:var(--gold)}
.status-done,.status-completed{background:rgba(0,230,118,0.15);color:var(--green)}
.status-process,.status-processing{background:rgba(30,111,255,0.15);color:var(--primary)}
.status-fail,.status-cancelled,.status-failed{background:rgba(255,23,68,0.15);color:var(--red)}

/* Wallet */
.wallet-page{padding:12px}
.wallet-card{background:linear-gradient(135deg,#0c1d5e 0%,#0d1d4a 40%,#07102e 100%);border-radius:var(--radius);padding:24px 20px;margin-bottom:16px;position:relative;overflow:hidden;border:1px solid rgba(59,130,246,0.15);box-shadow:0 12px 40px rgba(0,0,0,0.5)}
.wallet-card::before{content:'';position:absolute;top:-40%;right:-20%;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(34,211,238,0.1) 0%,transparent 70%)}
.wallet-card::after{content:'';position:absolute;bottom:-30%;left:-10%;width:150px;height:150px;border-radius:50%;background:radial-gradient(circle,rgba(59,130,246,0.08) 0%,transparent 70%)}
.wallet-balance-label{font-size:12px;color:rgba(255,255,255,0.6);margin-bottom:4px}
.wallet-balance-amount{font-size:38px;font-weight:900}
.wallet-balance-cur{font-size:14px;color:rgba(255,255,255,0.7)}
.wallet-actions{display:flex;gap:10px;margin-top:16px}
.wallet-action-btn{flex:1;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:10px;color:#fff;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;transition:all .15s}
.wallet-action-btn:active{background:rgba(255,255,255,.22);transform:scale(.97)}
.wallet-tx-item{display:flex;align-items:center;gap:12px;padding:13px 12px;background:var(--card);border-radius:var(--radius-sm);transition:background .15s}
.wallet-tx-item:active{background:var(--card2)}
.wallet-filter-btn{flex:1;padding:7px 0;background:var(--card);border:1px solid var(--border);border-radius:10px;color:var(--text2);font-size:11px;font-weight:700;cursor:pointer;text-align:center;font-family:var(--font);transition:all .2s}
.wallet-filter-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}
.tx-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.tx-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.tx-icon.credit{background:rgba(0,230,118,0.12);color:var(--green)}
.tx-icon.debit{background:rgba(255,23,68,0.12);color:var(--red)}
.tx-info{flex:1}
.tx-name{font-size:13px;font-weight:700}
.tx-date{font-size:10px;color:var(--text3)}
.tx-amount{font-size:15px;font-weight:900}
.tx-amount.credit{color:var(--green)}
.tx-amount.debit{color:var(--red)}

/* Profile */
.profile-page{padding:12px}
.profile-header-card{background:linear-gradient(135deg,#0d1f5c,#1a3a8c);border-radius:var(--radius);padding:24px 20px;text-align:center;margin-bottom:14px}
.profile-avatar{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--cyan));margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:30px;box-shadow:0 4px 20px rgba(30,111,255,0.4)}
.profile-name{font-size:18px;font-weight:900}
.profile-id{font-size:11px;color:rgba(255,255,255,0.6);margin-top:4px}
.profile-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(0,230,118,0.15);border:1px solid rgba(0,230,118,0.3);color:var(--green);font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;margin-top:8px}
.profile-menu-item{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;display:flex;align-items:center;gap:12px;margin-bottom:8px;cursor:pointer;transition:all .2s;text-decoration:none;color:var(--text)}
.profile-menu-item:active{background:var(--card2)}
.pmi-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px}
.pmi-label{flex:1;font-size:13px;font-weight:700}
.pmi-arrow{color:var(--text3);font-size:12px}

/* Bottom Nav */
.notif-page-wrap{padding:14px 12px}
.notif-page-topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.notif-page-heading{font-size:18px;font-weight:900;display:flex;align-items:center;gap:8px}
.notif-page-heading i{color:var(--primary)}
.notif-unread-count{background:var(--red);color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:20px;min-width:20px;text-align:center}
.notif-clear-btn{background:rgba(255,23,68,0.1);border:1px solid rgba(255,23,68,0.2);color:var(--red);font-size:11px;font-weight:700;padding:6px 12px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:5px;font-family:var(--font)}
.notif-clear-btn:active{transform:scale(.95)}
.notif-filter-row{display:flex;gap:6px;margin-bottom:14px}
.notif-filter-btn{flex:1;padding:7px 0;background:var(--card);border:1px solid var(--border);border-radius:10px;color:var(--text2);font-size:11px;font-weight:700;cursor:pointer;text-align:center;font-family:var(--font);transition:all .2s}
.notif-filter-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}
.notif-list-wrap{display:flex;flex-direction:column;gap:8px}
.notif-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;gap:12px;align-items:flex-start;position:relative;transition:all .2s;cursor:pointer}
.notif-card.unread{border-right:3px solid var(--primary);background:rgba(30,111,255,0.04)}
.notif-card:active{transform:scale(.98)}
.notif-card-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.notif-card-body{flex:1;min-width:0}
.notif-card-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notif-card-msg{font-size:12px;color:var(--text2);line-height:1.5}
.notif-card-time{font-size:10px;color:var(--text3);margin-top:5px;display:flex;align-items:center;gap:4px}
.notif-card-link{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--primary);margin-top:5px;font-weight:700}
.notif-card-del{position:absolute;top:10px;left:10px;width:26px;height:26px;border-radius:8px;background:transparent;border:none;color:var(--text3);font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;font-family:var(--font)}
.notif-card-del:hover{background:rgba(255,23,68,0.1);color:var(--red)}
.notif-unread-dot{width:7px;height:7px;background:var(--primary);border-radius:50%;flex-shrink:0;margin-top:5px}
.notif-empty-state{text-align:center;padding:50px 20px;color:var(--text3)}
.notif-empty-state .empty-icon{font-size:52px;margin-bottom:14px;display:block;opacity:.25}
.notif-empty-state p{font-size:14px;font-weight:600;margin-bottom:4px;color:var(--text2)}
.notif-empty-state small{font-size:12px}
.bottom-nav{height:var(--nav-h);background:rgba(5,8,15,0.97);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-top:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;flex-shrink:0;position:relative;z-index:200;box-shadow:0 -4px 24px rgba(0,0,0,0.5)}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;padding:8px 0;transition:all .2s;position:relative}
.nav-item:active{transform:scale(.9)}
.nav-icon{font-size:20px;color:var(--text3);transition:all .2s}
.nav-label{font-size:10px;color:var(--text3);font-weight:600;transition:all .2s}
.nav-item.active .nav-icon,.nav-item.active .nav-label{color:var(--primary)}
.nav-item.active::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:32px;height:3px;border-radius:0 0 4px 4px;background:linear-gradient(90deg,var(--primary),var(--cyan));box-shadow:0 2px 10px var(--primary-glow)}
.nav-center{flex:1;display:flex;justify-content:center}
.nav-center-btn{width:52px;height:52px;background:linear-gradient(135deg,var(--primary),#0a3fbe);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;cursor:pointer;box-shadow:0 4px 16px rgba(30,111,255,0.5);margin-top:-14px;transition:transform .2s;border:3px solid var(--bg)}
.nav-center-btn:active{transform:scale(.9)}

/* Toast */
.toast{position:absolute;top:70px;left:50%;transform:translateX(-50%) translateY(-20px);background:rgba(15,30,70,0.95);border:1px solid rgba(30,111,255,0.4);color:#fff;font-size:13px;font-weight:700;padding:10px 20px;border-radius:20px;white-space:nowrap;opacity:0;pointer-events:none;transition:all .3s;z-index:9999;backdrop-filter:blur(10px)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* auth-redirect notice */
.auth-notice{background:rgba(30,111,255,0.1);border:1px solid rgba(30,111,255,0.3);border-radius:12px;padding:12px 16px;margin:12px;text-align:center;font-size:13px}
.auth-notice a{color:var(--cyan);font-weight:700;text-decoration:none}
.empty-state-app{text-align:center;padding:40px 20px;color:var(--text2)}
.empty-state-app i{font-size:48px;opacity:.2;display:block;margin-bottom:12px}

/* ══════════════════════════════════════
   AUTH GATE BOX
══════════════════════════════════════ */
.auth-gate-box{
  background:linear-gradient(135deg,rgba(30,111,255,0.08),rgba(0,212,255,0.05));
  border:1.5px solid rgba(30,111,255,0.25);
  border-radius:20px;padding:32px 20px;text-align:center;
  cursor:pointer;transition:all .3s;margin:16px 0;
}
.auth-gate-box:active{transform:scale(.98);}
.auth-gate-icon{font-size:52px;margin-bottom:12px;display:block;animation:float 3s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.auth-gate-title{font-size:17px;font-weight:900;margin-bottom:6px}
.auth-gate-sub{font-size:12px;color:var(--text2);margin-bottom:16px}
.auth-gate-btn{display:inline-flex;align-items:center;gap:6px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;padding:10px 24px;border-radius:12px;cursor:pointer;transition:all .2s}
.auth-gate-btn:active{transform:scale(.95)}

/* ══════════════════════════════════════
   AUTH MODAL (Full Bottom Sheet)
══════════════════════════════════════ */
.auth-overlay{
  position:absolute;inset:0;
  background:rgba(0,0,0,0);
  z-index:800;display:none;
  backdrop-filter:blur(0px);
  transition:all .3s;
}
.auth-overlay.show{
  display:block;
  background:rgba(0,0,0,0.75);
  backdrop-filter:blur(4px);
}
.auth-sheet{
  position:absolute;bottom:0;left:0;right:0;
  background:linear-gradient(180deg,#0f1929 0%,#080c1a 100%);
  border-radius:28px 28px 0 0;
  z-index:900;
  transform:translateY(100%);
  transition:transform .4s cubic-bezier(.32,1.2,.6,1);
  max-height:92dvh;
  overflow-y:auto;
  border-top:1px solid rgba(30,111,255,0.2);
}
.auth-sheet.open{transform:translateY(0)}
.auth-sheet::-webkit-scrollbar{display:none}

.auth-handle{width:40px;height:4px;background:rgba(255,255,255,0.12);border-radius:2px;margin:14px auto 0}

/* Auth Header */
.auth-header{padding:20px 20px 0;text-align:center}
.auth-logo-big{
  width:64px;height:64px;
  background:linear-gradient(135deg,var(--primary),var(--cyan));
  border-radius:20px;margin:0 auto 14px;
  display:flex;align-items:center;justify-content:center;
  font-size:28px;
  box-shadow:0 8px 24px rgba(30,111,255,0.4);
  animation:pulse-glow 2s ease-in-out infinite;
}
@keyframes pulse-glow{
  0%,100%{box-shadow:0 8px 24px rgba(30,111,255,0.4)}
  50%{box-shadow:0 8px 40px rgba(0,212,255,0.6)}
}
.auth-header-title{font-size:22px;font-weight:900;margin-bottom:4px}
.auth-header-sub{font-size:12px;color:var(--text2)}

/* Tabs */
.auth-tabs{
  display:flex;margin:20px 20px 0;
  background:rgba(255,255,255,0.04);
  border-radius:14px;padding:4px;
  border:1px solid var(--border);
}
.auth-tab{
  flex:1;text-align:center;padding:10px;
  border-radius:11px;font-size:13px;font-weight:800;
  cursor:pointer;transition:all .25s;color:var(--text2);
}
.auth-tab.active{background:var(--primary);color:#fff;box-shadow:0 4px 12px rgba(30,111,255,0.35)}

/* Forms */
.auth-form{padding:20px;display:none}
.auth-form.active{display:block;animation:fadeIn .2s ease}

.auth-field{margin-bottom:14px;position:relative}
.auth-field-label{
  font-size:11px;color:var(--text2);font-weight:700;
  margin-bottom:6px;display:flex;align-items:center;gap:6px;
}
.auth-field-label i{color:var(--primary);font-size:12px}
.auth-input-wrap{position:relative}
.auth-input{
  width:100%;
  background:rgba(255,255,255,0.04);
  border:1.5px solid var(--border);
  border-radius:14px;
  padding:14px 14px 14px 44px;
  color:#fff;font-family:var(--font);font-size:15px;
  outline:none;transition:all .25s;
  direction:rtl;
}
.auth-input:focus{border-color:var(--primary);background:rgba(30,111,255,0.06);box-shadow:0 0 0 3px rgba(30,111,255,0.12)}
.auth-input.error{border-color:var(--red);background:rgba(255,23,68,0.05)}
.auth-input.success{border-color:var(--green)}
.auth-input-icon{
  position:absolute;top:50%;left:14px;transform:translateY(-50%);
  color:var(--text3);font-size:16px;pointer-events:none;transition:color .2s;
}
.auth-input:focus ~ .auth-input-icon{color:var(--primary)}

/* Password toggle */
.auth-pw-toggle{
  position:absolute;top:50%;right:14px;transform:translateY(-50%);
  color:var(--text3);font-size:15px;cursor:pointer;padding:4px;
}

/* Error message */
.auth-error{
  background:rgba(255,23,68,0.1);
  border:1px solid rgba(255,23,68,0.3);
  border-radius:10px;padding:10px 14px;
  font-size:12px;color:var(--red);
  margin-bottom:14px;display:none;
  animation:shake .3s ease;
  display:flex;align-items:center;gap:8px;
}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
.auth-error.show{display:flex}

/* Submit button */
.auth-submit{
  width:100%;background:linear-gradient(135deg,var(--primary),#0a3fbe);
  border:none;border-radius:14px;padding:16px;
  color:#fff;font-family:var(--font);font-size:16px;font-weight:900;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
  box-shadow:0 6px 20px rgba(30,111,255,0.4);transition:all .2s;
  margin-top:4px;
}
.auth-submit:active{transform:scale(.97)}
.auth-submit:disabled{opacity:.6;cursor:not-allowed;transform:none}
.auth-submit.loading{pointer-events:none}
.auth-submit .spin{animation:spin .7s linear infinite;display:none}
.auth-submit.loading .spin{display:inline-block}
.auth-submit.loading .btn-text{display:none}
@keyframes spin{to{transform:rotate(360deg)}}

/* Divider */
.auth-google-btn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:13px;border-radius:14px;border:1.5px solid rgba(66,133,244,.3);background:rgba(66,133,244,.06);color:var(--text);font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .2s;margin-bottom:4px}
.auth-google-btn:hover,.auth-google-btn:active{background:rgba(66,133,244,.12);border-color:rgba(66,133,244,.5)}
.auth-divider{display:flex;align-items:center;gap:12px;margin:16px 0;color:var(--text3);font-size:11px}
.auth-divider::before,.auth-divider::after{content:'';flex:1;height:1px;background:var(--border)}

/* Switch link */
.auth-switch{text-align:center;font-size:12px;color:var(--text2);margin-top:14px;padding-bottom:8px}
.auth-switch span{color:var(--cyan);font-weight:800;cursor:pointer}
.auth-switch span:active{opacity:.7}

/* Success screen */
.auth-success{
  text-align:center;padding:30px 20px;display:none;
  animation:fadeIn .4s ease;
}
.auth-success.show{display:block}
.auth-success-icon{
  font-size:64px;margin-bottom:16px;
  animation:pop .4s cubic-bezier(.34,1.56,.64,1);
}
@keyframes pop{from{transform:scale(0)}to{transform:scale(1)}}
.auth-success-title{font-size:20px;font-weight:900;color:var(--green);margin-bottom:8px}
.auth-success-sub{font-size:13px;color:var(--text2)}

/* strength meter */
.pw-strength{height:3px;border-radius:2px;background:var(--border);margin-top:6px;overflow:hidden}
.pw-strength-bar{height:100%;width:0;border-radius:2px;transition:all .3s}
.pw-strength-label{font-size:10px;margin-top:4px;font-weight:700}

/* Logged-in user chip in header */
.user-chip{
  display:flex;align-items:center;gap:6px;
  background:rgba(0,212,255,0.1);
  border:1px solid rgba(0,212,255,0.25);
  border-radius:20px;padding:4px 12px 4px 6px;
  cursor:pointer;transition:all .2s;
}
.user-chip:active{transform:scale(.95)}
.user-avatar{
  width:26px;height:26px;border-radius:50%;
  background:linear-gradient(135deg,var(--primary),var(--cyan));
  display:flex;align-items:center;justify-content:center;
  font-size:12px;color:#fff;font-weight:900;
}
.user-name-chip{font-size:11px;font-weight:700;color:var(--cyan);max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ═══════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════ */
.sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,0);z-index:800;pointer-events:none;transition:background .3s}
.sidebar-overlay.open{background:rgba(0,0,0,.65);pointer-events:auto;backdrop-filter:blur(2px)}
.sidebar{position:absolute;top:0;right:-290px;width:290px;height:100%;background:linear-gradient(180deg,#0c1525 0%,#05080f 100%);z-index:900;transition:right .3s cubic-bezier(.4,0,.2,1);border-left:1px solid rgba(59,130,246,0.1);display:flex;flex-direction:column;overflow-y:auto}
.sidebar.open{right:0;box-shadow:-20px 0 60px rgba(0,0,0,0.7)}
.sidebar.open{right:0;box-shadow:-20px 0 60px rgba(0,0,0,.6)}
.sidebar-top{padding:20px 16px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}
.sidebar-user{display:flex;align-items:center;gap:12px}
.sidebar-avatar{width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,var(--primary),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:900;color:#fff;flex-shrink:0}
.sidebar-username{font-weight:800;font-size:.95rem;color:#fff}
.sidebar-balance{font-size:.78rem;color:#8895a7;margin-top:2px}
.sidebar-close{background:var(--card2);border:1px solid var(--border);border-radius:50%;width:32px;height:32px;color:#8895a7;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}

/* بطاقة شحن الرصيد في السايدبار */
.sidebar-topup-card{margin:12px 10px;border-radius:16px;background:linear-gradient(135deg,#1e6fff 0%,#7c3aed 60%,#00c9a7 100%);padding:14px 16px;cursor:pointer;position:relative;overflow:hidden;box-shadow:0 6px 24px rgba(30,111,255,.4);transition:transform .15s,box-shadow .15s;display:block}
.sidebar-topup-card:active{transform:scale(.97);box-shadow:0 4px 16px rgba(30,111,255,.3)}
.sidebar-topup-glow{position:absolute;top:-30px;right:-30px;width:100px;height:100px;background:radial-gradient(circle,rgba(255,255,255,.2) 0%,transparent 70%);pointer-events:none}
.sidebar-topup-content{display:flex;align-items:center;gap:12px;position:relative}
.sidebar-topup-icon{width:40px;height:40px;background:rgba(255,255,255,.2);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff;flex-shrink:0}
.sidebar-topup-title{font-size:1rem;font-weight:900;color:#fff}
.sidebar-topup-sub{font-size:.75rem;color:rgba(255,255,255,.75);margin-top:2px}
.sidebar-topup-arrow{color:rgba(255,255,255,.7);font-size:.85rem;margin-right:auto}

.sidebar-menu{padding:8px 0;flex:1}
.sidebar-menu-label{font-size:.7rem;color:#8895a7;font-weight:700;text-transform:uppercase;letter-spacing:.8px;padding:10px 16px 6px}
.sidebar-item{display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer;transition:background .15s;font-size:.9rem;font-weight:700;color:var(--text);border:none;background:none;font-family:var(--font);width:100%;text-align:right}
.sidebar-item:active{background:rgba(255,255,255,.05)}
.sidebar-item-icon{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.sidebar-item-badge{margin-right:auto;font-size:.72rem;color:#00d4aa;font-weight:900;background:rgba(0,212,170,.1);padding:2px 8px;border-radius:10px}
.sidebar-divider{height:1px;background:var(--border);margin:6px 16px}

.hamburger-btn{background:var(--card2);border:1px solid var(--border);border-radius:12px;width:38px;height:38px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:16px;flex-shrink:0;transition:.2s}

/* ═══════════════════════════════════════
   TOPUP DRAWER — REDESIGN
═══════════════════════════════════════ */

/* ── Category Breadcrumb Nav ── */
.cat-nav-crumbs{background:var(--card2);border-bottom:1px solid var(--border);overflow:hidden}
.cat-crumbs-scroll{display:flex;align-items:center;overflow-x:auto;padding:8px 12px;scrollbar-width:none;white-space:nowrap;gap:2px}
.cat-crumbs-scroll::-webkit-scrollbar{display:none}
.cat-crumb-btn{background:none;border:none;font-family:var(--font);font-size:.78rem;color:#8895a7;cursor:pointer;padding:4px 8px;border-radius:8px;transition:.15s;white-space:nowrap}
.cat-crumb-btn:active,.cat-crumb-btn:hover{background:rgba(255,255,255,.06);color:#cbd5e1}
.cat-crumb-btn.current{color:#fff;font-weight:800;background:rgba(255,255,255,.07);pointer-events:none}
.cat-crumb-sep{color:var(--border);font-size:.6rem;flex-shrink:0;padding:0 2px}

.topup-overlay{display:none}
.topup-drawer{background:transparent;width:100%}
@keyframes slideUp{from{transform:translateY(100%);opacity:.5}to{transform:translateY(0);opacity:1}}
.topup-drag-bar{width:40px;height:4px;background:var(--border);border-radius:2px;margin:10px auto 0}
.topup-header{padding:14px 16px;display:flex;align-items:center;gap:10px}
.topup-header-title{font-size:1rem;font-weight:900}
.topup-header-sub{font-size:.75rem;color:#8895a7;margin-top:1px}
.topup-close-btn{width:32px;height:32px;background:var(--card2);border:1px solid var(--border);border-radius:50%;color:#8895a7;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.9rem}
.topup-back-btn{width:32px;height:32px;background:var(--card2);border:1px solid var(--border);border-radius:50%;color:#8895a7;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.9rem}
.topup-balance-strip{display:flex;justify-content:space-between;align-items:center;background:rgba(0,212,170,.06);border-top:1px solid rgba(0,212,170,.12);border-bottom:1px solid rgba(0,212,170,.12);padding:8px 16px;margin:0 0 4px}

/* قائمة طرق الدفع */
.topup-methods-list{padding:8px 12px 20px}
.topup-empty{text-align:center;padding:2.5rem 1rem;color:#8895a7}
.topup-empty i{font-size:2.5rem;opacity:.2;display:block;margin-bottom:.8rem}
.topup-empty p{font-weight:700;margin-bottom:4px}
.topup-empty span{font-size:.8rem}
.topup-mode-tabs{display:flex;gap:8px;padding:10px 12px 6px}
.topup-mode-tab{flex:1;padding:10px 6px;border-radius:14px;border:1.5px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:3px}
.topup-mode-tab.active{border-color:var(--primary);background:rgba(30,111,255,.1);color:var(--primary)}
.topup-mode-tab .tab-icon{font-size:22px}
.topup-mode-desc{font-size:11px;color:var(--text3);padding:0 14px 8px;text-align:center;line-height:1.5}
.topup-method-item{display:flex;align-items:center;gap:12px;padding:14px;background:var(--card2);border:1.5px solid var(--border);border-radius:16px;margin-bottom:10px;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.topup-method-item::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--mc,#6c3fe0)08,transparent);pointer-events:none}
.topup-method-item:active{transform:scale(.98);border-color:var(--mc,var(--primary))}
.topup-method-thumb{width:48px;height:48px;border-radius:14px;background:color-mix(in srgb, var(--mc,#6c3fe0) 20%,transparent);color:var(--mc,#6c3fe0);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;border:1.5px solid color-mix(in srgb, var(--mc,#6c3fe0) 30%,transparent)}
.topup-method-name{font-weight:800;font-size:.95rem}
.topup-method-desc{font-size:.75rem;color:#8895a7;margin-top:3px}
.topup-method-arrow{color:#8895a7;font-size:.8rem;margin-right:auto;transition:transform .2s}
.topup-method-item:active .topup-method-arrow{transform:translateX(-4px)}

/* بطاقة بيانات الحساب */
.topup-account-card{margin:0 12px 12px;border-radius:16px;border:1.5px solid var(--border);background:var(--card2);overflow:hidden}
.topup-account-header{padding:12px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02)}
.topup-account-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.topup-account-title{font-weight:800;font-size:.9rem}
.topup-fields-list{padding:6px 0}
.topup-field-item{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.03)}
.topup-field-item:last-child{border-bottom:none}
.topup-field-lbl{font-size:.75rem;color:#8895a7;font-weight:700}
.topup-field-val{font-size:.9rem;font-weight:800;color:#fff}
.topup-copy-chip{background:rgba(108,63,224,.15);border:1px solid rgba(108,63,224,.3);border-radius:8px;padding:4px 10px;color:var(--primary);font-size:.72rem;font-weight:800;cursor:pointer;font-family:var(--font);transition:.2s}
.topup-copy-chip.done{background:rgba(0,212,170,.15);border-color:rgba(0,212,170,.3);color:#00d4aa}

/* قسم الحساب */
.topup-calc-section{padding:0 12px 4px}
.topup-section-label{font-size:.75rem;color:#8895a7;font-weight:800;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.topup-currency-row{display:flex;gap:8px;margin-bottom:12px;overflow-x:auto;padding-bottom:2px;scrollbar-width:none}
.topup-currency-row::-webkit-scrollbar{display:none}
.topup-currency-chip{flex-shrink:0;background:var(--card2);border:2px solid var(--border);border-radius:12px;padding:8px 14px;cursor:pointer;transition:.2s;text-align:center}
.topup-currency-chip.active{border-color:var(--primary);background:rgba(30,111,255,.15)}
.topup-chip-symbol{font-size:1rem;font-weight:900;color:#fff}
.topup-chip-code{font-size:.65rem;color:#8895a7;margin-top:1px}
.topup-currency-chip.active .topup-chip-symbol{color:var(--primary)}
.topup-amount-wrap{position:relative;margin-bottom:12px;display:flex;align-items:center;background:var(--bg);border:2px solid var(--border);border-radius:14px;overflow:hidden;transition:.2s}
.topup-amount-wrap:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px rgba(30,111,255,.15)}
.topup-amount-currency{padding:0 14px;font-size:.85rem;font-weight:900;color:#8895a7;flex-shrink:0;border-left:1px solid var(--border)}
.topup-amount-field{flex:1;background:transparent;border:none;padding:14px 12px;font-size:1.2rem;font-weight:900;color:#fff;font-family:var(--font);outline:none;text-align:right}
.topup-result-card{background:linear-gradient(135deg,rgba(0,212,170,.1) 0%,rgba(30,111,255,.08) 100%);border:1px solid rgba(0,212,170,.2);border-radius:14px;padding:14px 16px;margin-bottom:14px}
.topup-result-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.topup-result-lbl{font-size:.8rem;color:#8895a7}
.topup-result-val{font-size:1.5rem;font-weight:900;color:#00d4aa}
.topup-result-bar{height:4px;background:rgba(255,255,255,.06);border-radius:2px;overflow:hidden}
.topup-result-bar-fill{height:100%;background:linear-gradient(90deg,#00d4aa,#1e6fff);border-radius:2px;width:0%;transition:width .5s cubic-bezier(.4,0,.2,1)}

/* رفع الإيصال */
.topup-upload-section{padding:0 12px 12px}
.topup-upload-zone{display:flex;align-items:center;gap:14px;background:var(--card2);border:2px dashed var(--border);border-radius:14px;padding:14px 16px;cursor:pointer;transition:.2s}
.topup-upload-zone:active{border-color:var(--primary);background:rgba(30,111,255,.05)}
.topup-upload-icon{width:40px;height:40px;background:rgba(30,111,255,.12);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1.1rem;flex-shrink:0}
.topup-upload-text{font-size:.88rem;font-weight:800;color:#fff}
.topup-upload-hint{font-size:.72rem;color:#8895a7;margin-top:2px}
.topup-receipt-preview{width:100%;border-radius:12px;margin-top:8px;max-height:180px;object-fit:cover}
.topup-note-input{width:100%;background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:11px 14px;font-size:.87rem;color:#fff;font-family:var(--font);outline:none;box-sizing:border-box;transition:.2s}
.topup-note-input:focus{border-color:rgba(255,255,255,.2)}
.topup-submit-btn{width:100%;background:linear-gradient(135deg,var(--primary) 0%,#7c3aed 100%);border:none;border-radius:16px;padding:16px;font-size:1rem;font-weight:900;color:#fff;cursor:pointer;font-family:var(--font);display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 8px 24px rgba(30,111,255,.35);transition:transform .15s,box-shadow .15s}
.topup-submit-btn:active{transform:scale(.97);box-shadow:0 4px 12px rgba(30,111,255,.3)}


/* ═══════════════════════════════════════
   Splash Screen
═══════════════════════════════════════ */
#splash-screen {
  position: fixed;
  inset: 0;
  z-index: 99999;
  background: #080c1a;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  transition: opacity 0.5s ease, transform 0.5s ease;
}
#splash-screen.hide {
  opacity: 0;
  transform: scale(1.05);
  pointer-events: none;
}
.splash-stars { position:absolute;inset:0;overflow:hidden; }
.splash-star { position:absolute;border-radius:50%;background:white;animation:sp-twinkle var(--d) ease-in-out infinite var(--delay); }
@keyframes sp-twinkle { 0%,100%{opacity:.05} 50%{opacity:.6} }
.splash-logo-wrap { position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;gap:20px; }
.splash-icon { width:90px;height:90px;background:linear-gradient(135deg,#1e6fff,#00d4ff);border-radius:26px;display:flex;align-items:center;justify-content:center;font-size:44px;box-shadow:0 20px 60px rgba(30,111,255,0.5),0 0 0 1px rgba(255,255,255,0.1);animation:sp-bounce 0.6s cubic-bezier(0.175,0.885,0.32,1.275) both; }
@keyframes sp-bounce { from{opacity:0;transform:scale(0.5)} to{opacity:1;transform:scale(1)} }
.splash-title { font-family:'Cairo',sans-serif;font-size:28px;font-weight:900;color:white;animation:sp-fade 0.5s ease 0.3s both;text-align:center; }
.splash-title span { background:linear-gradient(90deg,#1e6fff,#00d4ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text; }
.splash-sub { font-family:'Cairo',sans-serif;font-size:13px;color:#8fa3bf;animation:sp-fade 0.5s ease 0.5s both;text-align:center; }
@keyframes sp-fade { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
.splash-loader { width:140px;height:3px;background:rgba(255,255,255,0.1);border-radius:3px;overflow:hidden;margin-top:8px;animation:sp-fade 0.5s ease 0.6s both; }
.splash-loader-bar { height:100%;background:linear-gradient(90deg,#1e6fff,#00d4ff);border-radius:3px;animation:sp-load 1.2s ease 0.7s both; }
@keyframes sp-load { from{width:0%} to{width:100%} }

/* ── الوضع النهاري لواجهة الافتتاح ── */
body.light-mode #splash-screen { background: #f0f4fa !important; }
body.light-mode .splash-star { background: #1e40af !important; }
body.light-mode .splash-title { color: #0f172a !important; }
body.light-mode .splash-sub { color: #475569 !important; }
body.light-mode .splash-loader { background: rgba(15,23,42,0.08) !important; }
body.light-mode .splash-icon { box-shadow: 0 20px 60px rgba(30,111,255,0.25), 0 0 0 1px rgba(15,23,42,0.06) !important; }
body.light-mode .splash-icon-logo { background: linear-gradient(135deg,#ffffff,#f0f4fa) !important; }

/* ═══════════════════════════════════════
   Offline / Online Banner
═══════════════════════════════════════ */
#offline-banner { display:none;position:fixed;top:0;left:0;right:0;z-index:9998;color:white;font-family:'Cairo',sans-serif;font-size:13px;font-weight:700;padding:10px 16px;text-align:center;direction:rtl;box-shadow:0 4px 16px rgba(0,0,0,0.4);animation:slideDown .3s ease; }
#offline-banner.offline-mode { background:linear-gradient(135deg,#c62828,#b71c1c); }
#offline-banner.online-mode  { background:linear-gradient(135deg,#1b5e20,#2e7d32); }
@keyframes slideDown { from{transform:translateY(-100%);opacity:0} to{transform:translateY(0);opacity:1} }

/* ═══════════════════════════════════════
   Install PWA Prompt
═══════════════════════════════════════ */
#install-prompt { display:none;position:fixed;bottom:80px;left:12px;right:12px;z-index:9997;background:linear-gradient(135deg,#0d1428,#1a2340);border:1px solid rgba(30,111,255,0.3);border-radius:18px;padding:16px;box-shadow:0 16px 48px rgba(0,0,0,0.6);animation:slideUp .4s cubic-bezier(0.175,0.885,0.32,1.275);max-width:430px;margin:0 auto; }
@keyframes slideUp { from{transform:translateY(100px);opacity:0} to{transform:translateY(0);opacity:1} }
.install-prompt-inner { display:flex;align-items:center;gap:14px;direction:rtl; }
.install-icon { width:48px;height:48px;background:linear-gradient(135deg,#1e6fff,#00d4ff);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0; }
.install-text { flex:1; }
.install-title { font-size:14px;font-weight:900;color:white;margin-bottom:3px; }
.install-sub { font-size:11px;color:#8fa3bf; }
.install-actions { display:flex;gap:8px;margin-top:12px; }
.install-btn { flex:1;padding:10px;border-radius:12px;border:none;font-family:'Cairo',sans-serif;font-size:13px;font-weight:700;cursor:pointer; }
.install-btn-yes { background:linear-gradient(135deg,#1e6fff,#0d4fd4);color:white;box-shadow:0 4px 12px rgba(30,111,255,0.4); }
.install-btn-no { background:rgba(255,255,255,0.07);color:#8fa3bf;border:1px solid rgba(255,255,255,0.1); }

/* ── KYC Styles ── */
.kyc-label{display:block;font-size:12px;font-weight:700;color:#8895a7;margin-bottom:6px}
.kyc-input{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:12px 14px;color:#fff;font-family:var(--font);font-size:14px;outline:none;transition:border .2s;box-sizing:border-box}
.kyc-input:focus{border-color:var(--primary)}
.kyc-type-card{background:var(--card2);border:2px solid var(--border);border-radius:14px;padding:12px 8px;display:flex;flex-direction:column;align-items:center;gap:6px;transition:all .2s}
.kyc-type-card.active{border-color:var(--primary);background:rgba(30,111,255,.12)}
.kyc-upload-box{background:var(--card2);border:2px dashed var(--border);border-radius:16px;padding:24px 16px;display:flex;flex-direction:column;align-items:center;cursor:pointer;transition:all .2s;text-align:center}
.kyc-upload-box:active{border-color:var(--cyan);background:rgba(0,212,255,.06)}
.kyc-upload-box.has-image{border-color:var(--green);border-style:solid}
.kyc-btn-primary{width:100%;padding:15px;background:linear-gradient(135deg,var(--primary),#0a3fbe);border:none;border-radius:14px;color:#fff;font-family:var(--font);font-size:15px;font-weight:800;cursor:pointer;transition:all .2s;box-shadow:0 6px 20px rgba(30,111,255,.35)}
.kyc-btn-primary:active{transform:scale(.98)}
.kyc-btn-primary:disabled{background:#333;box-shadow:none;opacity:.6;cursor:not-allowed}

/* ╔══════════════════════════════════════════════════════════╗
   ║   ☀️ LIGHT MODE — نظام ألوان نهاري متكامل               ║
   ║   مبني على Slate/Blue palette — مريح للعين              ║
   ╚══════════════════════════════════════════════════════════╝ */

body.light-mode {
  /* ── Backgrounds ── */
  --bg:        #f0f4fa;   /* خلفية رمادية مزرقة ناعمة (أريح من الأبيض النقي) */
  --bg2:       #e4ecf7;   /* طبقة ثانية */
  --card:      #ffffff;   /* البطاقات بيضاء */
  --card2:     #f5f8ff;   /* بطاقات داخلية فاتحة */
  --card3:     #eaf0fb;   /* hover */

  /* ── Borders ── */
  --border:    rgba(30,64,175,0.1);
  --border2:   rgba(30,64,175,0.18);

  /* ── Brand Colors (نفس الـ dark لكن مع shadows مختلفة) ── */
  --primary:   #2563eb;
  --primary2:  #1e40af;
  --primary-glow: rgba(37,99,235,0.2);
  --cyan:      #0891b2;
  --gold:      #d97706;
  --gold-glow: rgba(217,119,6,0.15);
  --green:     #059669;
  --red:       #dc2626;
  --purple:    #7c3aed;

  /* ── Text ── */
  --text:      #0f172a;   /* داكن للقراءة */
  --text2:     #475569;   /* ثانوي */
  --text3:     #94a3b8;   /* خافت */

  /* ── Shadow (أخف بكثير في النهار) ── */
  --shadow:    0 2px 16px rgba(15,23,42,0.08);
}

/* ═══════════════════════════════════════════════
   الجسم والحاوية الرئيسية
═══════════════════════════════════════════════ */
body.light-mode,
body.light-mode #app,
body.light-mode .content,
body.light-mode .page,
body.light-mode .main-column {
  background: var(--bg) !important;
  color: var(--text) !important;
}
body.light-mode #app {
  box-shadow: 0 0 60px rgba(15,23,42,0.12) !important;
}

/* ═══════════════════════════════════════════════
   الهيدر العلوي
═══════════════════════════════════════════════ */
body.light-mode .top-header {
  background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%) !important;
  border-bottom: 1px solid rgba(255,255,255,0.12) !important;
  box-shadow: 0 2px 16px rgba(15,23,42,0.2) !important;
}
body.light-mode .hamburger-btn {
  background: rgba(255,255,255,0.15) !important;
  border-color: rgba(255,255,255,0.2) !important;
  color: #fff !important;
}
body.light-mode .logo-text {
  background: linear-gradient(90deg,#fff,#bfdbfe) !important;
  -webkit-background-clip: text !important;
  -webkit-text-fill-color: transparent !important;
  background-clip: text !important;
}
body.light-mode .header-btn,
body.light-mode .theme-toggle-btn {
  background: rgba(255,255,255,0.18) !important;
  border-color: rgba(255,255,255,0.25) !important;
  color: #fff !important;
}
body.light-mode .notif-dot {
  border-color: #1e3a8a !important;
}

/* ═══════════════════════════════════════════════
   شريط الرصيد
═══════════════════════════════════════════════ */
body.light-mode .balance-bar {
  background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%) !important;
  border-bottom: 1px solid rgba(255,255,255,0.1) !important;
  box-shadow: 0 2px 12px rgba(15,23,42,0.15) !important;
}
body.light-mode .balance-label,
body.light-mode .balance-value,
body.light-mode .balance-currency,
body.light-mode .balance-usd {
  color: rgba(255,255,255,0.95) !important;
}
body.light-mode .balance-label { color: rgba(255,255,255,0.75) !important; }
body.light-mode .balance-icon {
  background: rgba(255,255,255,0.2) !important;
  border: 1px solid rgba(255,255,255,0.25) !important;
}
body.light-mode .balance-reload {
  background: rgba(255,255,255,0.2) !important;
  border: 1px solid rgba(255,255,255,0.3) !important;
  color: #fff !important;
}
body.light-mode .balance-reload:active { background: rgba(255,255,255,0.3) !important; }

/* ═══════════════════════════════════════════════
   شريط التنقل السفلي
═══════════════════════════════════════════════ */
body.light-mode .bottom-nav {
  background: #ffffff !important;
  border-top: 1px solid rgba(15,23,42,0.08) !important;
  box-shadow: 0 -4px 20px rgba(15,23,42,0.07) !important;
}
body.light-mode .nav-icon,
body.light-mode .nav-label { color: #94a3b8 !important; }
body.light-mode .nav-item.active .nav-icon,
body.light-mode .nav-item.active .nav-label { color: var(--primary) !important; }
body.light-mode .nav-item.active::before {
  background: linear-gradient(90deg, var(--primary), #60a5fa) !important;
  box-shadow: none !important;
}
body.light-mode .nav-item:active { background: rgba(37,99,235,0.06) !important; }

/* ═══════════════════════════════════════════════
   البطاقات والكروت العامة
═══════════════════════════════════════════════ */
body.light-mode .pkg-card,
body.light-mode .order-card,
body.light-mode .order-item,
body.light-mode .wallet-tx-item,
body.light-mode .notif-item,
body.light-mode .referral-card,
body.light-mode .payment-card,
body.light-mode .kyc-card,
body.light-mode .settings-card,
body.light-mode .profile-menu-item,
body.light-mode .faq-item,
body.light-mode .sidebar-section {
  background: #ffffff !important;
  border-color: rgba(15,23,42,0.07) !important;
  box-shadow: 0 1px 8px rgba(15,23,42,0.07), 0 0 0 1px rgba(15,23,42,0.04) !important;
}
body.light-mode .pkg-card:active,
body.light-mode .profile-menu-item:active { background: #f5f8ff !important; }

body.light-mode .qa-item {
  background: #ffffff !important;
  border-color: rgba(37,99,235,0.1) !important;
  box-shadow: 0 1px 8px rgba(15,23,42,0.06) !important;
}
body.light-mode .qa-item:active { background: #f0f4fa !important; }

body.light-mode .search-bar {
  background: #ffffff !important;
  border-color: rgba(37,99,235,0.12) !important;
  box-shadow: 0 1px 6px rgba(15,23,42,0.06) !important;
}
body.light-mode .search-bar:focus-within {
  border-color: var(--primary) !important;
  box-shadow: 0 0 0 3px rgba(37,99,235,0.1), 0 1px 6px rgba(15,23,42,0.06) !important;
}

/* tx items داخل المحفظة */
body.light-mode .tx-item {
  border-bottom-color: rgba(15,23,42,0.06) !important;
  background: transparent !important;
}

/* ═══════════════════════════════════════════════
   أزرار التبويب والفلاتر
═══════════════════════════════════════════════ */
body.light-mode .action-tab-btn {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.08) !important;
  color: #64748b !important;
}
body.light-mode .action-tab-btn.active {
  background: linear-gradient(135deg, var(--primary), var(--primary2)) !important;
  border-color: transparent !important;
  color: #fff !important;
  box-shadow: 0 4px 14px rgba(37,99,235,0.3) !important;
}
body.light-mode .wallet-filter-btn {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.08) !important;
  color: #64748b !important;
}
body.light-mode .wallet-filter-btn.active {
  background: var(--primary) !important;
  border-color: transparent !important;
  color: #fff !important;
}

/* ═══════════════════════════════════════════════
   الأسعار في الباقات
═══════════════════════════════════════════════ */
body.light-mode .pkg-price-usd {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.1) !important;
  color: #64748b !important;
}
body.light-mode .pkg-name { color: #0f172a !important; }
body.light-mode .pkg-cat  { color: var(--primary) !important; }

/* ═══════════════════════════════════════════════
   السايدبار
═══════════════════════════════════════════════ */
body.light-mode .sidebar {
  background: #ffffff !important;
  border-left: 1px solid rgba(15,23,42,0.08) !important;
  box-shadow: -8px 0 40px rgba(15,23,42,0.12) !important;
}
body.light-mode .sidebar-top { border-bottom-color: rgba(15,23,42,0.08) !important; }
body.light-mode .sidebar-username { color: #0f172a !important; }
body.light-mode .sidebar-balance  { color: #64748b !important; }
body.light-mode .sidebar-close {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.08) !important;
  color: #64748b !important;
}
body.light-mode .sidebar-item { color: #0f172a !important; }
body.light-mode .sidebar-item:active { background: rgba(37,99,235,0.07) !important; }
body.light-mode .sidebar-item-badge { background: rgba(37,99,235,0.08) !important; }
body.light-mode .sidebar-divider { background: rgba(15,23,42,0.07) !important; }
body.light-mode .sidebar-menu-label { color: #94a3b8 !important; }
body.light-mode .sidebar-topup-card {
  box-shadow: 0 6px 24px rgba(37,99,235,0.3) !important;
}
body.light-mode .sidebar-topup-title { color: #fff !important; }
body.light-mode .sidebar-topup-sub   { color: rgba(255,255,255,0.8) !important; }

/* ═══════════════════════════════════════════════
   Bottom Sheets والـ Modals
═══════════════════════════════════════════════ */
body.light-mode .bottom-sheet {
  background: #ffffff !important;
  border: 1px solid rgba(15,23,42,0.06) !important;
  box-shadow: 0 -8px 40px rgba(15,23,42,0.15) !important;
}
body.light-mode .sheet-handle { background: rgba(15,23,42,0.12) !important; }
body.light-mode .sheet-close {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.08) !important;
  color: #64748b !important;
}
body.light-mode .sheet-title,
body.light-mode .sheet-service-name { color: #0f172a !important; }
body.light-mode .sheet-label,
body.light-mode .sheet-sub-label,
body.light-mode .sheet-pkg { color: #64748b !important; }
body.light-mode .sheet-price-val,
body.light-mode .sheet-total-price { color: #0f172a !important; }
body.light-mode .sheet-total {
  background: rgba(37,99,235,0.06) !important;
  border-color: rgba(37,99,235,0.15) !important;
}
body.light-mode .sheet-total-label { color: #64748b !important; }
body.light-mode .sheet-total-value { color: var(--primary) !important; }
body.light-mode .sheet-balance-row { border-top-color: rgba(15,23,42,0.07) !important; }
body.light-mode .sheet-warning {
  background: rgba(217,119,6,0.08) !important;
  border-color: rgba(217,119,6,0.25) !important;
}
body.light-mode .sheet-warning p { color: #92400e !important; }

/* ═══════════════════════════════════════════════
   حقول الإدخال
═══════════════════════════════════════════════ */
body.light-mode .field-input,
body.light-mode .auth-input,
body.light-mode .kyc-input,
body.light-mode .topup-note-input,
body.light-mode .chat-win-textarea,
body.light-mode input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=range]),
body.light-mode select,
body.light-mode textarea {
  background: #f8faff !important;
  color: #0f172a !important;
  border-color: rgba(15,23,42,0.12) !important;
}
body.light-mode .field-input::placeholder,
body.light-mode .auth-input::placeholder,
body.light-mode input::placeholder,
body.light-mode textarea::placeholder { color: #94a3b8 !important; }
body.light-mode .field-input:focus,
body.light-mode .auth-input:focus,
body.light-mode input:focus,
body.light-mode textarea:focus {
  border-color: var(--primary) !important;
  box-shadow: 0 0 0 3px rgba(37,99,235,0.1) !important;
}

/* ═══════════════════════════════════════════════
   صفحة المحفظة
═══════════════════════════════════════════════ */
body.light-mode .wallet-page { background: var(--bg) !important; }
body.light-mode .wallet-card {
  background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 50%, #1d4ed8 100%) !important;
  box-shadow: 0 8px 32px rgba(30,58,138,0.4) !important;
}
body.light-mode .wallet-action-btn {
  background: rgba(37,99,235,0.1) !important;
  border-color: rgba(37,99,235,0.2) !important;
  color: #1e40af !important;
}
body.light-mode .wallet-action-btn:active { background: rgba(37,99,235,0.18) !important; }
body.light-mode .wallet-balance-label { color: rgba(255,255,255,0.75) !important; }
body.light-mode .wallet-balance-amount,
body.light-mode .wallet-balance-cur { color: #fff !important; }
body.light-mode .tx-icon.credit { background: rgba(5,150,105,0.1) !important; }
body.light-mode .tx-icon.debit  { background: rgba(220,38,38,0.1) !important; }
body.light-mode .tx-name { color: #0f172a !important; }
body.light-mode .tx-date { color: #94a3b8 !important; }
body.light-mode .tx-amount.credit { color: #059669 !important; }
body.light-mode .tx-amount.debit  { color: #dc2626 !important; }

/* ═══════════════════════════════════════════════
   صفحة الطلبات
═══════════════════════════════════════════════ */
body.light-mode .order-name  { color: #0f172a !important; }
body.light-mode .order-id    { color: #94a3b8 !important; }
body.light-mode .order-price { color: var(--cyan) !important; }
body.light-mode .order-ref,
body.light-mode .order-service,
body.light-mode .order-date   { color: #0f172a !important; }
body.light-mode .order-provider,
body.light-mode .order-qty    { color: #64748b !important; }
body.light-mode .order-icon   { background: #f0f4fa !important; }

/* ═══════════════════════════════════════════════
   صفحة الإشعارات
═══════════════════════════════════════════════ */
body.light-mode .notif-item.unread { background: rgba(37,99,235,0.05) !important; }
body.light-mode .notif-item { border-bottom-color: rgba(15,23,42,0.06) !important; }
body.light-mode .notif-title  { color: #0f172a !important; }
body.light-mode .notif-body   { color: #475569 !important; }
body.light-mode .notif-time   { color: #94a3b8 !important; }

/* ═══════════════════════════════════════════════
   صفحة الحساب والإعدادات
═══════════════════════════════════════════════ */
body.light-mode .profile-page { background: var(--bg) !important; }
body.light-mode .profile-header-card {
  background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
  box-shadow: 0 8px 32px rgba(30,58,138,0.3) !important;
}
body.light-mode .profile-menu-label  { color: #0f172a !important; }
body.light-mode .pmi-arrow            { color: #94a3b8 !important; }
body.light-mode .settings-section-title {
  color: #64748b !important;
  font-weight: 700 !important;
}
body.light-mode .settings-toggle-row {
  border-bottom-color: rgba(15,23,42,0.06) !important;
}
body.light-mode .settings-toggle-row .stg-label { color: #0f172a !important; }
body.light-mode .settings-toggle-row .stg-sub   { color: #64748b !important; }
body.light-mode .settings-label { color: #0f172a !important; }
body.light-mode .settings-sub   { color: #64748b !important; }

/* ═══════════════════════════════════════════════
   صفحة شحن الرصيد
═══════════════════════════════════════════════ */
body.light-mode #page-topup { background: var(--bg) !important; }
body.light-mode .topup-drawer { background: #ffffff !important; }
body.light-mode .topup-header {
  border-bottom-color: rgba(15,23,42,0.07) !important;
  background: #fff !important;
}
body.light-mode .topup-header-title { color: #0f172a !important; }
body.light-mode .topup-header-sub   { color: #64748b !important; }
body.light-mode .topup-close-btn,
body.light-mode .topup-back-btn {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.1) !important;
  color: #64748b !important;
}
body.light-mode .topup-method-item,
body.light-mode .topup-method-card {
  background: #ffffff !important;
  border-color: rgba(15,23,42,0.08) !important;
  box-shadow: 0 2px 10px rgba(15,23,42,0.06) !important;
}
body.light-mode .topup-method-item:active,
body.light-mode .topup-method-card:hover,
body.light-mode .topup-method-card.selected {
  border-color: var(--primary) !important;
  background: rgba(37,99,235,0.04) !important;
}
body.light-mode .topup-method-name { color: #0f172a !important; }
body.light-mode .topup-method-desc,
body.light-mode .topup-method-sub  { color: #64748b !important; }
body.light-mode .topup-method-arrow { color: #94a3b8 !important; }
body.light-mode .topup-balance-strip {
  background: rgba(37,99,235,0.06) !important;
  border-color: rgba(37,99,235,0.15) !important;
}
body.light-mode .topup-section-label { color: #64748b !important; }
body.light-mode .topup-account-card {
  background: #ffffff !important;
  border-color: rgba(15,23,42,0.08) !important;
}
body.light-mode .topup-account-header {
  background: #f8faff !important;
  border-bottom-color: rgba(15,23,42,0.07) !important;
}
body.light-mode .topup-account-title { color: #0f172a !important; }
body.light-mode .topup-field-lbl     { color: #64748b !important; }
body.light-mode .topup-field-val,
body.light-mode .topup-field-label,
body.light-mode .topup-chip-symbol   { color: #0f172a !important; }
body.light-mode .topup-copy-chip {
  background: rgba(37,99,235,0.1) !important;
  border-color: rgba(37,99,235,0.2) !important;
  color: var(--primary) !important;
}
body.light-mode .topup-copy-chip.done {
  background: rgba(5,150,105,0.1) !important;
  border-color: rgba(5,150,105,0.2) !important;
  color: #059669 !important;
}
body.light-mode .topup-amount-wrap {
  background: #f8faff !important;
  border-color: rgba(15,23,42,0.12) !important;
}
body.light-mode .topup-amount-wrap:focus-within {
  border-color: var(--primary) !important;
  box-shadow: 0 0 0 3px rgba(37,99,235,0.1) !important;
}
body.light-mode .topup-amount-field { color: #0f172a !important; }
body.light-mode .topup-amount-currency { color: #64748b !important; }
body.light-mode .topup-currency-chip {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.1) !important;
}
body.light-mode .topup-chip,
body.light-mode .topup-currency-chip .topup-chip-code { color: #64748b !important; }
body.light-mode .topup-currency-chip.active,
body.light-mode .topup-chip.active {
  background: var(--primary) !important;
  border-color: var(--primary) !important;
  color: #fff !important;
}
body.light-mode .topup-currency-chip.active .topup-chip-code { color: rgba(255,255,255,0.8) !important; }
body.light-mode .topup-result-card {
  background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(5,150,105,0.05)) !important;
  border-color: rgba(37,99,235,0.15) !important;
}
body.light-mode .topup-result-lbl { color: #64748b !important; }
body.light-mode .topup-result-val { color: #059669 !important; }
body.light-mode .topup-mode-tab {
  border-color: rgba(15,23,42,0.1) !important;
  color: #64748b !important;
}
body.light-mode .topup-mode-tab.active {
  border-color: var(--primary) !important;
  background: rgba(37,99,235,0.08) !important;
  color: var(--primary) !important;
}
body.light-mode .topup-mode-desc { color: #64748b !important; }
body.light-mode .topup-upload-zone {
  background: #f8faff !important;
  border-color: rgba(37,99,235,0.2) !important;
}
body.light-mode .topup-upload-zone:active { border-color: var(--primary) !important; }
body.light-mode .topup-upload-text { color: #0f172a !important; }
body.light-mode .topup-upload-hint { color: #94a3b8 !important; }
body.light-mode .topup-empty { color: #94a3b8 !important; }

/* ═══════════════════════════════════════════════
   صفحة KYC
═══════════════════════════════════════════════ */
body.light-mode #page-kyc { background: var(--bg) !important; }
body.light-mode .kyc-label     { color: #64748b !important; }
body.light-mode .kyc-input     { background: #f8faff !important; border-color: rgba(15,23,42,0.12) !important; color: #0f172a !important; }
body.light-mode .kyc-input:focus { border-color: var(--primary) !important; }
body.light-mode .kyc-type-card { background: #f8faff !important; border-color: rgba(15,23,42,0.1) !important; }
body.light-mode .kyc-type-card.active { border-color: var(--primary) !important; background: rgba(37,99,235,0.06) !important; }
body.light-mode .kyc-upload-box { background: #f8faff !important; border-color: rgba(15,23,42,0.12) !important; }
body.light-mode .kyc-upload-box.has-image { border-color: #059669 !important; }
body.light-mode .kyc-required-banner {
  background: rgba(217,119,6,0.08) !important;
  border-color: rgba(217,119,6,0.25) !important;
  color: #92400e !important;
}
/* النصوص على خلفيات ملونة تبقى بيضاء */
body.light-mode #page-kyc .kyc-status-approved,
body.light-mode #page-kyc .kyc-status-pending { color: #fff !important; }

/* ═══════════════════════════════════════════════
   مودال المصادقة
═══════════════════════════════════════════════ */
body.light-mode .auth-sheet {
  background: #ffffff !important;
  border-color: rgba(15,23,42,0.06) !important;
  box-shadow: 0 -8px 40px rgba(15,23,42,0.12) !important;
}
body.light-mode .auth-tabs { background: rgba(15,23,42,0.05) !important; }
body.light-mode .auth-handle { background: rgba(15,23,42,0.12) !important; }
body.light-mode .auth-tab { color: #64748b !important; border-color: rgba(15,23,42,0.1) !important; }
body.light-mode .auth-tab.active { color: var(--primary) !important; border-color: var(--primary) !important; }
body.light-mode .auth-label { color: #475569 !important; }
body.light-mode .auth-switch { color: #475569 !important; }
body.light-mode .auth-divider { color: #94a3b8 !important; }
body.light-mode .auth-google-btn {
  background: rgba(66,133,244,0.06) !important;
  border-color: rgba(66,133,244,0.25) !important;
  color: #1e40af !important;
}
body.light-mode .auth-google-btn:active { background: rgba(66,133,244,0.12) !important; }

/* ═══════════════════════════════════════════════
   قسم الإحالة
═══════════════════════════════════════════════ */
body.light-mode .referral-code-box {
  background: #f0f4fa !important;
  border-color: rgba(37,99,235,0.15) !important;
}
body.light-mode .referral-code-val { color: #0f172a !important; }
body.light-mode .referral-stat-card {
  background: #ffffff !important;
  border-color: rgba(15,23,42,0.07) !important;
  box-shadow: 0 1px 8px rgba(15,23,42,0.06) !important;
}

/* ═══════════════════════════════════════════════
   Bread crumbs / Category nav
═══════════════════════════════════════════════ */
body.light-mode .cat-nav-crumbs { background: #f0f4fa !important; border-bottom-color: rgba(15,23,42,0.07) !important; }
body.light-mode .cat-crumb-btn { color: #64748b !important; }
body.light-mode .cat-crumb-btn.current { color: #0f172a !important; background: rgba(15,23,42,0.07) !important; }
body.light-mode .cat-crumb-sep { color: #94a3b8 !important; }

/* ═══════════════════════════════════════════════
   الإشعار المنبثق
═══════════════════════════════════════════════ */
body.light-mode .notif-popup-box {
  background: #ffffff !important;
  border: 1px solid rgba(15,23,42,0.08) !important;
  box-shadow: 0 30px 70px -15px rgba(15,23,42,0.22) !important;
}
body.light-mode .notif-popup-title { color: #0f172a !important; }
body.light-mode .notif-popup-msg   { color: #475569 !important; }
body.light-mode .notif-popup-btn-later {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.1) !important;
  color: #475569 !important;
}
body.light-mode .notif-popup-close {
  background: rgba(15,23,42,.05) !important;
  border-color: rgba(15,23,42,.08) !important;
  color: #64748b !important;
}

/* ═══════════════════════════════════════════════
   نافذة الدردشة
═══════════════════════════════════════════════ */
body.light-mode .chat-window { background: #f0f4fa !important; }
body.light-mode .chat-win-messages { background: #f0f4fa !important; }
body.light-mode .cwb-in { background: #ffffff !important; color: #0f172a !important; box-shadow: 0 1px 6px rgba(15,23,42,0.07) !important; }
body.light-mode .cwb-auto { background: rgba(5,150,105,0.08) !important; border-color: rgba(5,150,105,0.2) !important; }
body.light-mode .chat-win-input { background: #ffffff !important; border-top-color: rgba(15,23,42,0.07) !important; }
body.light-mode .chat-win-textarea {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.1) !important;
  color: #0f172a !important;
}

/* ═══════════════════════════════════════════════
   PWA Install Prompt
═══════════════════════════════════════════════ */
body.light-mode #install-prompt {
  background: #ffffff !important;
  border-color: rgba(37,99,235,0.2) !important;
  box-shadow: 0 12px 40px rgba(15,23,42,0.15) !important;
}
body.light-mode .install-title { color: #0f172a !important; }
body.light-mode .install-sub   { color: #64748b !important; }
body.light-mode .install-btn-no {
  background: #f0f4fa !important;
  border-color: rgba(15,23,42,0.1) !important;
  color: #64748b !important;
}

/* ═══════════════════════════════════════════════
   عناصر متفرقة
═══════════════════════════════════════════════ */
body.light-mode .game-thumb {
  border-color: rgba(15,23,42,0.08) !important;
  box-shadow: 0 2px 10px rgba(15,23,42,0.06) !important;
}
body.light-mode .game-name { color: #475569 !important; }

body.light-mode #pkgHeaderBg {
  background: linear-gradient(180deg, #1e3a8a 0%, var(--bg) 100%) !important;
}
body.light-mode .phic-title { color: #fff !important; }
body.light-mode .phic-sub   { color: rgba(255,255,255,0.8) !important; }

body.light-mode .sec-title { color: #0f172a !important; }
body.light-mode .sec-more  { color: var(--primary) !important; }
body.light-mode .cat-card-namebox { background: #000000 !important; }
body.light-mode .cat-card-name { color: #fff !important; }

body.light-mode .sheet-overlay,
body.light-mode .sidebar-overlay { background: rgba(15,23,42,0.4) !important; }

/* بطاقة المحفظة في الـ sidebar */
body.light-mode .sidebar-avatar {
  background: linear-gradient(135deg, var(--primary), #60a5fa) !important;
  box-shadow: 0 4px 14px rgba(37,99,235,0.3) !important;
}

/* Toasts */
body.light-mode .toast {
  box-shadow: 0 4px 20px rgba(15,23,42,0.15) !important;
}

/* order success sheet */
body.light-mode .order-success-sheet { background: #ffffff !important; }
body.light-mode .order-success-title { color: #0f172a !important; }
body.light-mode .order-success-ref   { color: #64748b !important; }

/* ── Override inline color:#fff for non-colored backgrounds ── */
body.light-mode [style*="color:#fff"]:not(.wallet-card *):not(.balance-bar *):not(.top-header *):not(.bottom-nav *):not([class*="badge"]):not([class*="status-"]):not([class*="-btn"]):not(.cat-card *):not(#pkgHeaderBg *):not(.profile-header-card *):not(.auth-google-btn):not(.sheet-buy-btn):not(.quiz-answer-btn):not([class*="topup-chip"].active):not(.wallet-action-btn):not(.sidebar-topup-card *) {
  color: #0f172a !important;
}
body.light-mode [style*="color:rgba(255,255,255"]:not(.wallet-card *):not(.balance-bar *):not(.top-header *):not(.bottom-nav *):not(.profile-header-card *):not(.cat-card *):not(#pkgHeaderBg *):not(.sidebar-topup-card *) {
  color: #475569 !important;
}
body.light-mode [style*="color:#8fa3bf"],
body.light-mode [style*="color:#8895a7"],
body.light-mode [style*="color:#4d6080"] {
  color: #64748b !important;
}
body.light-mode [style*="background:var(--card)"],
body.light-mode [style*="background:var(--card2)"] {
  background: #ffffff !important;
}

/* ── Transition ── */
body.theme-transitioning * {
  transition: background-color .3s ease, color .25s ease, border-color .25s ease, box-shadow .3s ease !important;
}
</style>
