<?php
require_once 'includes/config.php';
$pageTitle = 'سوق P2P - ' . SITE_NAME;
$user = isLoggedIn() ? getUser() : null;
$isVerified = false;
if ($user) { try { $k=$pdo->prepare("SELECT status FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");$k->execute([$user['id']]);$r=$k->fetch();$isVerified=$r&&$r['status']==='approved'; } catch(Exception $e){$isVerified=true;} }
$siteLogo = getSetting('site_logo') ?: '';
$siteLogoUrl = $siteLogo ? SITE_URL.'/'.$siteLogo : '';
$siteName = getSetting('site_name') ?: SITE_NAME;
?><!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"/><meta name="theme-color" content="#080c1a"/><title><?=htmlspecialchars($pageTitle)?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com"></script>
<style>
:root{--bg:#080c1a;--bg2:#0d1428;--card:#111827;--card2:#1a2340;--border:rgba(255,255,255,0.07);--border2:rgba(255,255,255,0.12);--primary:#1e6fff;--primary2:#0d4fd4;--cyan:#00d4ff;--gold:#f5a623;--green:#00e676;--red:#ff1744;--purple:#8b5cf6;--text:#ffffff;--text2:#8fa3bf;--text3:#4d6080;--radius:18px;--radius-sm:12px;--shadow:0 8px 32px rgba(0,0,0,0.5);--font:'Cairo',sans-serif;--nav-h:64px}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100dvh;max-width:430px;margin:0 auto;position:relative;overflow-x:hidden;padding-bottom:calc(var(--nav-h) + 10px)}
.card{background:var(--card);border-radius:var(--radius);padding:16px;margin-bottom:10px;border:1px solid var(--border)}
.card:active{transform:scale(.985);transition:transform .1s}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.top-header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;position:sticky;top:0;z-index:100;background:linear-gradient(180deg,var(--bg) 60%,transparent);backdrop-filter:blur(12px)}
.header-logo{display:flex;align-items:center;gap:8px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--primary),var(--purple));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;overflow:hidden}
.logo-icon img{width:100%;height:100%;object-fit:contain;border-radius:10px}
.logo-text{font-size:1rem;font-weight:900;color:var(--text)}
.header-btn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:16px;transition:all .2s;text-decoration:none}
.header-btn:active{transform:scale(.92)}
.header-right{display:flex;align-items:center;gap:8px}
.bal-chip{display:flex;align-items:center;gap:5px;padding:6px 12px;background:var(--card2);border:1px solid var(--border);border-radius:20px;font-size:.8rem;font-weight:700;color:var(--cyan)}
.bottom-nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:430px;height:var(--nav-h);background:linear-gradient(180deg,rgba(13,20,40,0.97) 0%,#080c1a 100%);border-top:1px solid var(--border);display:flex;align-items:center;z-index:200}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;padding:8px 0;transition:all .2s;position:relative;color:var(--text3);font-size:.65rem}
.nav-item:active{transform:scale(.9)}
.nav-item .nav-icon{font-size:20px;transition:color .2s}
.nav-item.active .nav-icon,.nav-item.active .nav-label{color:var(--primary)}
.nav-item.active::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:30px;height:2px;border-radius:0 0 3px 3px;background:var(--primary)}
.nav-center{flex:1;display:flex;justify-content:center}
.nav-center-btn{width:50px;height:50px;background:linear-gradient(135deg,var(--primary),#0a3fbe);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;cursor:pointer;box-shadow:0 4px 16px rgba(30,111,255,.5);margin-top:-14px;transition:transform .2s;border:3px solid var(--bg)}
.nav-center-btn:active{transform:scale(.9)}
.content{padding:0 16px;min-height:60vh}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:500;display:none;align-items:flex-end;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal-sheet{width:100%;max-width:430px;max-height:85vh;overflow-y:auto;background:var(--card);border-radius:24px 24px 0 0;padding:20px;animation:sheetUp .3s cubic-bezier(.4,0,.2,1)}
@keyframes sheetUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.modal-handle{width:36px;height:4px;background:var(--border2);border-radius:4px;margin:0 auto 16px}
.cat-scroll{display:flex;gap:8px;overflow-x:auto;padding:0 0 8px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.cat-scroll::-webkit-scrollbar{display:none}
.cat-chip{flex-shrink:0;display:flex;align-items:center;gap:5px;padding:8px 14px;border-radius:20px;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .2s;border:1px solid var(--border);background:var(--card2);color:var(--text2);white-space:nowrap}
.cat-chip.active{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(30,111,255,.3)}
.svc-card{background:var(--card);border-radius:var(--radius);padding:14px;border:1px solid var(--border);cursor:pointer;transition:all .15s}
.svc-card:active{transform:scale(.98)}
.chat-bubble-in{background:var(--card2);border-radius:4px 14px 14px 14px;padding:10px 14px;font-size:.85rem;line-height:1.6;max-width:82%;align-self:flex-start}
.chat-bubble-out{background:linear-gradient(135deg,var(--primary),#2563eb);color:#fff;border-radius:14px 4px 14px 14px;padding:10px 14px;font-size:.85rem;line-height:1.6;max-width:82%;align-self:flex-end}
.chat-bubble-sys{background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.2);border-radius:20px;padding:5px 14px;font-size:.72rem;color:#a78bfa;text-align:center;align-self:center}
.prog-step{display:flex;flex-direction:column;align-items:center;gap:3px;flex:1}
.prog-dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;transition:all .3s}
.prog-dot.done{background:linear-gradient(135deg,var(--primary),#2563eb);color:#fff;box-shadow:0 4px 12px rgba(30,111,255,.4)}
.prog-dot.cur{background:rgba(30,111,255,.15);color:var(--primary);border:1.5px solid rgba(30,111,255,.3)}
.prog-dot.off{background:var(--card2);color:var(--text3)}
.prog-line{flex:1;height:2px;border-radius:2px;margin-top:-18px}
.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;border:none;border-radius:var(--radius-sm);padding:12px;font-family:var(--font);font-weight:700;font-size:.9rem;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .15s}
.btn-primary:active{transform:scale(.97);opacity:.9}
.btn-danger{background:rgba(255,23,68,.08);color:var(--red);border:1px solid rgba(255,23,68,.2);border-radius:var(--radius-sm);padding:12px;font-family:var(--font);font-weight:700;font-size:.85rem;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:6px}
.btn-warning{background:rgba(245,166,35,.08);color:var(--gold);border:1px solid rgba(245,166,35,.2);border-radius:var(--radius-sm);padding:12px;font-family:var(--font);font-weight:700;font-size:.85rem;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:6px}
.btn-success{background:linear-gradient(135deg,#00c853,#00e676);color:#fff;border:none;border-radius:var(--radius-sm);padding:12px;font-family:var(--font);font-weight:700;font-size:.9rem;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:6px}
.btn-purple{background:linear-gradient(135deg,var(--purple),#6d28d9);color:#fff;border:none;border-radius:var(--radius-sm);padding:12px;font-family:var(--font);font-weight:700;font-size:.9rem;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:6px}
.input{width:100%;background:var(--bg2);border:1.5px solid var(--border2);border-radius:var(--radius-sm);padding:10px 14px;color:var(--text);font-family:var(--font);font-size:.87rem;outline:none}
.input:focus{border-color:var(--primary)}
.input::placeholder{color:var(--text3)}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .35s ease both}
.fd1{animation-delay:.04s}.fd2{animation-delay:.08s}.fd3{animation-delay:.12s}.fd4{animation-delay:.16s}
.mi{font-family:'Material Symbols Outlined';font-size:24px;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;display:inline-block;line-height:1;direction:ltr}
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;display:inline-block;line-height:1;direction:ltr}
.glass-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:14px}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.custom-scroll::-webkit-scrollbar{width:3px}.custom-scroll::-webkit-scrollbar-thumb{background:var(--border2);border-radius:10px}
</style></head><body>

<!-- HEADER -->
<div class="top-header">
  <div class="header-logo">
    <a href="<?=SITE_URL?>/mobile.php" class="header-btn" style="text-decoration:none"><i class="fas fa-arrow-right"></i></a>
    <div class="logo-icon"><?=$siteLogoUrl?'<img src="'.htmlspecialchars($siteLogoUrl).'">':'🤝'?></div>
    <div class="logo-text">سوق P2P</div>
  </div>
  <div class="header-right">
    <?php if($user): ?>
    <div class="bal-chip"><i class="fas fa-wallet"></i> <span id="bal"><?=number_format($user['balance'],2)?></span> $</div>
    <?php else: ?>
    <a href="<?=SITE_URL?>/mobile.php#login" class="header-btn" style="color:var(--primary)"><i class="fas fa-sign-in-alt"></i></a>
    <?php endif; ?>
  </div>
</div>

<!-- CONTENT -->
<div class="content" id="A"></div>
<div id="nT" style="display:none"></div>

<!-- BOTTOM NAV -->
<div class="bottom-nav">
  <div class="nav-item active" data-t="market" onclick="go('market')"><div class="nav-icon"><i class="fas fa-store"></i></div><div class="nav-label">السوق</div></div>
  <div class="nav-item" data-t="orders" onclick="go('orders')"><div class="nav-icon"><i class="fas fa-receipt"></i></div><div class="nav-label">طلباتي</div></div>
  <div class="nav-center"><div class="nav-center-btn" onclick="go('chats')"><i class="fas fa-comments"></i></div></div>
  <div class="nav-item" data-t="mysvcs" onclick="go('mysvcs')"><div class="nav-icon"><i class="fas fa-box-open"></i></div><div class="nav-label">خدماتي</div></div>
  <div class="nav-item" data-t="profile" onclick="if(LG && UID) openProfile(UID); else go('market')"><div class="nav-icon"><i class="fas fa-user"></i></div><div class="nav-label">حسابي</div></div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="M" onclick="if(event.target===this)cM()"><div class="modal-sheet" id="MB"><div class="modal-handle"></div></div></div>

<script>
// ── ضغط صور P2P ──────────────────────────────────────────────────────────────
const _P2P_WEBP = document.createElement('canvas').toDataURL('image/webp').startsWith('data:image/webp');
const _P2P_FMT  = _P2P_WEBP ? 'image/webp' : 'image/jpeg';
const _P2P_EXT  = _P2P_WEBP ? 'webp' : 'jpg';
function _p2pCompress(file) {
  return new Promise(resolve => {
    if (!file || !file.type.startsWith('image/')) { resolve(file); return; }
    const reader = new FileReader();
    reader.onload = ev => {
      const img = new Image();
      img.onload = () => {
        const MAX = 1024, TARGET = 150*1024;
        let w = img.width, h = img.height;
        if (w > MAX || h > MAX) {
          if (w >= h) { h = Math.round(h*MAX/w); w = MAX; }
          else        { w = Math.round(w*MAX/h); h = MAX; }
        }
        const canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        let quality = 0.80;
        const attempt = () => {
          canvas.toBlob(blob => {
            if (!blob) { resolve(file); return; }
            if (blob.size <= TARGET || quality <= 0.25) {
              resolve(new File([blob], 'image.' + _P2P_EXT, {type: _P2P_FMT}));
            } else { quality = Math.max(0.25, quality - 0.10); attempt(); }
          }, _P2P_FMT, quality);
        };
        attempt();
      };
      img.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  });
}
async function _p2pCompressFormData(fd, fieldName) {
  const file = fd.get(fieldName);
  if (!file || !file.size) return;
  const compressed = await _p2pCompress(file);
  fd.set(fieldName, compressed);
}

const U='<?=SITE_URL?>',LG=<?=isLoggedIn()?'true':'false'?>,VR=<?=$isVerified?'true':'false'?>,UID=<?=$user?$user['id']:0?>,LOGO='<?=$siteLogo?SITE_URL."/".$siteLogo:""?>';
let tab='market',cp=null;
const $=id=>document.getElementById(id),E=s=>{const d=document.createElement('div');d.textContent=s;return d.innerHTML},F=v=>parseFloat(v).toFixed(2);
function tA(dt){if(!dt)return'';const d=Math.floor((Date.now()-new Date(dt))/1000);if(d<60)return'الآن';if(d<3600)return Math.floor(d/60)+' د';if(d<86400)return Math.floor(d/3600)+' س';return Math.floor(d/86400)+' ي';}
function sImg(img,icon,sz){const s=sz||'36';const px=s+'px';if(img)return`<div style="width:${px};height:${px};border-radius:12px;overflow:hidden;flex-shrink:0;border:1px solid var(--border)"><img src="${U}/${img}" style="width:100%;height:100%;object-fit:cover"></div>`;if(LOGO)return`<div style="width:${px};height:${px};border-radius:12px;overflow:hidden;flex-shrink:0;background:var(--card2);padding:4px;border:1px solid var(--border)"><img src="${LOGO}" style="width:100%;height:100%;object-fit:contain"></div>`;return`<div style="width:${px};height:${px};border-radius:12px;background:rgba(30,111,255,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--primary);font-size:${parseInt(s)/2}px"><i class="fas fa-${icon||'cube'}"></i></div>`;}
async function api(a,d={},m='GET'){const u=U+'/api/p2p.php';if(m==='GET'){const p=new URLSearchParams({action:a,...d});return(await fetch(u+'?'+p,{credentials:'same-origin'})).json();}const f=new FormData();f.append('action',a);for(const[k,v]of Object.entries(d))f.append(k,v);return(await fetch(u,{method:'POST',body:f,credentials:'same-origin'})).json();}
function go(t){tab=t;clearInterval(cp);document.querySelectorAll('.nav-item').forEach(b=>{b.classList.toggle('active',b.dataset.t===t)});
if(t==='market')lM();else if(t==='orders')lO();else if(t==='chats')lChats();else if(t==='sell')lS();else if(t==='mysvcs')lMS();}
function oM(){$('M').classList.add('open');}function cM(){$('M').classList.remove('open');}
function spin(){return'<div style="text-align:center;padding:60px 0;color:var(--text3)"><i class="fas fa-spinner fa-spin" style="font-size:24px"></i></div>';}
function empty(ic,tx){return'<div style="text-align:center;padding:60px 0;color:var(--text3)"><i class="fas fa-'+ic+'" style="font-size:40px;opacity:.2;display:block;margin-bottom:12px"></i><p style="font-size:.9rem">'+tx+'</p></div>';}

// ══ MARKET ══
let _cats=[], _curCat=0;

// ── عرض الأقسام كمربعات ──────────────────────────────────────────────────────
function renderCatGrid(cats) {
  const gradients = [
    'linear-gradient(135deg,#1e3a8a,#1e6fff)',
    'linear-gradient(135deg,#7c3aed,#a78bfa)',
    'linear-gradient(135deg,#065f46,#00d4aa)',
    'linear-gradient(135deg,#92400e,#f5a623)',
    'linear-gradient(135deg,#9f1239,#ff4455)',
    'linear-gradient(135deg,#164e63,#00d4ff)',
    'linear-gradient(135deg,#3730a3,#6366f1)',
    'linear-gradient(135deg,#14532d,#00e676)',
  ];
  if (!cats.length) return empty('store', 'لا توجد أقسام متاحة حالياً');
  return `
    <div style="margin-bottom:16px">
      <div style="font-size:1.1rem;font-weight:900;color:#fff;margin-bottom:4px">السوق</div>
      <div style="font-size:.78rem;color:var(--text3)">اختر القسم لعرض الخدمات المتاحة</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px">
      ${cats.map((ct,i) => {
        const grad = gradients[i % gradients.length];
        const iconHtml = ct.image
          ? `<img src="${U}/${ct.image}" style="width:52px;height:52px;object-fit:cover;border-radius:14px;margin-bottom:10px">`
          : `<div style="width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;margin-bottom:10px;font-size:22px">
               <i class="fas fa-${ct.icon||'cube'}" style="color:#fff"></i>
             </div>`;
        return `
          <div onclick="openCat(${ct.id},'${E(ct.name)}')"
               style="background:${grad};border-radius:18px;padding:18px 14px;cursor:pointer;
                      position:relative;overflow:hidden;border:1px solid rgba(255,255,255,.08);
                      transition:transform .15s,box-shadow .15s;active-transform:scale(.96)"
               ontouchstart="this.style.transform='scale(.96)';this.style.boxShadow='none'"
               ontouchend="this.style.transform='';this.style.boxShadow=''"
               onmousedown="this.style.transform='scale(.96)'"
               onmouseup="this.style.transform=''">
            <!-- خلفية زخرفية -->
            <div style="position:absolute;top:-20px;left:-20px;width:80px;height:80px;
                        border-radius:50%;background:rgba(255,255,255,.06)"></div>
            <div style="position:absolute;bottom:-30px;right:-10px;width:90px;height:90px;
                        border-radius:50%;background:rgba(255,255,255,.04)"></div>
            <div style="position:relative;z-index:1">
              ${iconHtml}
              <div style="font-size:.88rem;font-weight:900;color:#fff;line-height:1.3;margin-bottom:5px">${E(ct.name)}</div>
              ${ct.svc_count>0
                ? `<div style="display:inline-flex;align-items:center;gap:4px;background:rgba(255,255,255,.15);
                              border-radius:20px;padding:2px 9px;font-size:.68rem;font-weight:700;color:rgba(255,255,255,.9)">
                     <i class="fas fa-box" style="font-size:.6rem"></i> ${ct.svc_count} خدمة
                   </div>`
                : `<div style="font-size:.68rem;color:rgba(255,255,255,.5)">لا توجد خدمات</div>`
              }
            </div>
          </div>`;
      }).join('')}
    </div>`;
}

// ── عرض الخدمات داخل قسم ────────────────────────────────────────────────────
function renderSvcList(services, catName) {
  return `
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
      <button onclick="_curCat=0;lM()"
              style="width:36px;height:36px;background:var(--card2);border:1px solid var(--border);
                     border-radius:12px;color:var(--text2);font-size:15px;cursor:pointer;
                     display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="fas fa-arrow-right"></i>
      </button>
      <div>
        <div style="font-size:1rem;font-weight:900;color:#fff">${E(catName)}</div>
        <div style="font-size:.73rem;color:var(--text3)">${services.length} خدمة متاحة</div>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px">
      ${services.map((s,i) => `
        <div onclick="oS(${s.id})"
             style="background:var(--card);border:1px solid var(--border);border-radius:16px;
                    padding:14px;cursor:pointer;border-right:3px solid var(--primary);
                    animation:fadeUp .25s ease ${i*0.04}s both">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
            <div style="display:flex;gap:10px;align-items:flex-start;flex:1;min-width:0">
              ${sImg(s.image, s.cat_icon||'cube')}
              <div style="min-width:0">
                <div style="font-size:.88rem;font-weight:800;color:#fff;
                            white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${E(s.title)}</div>
                <div style="font-size:.72rem;color:var(--text3);margin-top:3px;display:flex;align-items:center;gap:4px">
                  <span onclick="event.stopPropagation();openProfile(${s.seller_uid})"
                        style="color:var(--primary);cursor:pointer">${E(s.seller_display)}</span>
                  <span>·</span>
                  <span style="color:${s.seller_online?'#00e676':'var(--text3)'}">
                    <i class="fas fa-circle" style="font-size:.4rem"></i>
                    ${s.seller_online?'متصل':'غير متصل'}
                  </span>
                </div>
                ${s.description?`<div style="font-size:.72rem;color:var(--text3);margin-top:4px;
                  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px">${E(s.description)}</div>`:''}
              </div>
            </div>
            <div style="text-align:left;flex-shrink:0">
              <div style="font-size:1.1rem;font-weight:900;color:#00d4aa;line-height:1">${F(s.buyer_price)}$</div>
              <div style="font-size:.65rem;color:var(--text3);margin-top:3px">متاح: ${s.remaining}</div>
            </div>
          </div>
        </div>`).join('')}
    </div>`;
}

async function lM(){
  const c=$('A');
  c.innerHTML=spin();
  if(!_cats.length){const cd=await api('categories');if(cd.ok)_cats=cd.categories||[];}
  if(!_curCat){
    // عرض الأقسام كمربعات
    c.innerHTML=renderCatGrid(_cats);
    return;
  }
  // عرض الخدمات داخل القسم
  const d=await api('services',{category_id:_curCat});
  const cat=_cats.find(x=>x.id==_curCat)||{};
  if(!d.ok||!d.services.length){
    c.innerHTML=renderSvcList([],cat.name||'الخدمات');
    c.querySelector('div[style*="flex-direction:column"]').innerHTML=empty('box-open','لا توجد خدمات في هذا القسم');
    return;
  }
  c.innerHTML=renderSvcList(d.services, cat.name||'الخدمات');
}

function openCat(id, name){
  _curCat=id;
  lM();
}

async function oS(id){const d=await api('services');if(!d.ok)return;const s=d.services.find(x=>x.id==id);if(!s)return;
$('MB').innerHTML=`<div class="w-4 h-1 bg-slate-600 rounded-full mx-auto mb-6"></div><div class="text-center mb-6">${sImg(s.image,'design_services','w-20 h-20')}<h2 class="font-black text-xl text-white mb-1 mt-4">${E(s.title)}</h2><p class="text-xs text-slate-400"><span class="${s.seller_online?'text-green-400':'text-slate-500'}">●</span> <span onclick="cM();openProfile(${s.seller_uid})" class="text-blue-400 hover:underline cursor-pointer">${E(s.seller_display)}</span> · ${s.seller_sales} عملية</p></div>${s.description?`<div class="bg-white/[.03] rounded-2xl p-4 mb-5 text-sm text-slate-300 leading-relaxed border border-white/5">${E(s.description)}</div>`:''}<div class="bg-green-500/5 border border-green-500/15 rounded-2xl p-5 text-center mb-6"><div class="text-xs text-slate-400 mb-1">السعر الإجمالي</div><div class="text-3xl font-black text-green-400">${F(s.buyer_price)} $</div><div class="text-[10px] text-slate-500 mt-1">${s.commission_payer==='buyer'?'العمولة على المشتري':'العمولة على البائع'} · ${F(s.commission_amount)}$</div></div><button onclick="bS(${s.id})" id="bB" class="w-full py-4 rounded-2xl bg-gradient-to-r from-green-600 to-green-500 text-white font-bold shadow-lg shadow-green-500/25 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-xl">bolt</span>شراء الآن</button><button onclick="cM()" class="w-full py-3 mt-3 rounded-xl text-slate-400 font-semibold hover:bg-white/5">إلغاء</button>`;oM();}

async function bS(id){if(!LG){alert('سجل دخولك');return;}if(!VR){alert('وثّق حسابك');return;}const b=$('bB');b.disabled=true;b.innerHTML='<span class="mi animate-spin">progress_activity</span> جاري...';const d=await api('buy',{service_id:id},'POST');if(d.ok){cM();rO(d.order_id);}else{alert('❌ '+(d.error||'خطأ'));b.disabled=false;b.innerHTML='<span class="mi">bolt</span>شراء الآن';}}

// ══ ORDERS (merged with history) ══
let _ordFilter='all';
async function lO(){const c=$('A');c.innerHTML=spin();const d=await api('orders');if(!d.ok||!d.orders.length){c.innerHTML=empty('inbox','لا توجد طلبات');return;}
const all=d.orders;
// إحصائيات
const tb=all.filter(o=>o.is_buyer).length,ts=all.filter(o=>!o.is_buyer).length,ca=all.filter(o=>o.status==='completed').reduce((s,o)=>s+parseFloat(o.price),0),actv=all.filter(o=>o.status==='active').length;
// فلترة
const filtered=_ordFilter==='all'?all:all.filter(o=>o.status===_ordFilter);
const st={pending:{c:'border-amber-500',bg:'bg-amber-500/10',tc:'text-amber-400',l:'قيد الانتظار',ic:'schedule'},active:{c:'border-blue-500',bg:'bg-blue-500/10',tc:'text-blue-400',l:'جاري التنفيذ',ic:'sync'},delivered:{c:'border-purple-500',bg:'bg-purple-500/10',tc:'text-purple-400',l:'تم التسليم',ic:'inventory'},completed:{c:'border-green-500',bg:'bg-green-500/10',tc:'text-green-400',l:'مكتمل',ic:'check_circle'},cancelled:{c:'border-red-500/50',bg:'bg-red-500/10',tc:'text-red-400',l:'ملغي',ic:'cancel'},disputed:{c:'border-orange-500',bg:'bg-orange-500/10',tc:'text-orange-400',l:'نزاع',ic:'gavel'}};
const filters=[{k:'all',l:'الكل',n:all.length},{k:'active',l:'نشط',n:actv},{k:'delivered',l:'تم التسليم',n:all.filter(o=>o.status==='delivered').length},{k:'completed',l:'مكتمل',n:all.filter(o=>o.status==='completed').length},{k:'disputed',l:'نزاع',n:all.filter(o=>o.status==='disputed').length},{k:'cancelled',l:'ملغي',n:all.filter(o=>o.status==='cancelled').length}];
c.innerHTML=`
<div class="grid grid-cols-3 gap-2 mb-4">\
<div class="glass-card rounded-2xl p-3 text-center fade-up"><div class="text-xl font-black text-green-400">${tb}</div><div class="text-[9px] text-slate-500">مشتريات</div></div>\
<div class="glass-card rounded-2xl p-3 text-center fade-up fd1"><div class="text-xl font-black text-purple-400">${ts}</div><div class="text-[9px] text-slate-500">مبيعات</div></div>\
<div class="glass-card rounded-2xl p-3 text-center fade-up fd2"><div class="text-xl font-black text-amber-400">${ca.toFixed(2)}$</div><div class="text-[9px] text-slate-500">إجمالي مكتمل</div></div></div>
<div class="flex gap-2 overflow-x-auto pb-3 mb-3" style="scrollbar-width:none">${filters.map(f=>`<button onclick="_ordFilter='${f.k}';lO()" class="flex-shrink-0 px-4 py-2 rounded-xl text-xs font-bold transition-all ${_ordFilter===f.k?'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/20':'bg-white/5 text-slate-400 border border-white/5'}">${f.l} <span class="opacity-60">${f.n}</span></button>`).join('')}</div>
${!filtered.length?'<div class="text-center py-10 text-slate-500 text-sm">لا توجد طلبات في هذا القسم</div>':
'<div class="space-y-3">'+filtered.map((o,i)=>{const s=st[o.status]||st.pending;const dt=new Date(o.created_at||o.updated_at);return`<div onclick="viewOrdDetail(${o.id})" class="glass-card rounded-[18px] p-4 ${s.c} border-r-4 cursor-pointer hover:bg-slate-900/80 transition-all fade-up ${['','fd1','fd2','fd3','fd4'][i%5]}"><div class="flex justify-between items-start mb-2"><div class="flex-1 min-w-0"><h3 class="font-bold text-[14px] text-white truncate">${E(o.svc_title)}</h3><span class="text-[10px] text-slate-400">${o.is_buyer?'🛒 مشتري':'🏪 بائع'} · ${E(o.other_name)} · #${o.id} · ${dt.toLocaleDateString('ar',{month:'short',day:'numeric'})}</span></div><div class="${s.tc} font-black text-base">${F(o.price)}$</div></div><div class="flex items-center justify-between"><div class="flex items-center gap-1.5 px-3 py-1 ${s.bg} rounded-full ${s.tc}"><span class="mi text-sm" style="font-variation-settings:'FILL' 1">${s.ic}</span><span class="text-xs font-medium">${s.l}</span></div>${o.unread>0?`<div class="bg-blue-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">${o.unread} جديدة</div>`:''}</div></div>`;}).join('')+'</div>'}`;
}

// ══ ORDER DETAIL (from orders list) ══
async function viewOrdDetail(id){
const c=$('A');c.innerHTML=spin();
const d=await api('order',{id});if(!d.ok){c.innerHTML='<div class="text-center py-20 text-red-400">'+E(d.error||'خطأ')+'</div>';return;}
const o=d.order;
const stl={pending:'قيد الانتظار',active:'جاري التنفيذ',completed:'مكتمل',cancelled:'ملغي'};
const stc={pending:'text-amber-400 bg-amber-500/10 border-amber-500/15',active:'text-blue-400 bg-blue-500/10 border-blue-500/15',completed:'text-green-400 bg-green-500/10 border-green-500/15',cancelled:'text-red-400 bg-red-500/10 border-red-500/15'};
const cls=stc[o.status]||stc.pending;
c.innerHTML=`<div class="fade-up">
<div class="flex items-center gap-3 mb-4"><button onclick="go('orders')" class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-slate-300 hover:text-white active:scale-90 transition-all"><span class="mi">arrow_forward</span></button><h2 class="font-bold text-base text-white flex-1">تفاصيل الطلب #${o.id}</h2><span class="text-xs font-bold px-3 py-1 rounded-full border ${cls}">${stl[o.status]||o.status}</span></div>
<div class="glass-card rounded-[18px] p-5 mb-3">
<div class="flex items-center gap-3 mb-4 pb-4 border-b border-white/5">
<div class="w-12 h-12 rounded-xl bg-[#252a35] flex items-center justify-center text-lg overflow-hidden relative">${o.other_avatar?`<img src="${o.other_avatar.startsWith('http')?o.other_avatar:U+'/'+o.other_avatar}" class="w-full h-full object-cover">`:'👤'}${o.other_online?'<span class="absolute bottom-0 left-0 w-3 h-3 bg-green-500 rounded-full border-2 border-[#252a35]"></span>':''}</div>
<div class="flex-1"><div class="font-bold text-white">${E(o.other_name)}</div><div class="text-[10px] text-slate-400">${o.other_online?'<span class="text-green-400">● متصل</span>':'● '+tA(o.other_last_seen)} · #${String(o.other_uid).padStart(6,'0')} · <span class="text-green-400">✓ موثق</span></div></div>
</div>
<div class="space-y-3 text-sm">
<div class="flex justify-between"><span class="text-slate-400">الخدمة</span><span class="text-white font-bold">${E(o.svc_title)}</span></div>
<div class="flex justify-between"><span class="text-slate-400">السعر</span><span class="text-green-400 font-black">${F(o.price)} $</span></div>
<div class="flex justify-between"><span class="text-slate-400">العمولة</span><span class="text-amber-400">${F(o.commission)} $ (${o.commission_payer==='buyer'?'مشتري':'بائع'})</span></div>
<div class="flex justify-between"><span class="text-slate-400">الدور</span><span class="text-white">${o.is_buyer?'🛒 أنت المشتري':'🏪 أنت البائع'}</span></div>
<div class="flex justify-between"><span class="text-slate-400">التاريخ</span><span class="text-slate-300">${new Date(o.created_at).toLocaleDateString('ar',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}</span></div>
${o.completed_at?`<div class="flex justify-between"><span class="text-slate-400">تم الإتمام</span><span class="text-green-400">${new Date(o.completed_at).toLocaleDateString('ar',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}</span></div>`:''}
${o.cancelled_at?`<div class="flex justify-between"><span class="text-slate-400">تم الإلغاء</span><span class="text-red-400">${new Date(o.cancelled_at).toLocaleDateString('ar',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}</span></div>`:''}
</div></div>
<div class="space-y-2">
${o.status==='active'||o.status==='completed'?`<button onclick="rO(${o.id})" class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 text-white font-bold shadow-lg shadow-blue-500/25 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">chat</span>الذهاب للدردشة</button>`:''}
${o.status==='active'||o.status==='completed'||o.status==='delivered'?`<button onclick="rO(${o.id})" class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 text-white font-bold shadow-lg shadow-blue-500/25 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">chat</span>الذهاب للدردشة</button>`:''}
${o.is_seller&&o.status==='active'?`<button onclick="markDelivered(${o.id})" class="w-full py-3 rounded-2xl bg-gradient-to-r from-purple-600 to-purple-500 text-white font-bold shadow-lg shadow-purple-500/20 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">inventory</span>تم التسليم</button><button onclick="cnO(${o.id})" class="w-full py-3 rounded-2xl bg-red-500/10 text-red-400 border border-red-500/20 font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">close</span>إلغاء الطلب</button>`:''}
${o.is_buyer&&o.status==='active'?`<button onclick="reqCancel(${o.id})" class="w-full py-3 rounded-2xl bg-amber-500/10 text-amber-400 border border-amber-500/20 font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">cancel_schedule_send</span>طلب إلغاء</button>`:''}
${o.is_buyer&&o.status==='pending'?`<button onclick="cnO(${o.id})" class="w-full py-3 rounded-2xl bg-red-500/10 text-red-400 border border-red-500/20 font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">close</span>إلغاء</button>`:''}
${o.is_buyer&&o.status==='delivered'?`<div class="glass-card rounded-2xl p-4 mb-2"><div class="flex items-center gap-2 text-purple-400 text-xs font-bold mb-3"><span class="mi text-sm">inventory</span>بيانات التسليم</div><div class="bg-[#0a0e18] rounded-xl p-3 text-sm text-slate-300 leading-relaxed whitespace-pre-wrap" id="dData">جاري التحميل...</div>${o.auto_complete_at?`<div class="mt-3 flex items-center gap-2 text-amber-400 text-[10px] font-bold"><span class="mi text-sm">timer</span>سيتم الإتمام تلقائياً: ${new Date(o.auto_complete_at).toLocaleDateString('ar',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}</div>`:''}</div><div class="glass-card rounded-2xl p-4 mb-2 border border-amber-500/15"><div class="flex items-center gap-2 text-amber-400 text-xs font-bold mb-2"><span class="mi text-sm">warning</span>تأكيد أمني</div><p class="text-[10px] text-slate-400 mb-3">تأكد من تغيير جميع بيانات الحساب قبل التأكيد. لن تتمكن من فتح نزاع بسهولة بعد ذلك.</p><label class="flex items-center gap-2 mb-2 cursor-pointer"><input type="checkbox" id="ckEmail" class="accent-green-500 w-4 h-4"><span class="text-xs text-slate-300">تم تغيير البريد الإلكتروني</span></label><label class="flex items-center gap-2 mb-2 cursor-pointer"><input type="checkbox" id="ckPass" class="accent-green-500 w-4 h-4"><span class="text-xs text-slate-300">تم تغيير كلمة المرور</span></label><label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="ckRecov" class="accent-green-500 w-4 h-4"><span class="text-xs text-slate-300">تم إزالة وسائل الاسترجاع القديمة</span></label></div><button onclick="cfO(${o.id})" class="w-full py-3 rounded-2xl bg-gradient-to-r from-green-600 to-green-500 text-white font-bold shadow-lg shadow-green-500/20 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">check_circle</span>تأكيد الاستلام</button><button onclick="openDispute(${o.id})" class="w-full py-3 rounded-2xl bg-orange-500/10 text-orange-400 border border-orange-500/20 font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">gavel</span>فتح نزاع</button>`:''}
${o.is_seller&&o.status==='delivered'?`<div class="glass-card rounded-2xl p-3 text-center text-xs text-purple-300"><span class="mi text-sm align-middle">hourglass_top</span> بانتظار تأكيد المشتري${o.auto_complete_at?' · إتمام تلقائي: '+new Date(o.auto_complete_at).toLocaleDateString('ar',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):''}${o.buyer_viewed_delivery?' · <span class="text-green-400">👁 المشتري شاهد البيانات</span>':''}</div>`:''}
${o.status==='active'&&o.cancel_requested_by==='buyer'&&o.is_seller?`<div class="glass-card rounded-2xl p-4 border border-amber-500/20"><div class="text-xs text-amber-400 font-bold mb-3"><span class="mi text-sm align-middle">warning</span> المشتري يطلب إلغاء الطلب</div><div class="flex gap-2"><button onclick="approveCancel(${o.id})" class="flex-1 py-2.5 rounded-xl bg-red-500/10 text-red-400 border border-red-500/20 text-xs font-bold active:scale-95">قبول الإلغاء</button><button onclick="rejectCancel(${o.id})" class="flex-1 py-2.5 rounded-xl bg-green-500/10 text-green-400 border border-green-500/20 text-xs font-bold active:scale-95">رفض</button></div></div>`:''}
${o.status==='disputed'?`<div class="glass-card rounded-2xl p-4 border border-orange-500/20 text-center"><span class="mi text-3xl text-orange-400 block mb-2">gavel</span><div class="text-sm font-bold text-orange-400">الطلب في نزاع</div><div class="text-[10px] text-slate-500 mt-1">الإدارة ستقوم بمراجعة الطلب والمحادثة وبيانات التسليم</div></div>`:''}
</div></div>`;
// تحميل بيانات التسليم للمشتري
if(o.is_buyer&&o.status==='delivered'){(async()=>{const dd=await api('view_delivery',{order_id:id});const el=$('dData');if(el&&dd.ok)el.textContent=dd.delivery_data||'لا توجد بيانات';})();}
}

// ══ ORDER DETAIL + CHAT (Professional) ══
function progBar(status){
const steps=[{k:'pending',l:'انتظار',ic:'schedule'},{k:'active',l:'تنفيذ',ic:'sync'},{k:'delivered',l:'تسليم',ic:'inventory'},{k:'completed',l:'مكتمل',ic:'check_circle'}];
const idx=steps.findIndex(s=>s.k===status);const isDisputed=status==='disputed';const isCancelled=status==='cancelled';
if(isDisputed||isCancelled)return`<div class="flex items-center justify-center gap-2 py-3 px-4 rounded-2xl ${isDisputed?'bg-orange-500/10 border border-orange-500/15':'bg-red-500/10 border border-red-500/15'} mb-3"><span class="mi text-lg ${isDisputed?'text-orange-400':'text-red-400'}">${isDisputed?'gavel':'cancel'}</span><span class="text-xs font-bold ${isDisputed?'text-orange-400':'text-red-400'}">${isDisputed?'⚖️ الطلب في نزاع':'❌ تم الإلغاء'}</span></div>`;
return`<div class="flex items-center justify-between mb-3 px-2">${steps.map((s,i)=>{
const done=i<=idx,cur=i===idx;
return`<div class="flex flex-col items-center gap-1 flex-1"><div class="w-8 h-8 rounded-full flex items-center justify-center text-xs transition-all ${done?'bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/30':cur?'bg-blue-500/20 text-blue-400 border border-blue-500/30':'bg-white/5 text-slate-600'}"><span class="mi text-sm" ${done?'style="font-variation-settings:\'FILL\' 1"':''}>${s.ic}</span></div><span class="text-[9px] ${done?'text-blue-400 font-bold':cur?'text-slate-300':'text-slate-600'}">${s.l}</span>${i<steps.length-1?'':''}
</div>${i<steps.length-1?`<div class="h-0.5 flex-1 mx-1 mt-[-14px] rounded-full ${i<idx?'bg-blue-500':'bg-white/5'}"></div>`:''}`}).join('')}</div>`;}

async function rO(id){const c=$('A');c.innerHTML=spin();document.querySelectorAll('.bn').forEach(b=>b.classList.remove('on'));$('nT').textContent='طلب #'+id;$('nT').className='font-bold text-lg text-blue-400';
const d=await api('order',{id});if(!d.ok){c.innerHTML='<div class="text-center py-20 text-red-400">'+E(d.error||'خطأ')+'</div>';return;}const o=d.order;
const chatTl=o.status==='active'&&o.chat_expires_at?Math.max(0,Math.floor((new Date(o.chat_expires_at)-Date.now())/1000)):0;
const autoTl=o.status==='delivered'&&o.auto_complete_at?Math.max(0,Math.floor((new Date(o.auto_complete_at)-Date.now())/1000)):0;
const stLabel={pending:'قيد الانتظار',active:'جاري التنفيذ',delivered:'تم التسليم',completed:'مكتمل',cancelled:'ملغي',disputed:'نزاع مفتوح'};

c.innerHTML=`<div class="fade-up">
<header class="glass-card rounded-[18px] p-4 mb-3 flex items-center gap-3">
<button onclick="go('chats')" class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-slate-300 hover:text-white active:scale-90 transition-all"><span class="mi">arrow_forward</span></button>
<div class="w-11 h-11 rounded-xl bg-[#252a35] flex items-center justify-center text-lg overflow-hidden relative flex-shrink-0">${o.other_avatar?`<img src="${o.other_avatar.startsWith('http')?o.other_avatar:U+'/'+o.other_avatar}" class="w-full h-full object-cover">`:'👤'}${o.other_online?'<span class="absolute bottom-0 left-0 w-3 h-3 bg-green-500 rounded-full border-2 border-[#252a35]"></span>':''}</div>
<div class="flex-1 min-w-0"><h2 class="font-bold text-base text-white">${E(o.other_name)}</h2><p class="text-[10px] text-slate-400 truncate">${E(o.svc_title)} · ${F(o.price)}$ · #${o.id}</p></div>
${['active','completed','delivered'].includes(o.status)?`<button onclick="openChatMenu(${o.id})" class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-slate-300 hover:text-white active:scale-90 transition-all flex-shrink-0"><span class="mi">more_vert</span></button>`:''}</header>

${progBar(o.status)}

${chatTl>0?`<div class="flex items-center gap-2 px-4 py-2 rounded-2xl bg-cyan-500/[.08] border border-cyan-500/15 text-cyan-400 text-xs font-bold mb-3"><span class="mi text-sm">timer</span>المحادثة تنتهي خلال <span id="tm" class="font-mono text-sm">${Math.floor(chatTl/60)}:${String(chatTl%60).padStart(2,'0')}</span></div>`:''}
${autoTl>0?`<div class="flex items-center gap-2 px-4 py-2 rounded-2xl bg-amber-500/[.08] border border-amber-500/15 text-amber-400 text-xs font-bold mb-3"><span class="mi text-sm">hourglass_top</span>إتمام تلقائي خلال <span id="atm" class="font-mono text-sm">${Math.floor(autoTl/3600)}س ${Math.floor((autoTl%3600)/60)}د</span></div>`:''}

${['active','completed','delivered','disputed'].includes(o.status)?`<div class="glass-card rounded-[18px] overflow-hidden mb-3"><div id="ms" class="h-[300px] overflow-y-auto p-4 space-y-3 custom-scroll">${spin()}</div>
${o.status==='active'||o.status==='delivered'?`<div class="flex items-center gap-3 p-3 bg-slate-900/80 backdrop-blur-2xl border-t border-white/5"><div class="flex-1"><input id="mi" type="text" placeholder="اكتب رسالتك..." class="w-full bg-[#303540]/50 border-none rounded-2xl py-3 px-5 text-sm text-[#dfe2f1] focus:ring-2 focus:ring-blue-500/30 placeholder:text-slate-500 font-[Cairo]" onkeydown="if(event.key==='Enter')sM(${o.id})"/></div><button onclick="sM(${o.id})" class="w-12 h-12 flex items-center justify-center rounded-2xl bg-gradient-to-tr from-blue-700 to-blue-500 text-white shadow-[0_8px_16px_-4px_rgba(0,90,194,.4)] active:scale-95 transition-transform"><span class="mi text-xl rotate-180" style="font-variation-settings:'FILL' 1">send</span></button></div>`:''}</div>`:''}

${o.is_seller&&o.status==='active'?`<button onclick="markDelivered(${o.id})" class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-purple-600 to-purple-500 text-white font-bold shadow-lg shadow-purple-500/20 active:scale-[.97] transition-transform flex items-center justify-center gap-2 mb-2"><span class="mi text-lg">inventory</span>تم التسليم</button>`:''}
${o.is_buyer&&o.status==='active'?`<button onclick="reqCancel(${o.id})" class="w-full py-3 rounded-2xl bg-amber-500/10 text-amber-400 border border-amber-500/20 font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2 text-sm mb-2"><span class="mi text-lg">cancel_schedule_send</span>طلب إلغاء</button>`:''}
${o.is_buyer&&o.status==='pending'?`<button onclick="cnO(${o.id})" class="w-full py-3 rounded-2xl bg-red-500/10 text-red-400 border border-red-500/20 font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2 text-sm"><span class="mi text-lg">close</span>إلغاء الطلب</button>`:''}
${o.status==='active'&&o.cancel_requested_by==='buyer'&&o.is_seller?`<div class="glass-card rounded-2xl p-4 border border-amber-500/20 mb-2"><div class="text-xs text-amber-400 font-bold mb-3"><span class="mi text-sm align-middle">warning</span> المشتري يطلب إلغاء</div><div class="flex gap-2"><button onclick="approveCancel(${o.id})" class="flex-1 py-2.5 rounded-xl bg-red-500/10 text-red-400 border border-red-500/20 text-xs font-bold active:scale-95">قبول الإلغاء</button><button onclick="rejectCancel(${o.id})" class="flex-1 py-2.5 rounded-xl bg-green-500/10 text-green-400 border border-green-500/20 text-xs font-bold active:scale-95">رفض</button></div></div>`:''}

${o.is_buyer&&o.status==='delivered'?`
<div class="glass-card rounded-2xl p-4 mb-2"><div class="flex items-center gap-2 text-purple-400 text-xs font-bold mb-2"><span class="mi text-sm">inventory</span>بيانات التسليم</div><div class="bg-[#0a0e18] rounded-xl p-3 text-sm text-slate-300 leading-relaxed whitespace-pre-wrap" id="dDataChat">جاري التحميل...</div></div>
<div class="glass-card rounded-2xl p-4 mb-2 border border-amber-500/15"><div class="flex items-center gap-2 text-amber-400 text-xs font-bold mb-2"><span class="mi text-sm">shield</span>تأكيد أمني مطلوب</div><p class="text-[10px] text-slate-400 mb-2">تأكد من تغيير جميع بيانات الحساب. لن تتمكن من فتح نزاع بسهولة بعد التأكيد.</p>
<label class="flex items-center gap-2 mb-1.5 cursor-pointer"><input type="checkbox" id="ckEmail" class="accent-green-500 w-4 h-4"><span class="text-xs text-slate-300">تم تغيير البريد الإلكتروني</span></label>
<label class="flex items-center gap-2 mb-1.5 cursor-pointer"><input type="checkbox" id="ckPass" class="accent-green-500 w-4 h-4"><span class="text-xs text-slate-300">تم تغيير كلمة المرور</span></label>
<label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="ckRecov" class="accent-green-500 w-4 h-4"><span class="text-xs text-slate-300">تم إزالة وسائل الاسترجاع</span></label></div>
<div class="flex gap-2"><button onclick="cfO(${o.id})" class="flex-1 py-3.5 rounded-2xl bg-gradient-to-r from-green-600 to-green-500 text-white font-bold shadow-lg shadow-green-500/20 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">check_circle</span>تأكيد الاستلام</button><button onclick="openDispute(${o.id})" class="px-5 py-3.5 rounded-2xl bg-orange-500/10 text-orange-400 border border-orange-500/20 font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi text-lg">gavel</span>نزاع</button></div>`:''}

${o.is_seller&&o.status==='delivered'?`<div class="glass-card rounded-2xl p-4 text-center"><span class="mi text-3xl text-purple-400 block mb-2" style="font-variation-settings:'FILL' 1">hourglass_top</span><div class="text-sm font-bold text-purple-300 mb-1">بانتظار تأكيد المشتري</div><div class="text-[10px] text-slate-500">${o.auto_complete_at?'إتمام تلقائي: '+new Date(o.auto_complete_at).toLocaleDateString('ar',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):''}${o.buyer_viewed_delivery?' · <span class="text-green-400">👁 شاهد البيانات</span>':''}</div></div>`:''}

${o.status==='disputed'?`<div class="glass-card rounded-2xl p-5 text-center border border-orange-500/20"><span class="mi text-4xl text-orange-400 block mb-2">gavel</span><div class="text-sm font-bold text-orange-400 mb-1">الطلب في نزاع</div><div class="text-[10px] text-slate-500">الإدارة ستراجع المحادثة وبيانات التسليم وسجل الأحداث</div></div>`:''}

${o.status==='completed'?`<div id="rateArea"></div>`:''}
</div>`;

// تحميل الرسائل
if(['active','completed','delivered','disputed'].includes(o.status)){lMs(id);if(['active','delivered'].includes(o.status))cp=setInterval(()=>lMs(id),4000);}
// تحميل بيانات التسليم
if(o.is_buyer&&o.status==='delivered'){(async()=>{const dd=await api('view_delivery',{order_id:id});const el=$('dDataChat');if(el&&dd.ok)el.textContent=dd.delivery_data||'لا توجد بيانات';})();}
// عداد المحادثة
if(chatTl>0){let t=chatTl;setInterval(()=>{t--;const e=$('tm');if(e)e.textContent=Math.floor(t/60)+':'+String(t%60).padStart(2,'0');},1000);}
// عداد الإتمام التلقائي
if(autoTl>0){let t=autoTl;setInterval(()=>{t--;const e=$('atm');if(e){const h=Math.floor(t/3600),m=Math.floor((t%3600)/60);e.textContent=h+'س '+m+'د';}},1000);}
// تقييم بعد الإتمام
if(o.status==='completed'){const ra=$('rateArea');if(ra)ra.innerHTML=`<button onclick="showRating(${o.id})" class="w-full py-3 mt-3 rounded-2xl bg-amber-500/10 text-amber-400 border border-amber-500/15 font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2 text-sm"><span class="mi">star</span>قيّم هذه التجربة</button>`;}
}

async function lMs(id){const d=await api('messages',{order_id:id});const b=$('ms');if(!b||!d.ok)return;
b.innerHTML=d.messages.map(m=>{if(m.sender_id==0)return`<div class="flex justify-center"><div class="bg-purple-500/10 border border-purple-500/15 rounded-full px-5 py-1.5 text-purple-300 text-[11px] font-medium">${E(m.message)}</div></div>`;const mine=m.sender_id==UID;
return mine?`<div class="flex flex-row-reverse items-end gap-2 max-w-[85%] mr-auto"><div><div class="bg-gradient-to-br from-blue-600 to-blue-700 text-white p-3.5 rounded-t-2xl rounded-br-2xl rounded-bl-md shadow-lg shadow-blue-900/30"><p class="text-sm leading-relaxed">${E(m.message)}</p></div><div class="flex items-center justify-end gap-1 mt-1 px-1"><span class="text-[10px] text-slate-500">${tA(m.created_at)}</span><span class="mi text-[12px] text-green-400" style="font-variation-settings:'FILL' 1">done_all</span></div></div></div>`
:`<div class="flex items-end gap-2 max-w-[85%]"><div><div class="bg-[#303540]/80 backdrop-blur-md text-[#dfe2f1] p-3.5 rounded-t-2xl rounded-bl-2xl rounded-br-md"><p class="text-sm leading-relaxed">${E(m.message)}</p></div><span class="text-[10px] text-slate-500 px-1">${tA(m.created_at)}</span></div></div>`;}).join('');b.scrollTop=b.scrollHeight;}

async function sM(id){const i=$('mi');const m=(i?.value||'').trim();if(!m)return;i.value='';await api('send_msg',{order_id:id,message:m},'POST');lMs(id);}
async function cfO(id){
const ck1=$('ckEmail'),ck2=$('ckPass'),ck3=$('ckRecov');
const data={order_id:id};
if(ck1)data.confirmed_email_change=ck1.checked?1:0;
if(ck2)data.confirmed_password_change=ck2.checked?1:0;
if(ck3)data.confirmed_recovery_removed=ck3.checked?1:0;
if(!confirm('تأكيد استلام الخدمة؟ سيتم تحويل المبلغ للبائع.'))return;
const d=await api('confirm',data,'POST');
if(d.ok){alert('✅ '+d.msg);showRating(id);}
else if(d.require_security){alert('⚠️ '+d.error);}
else{alert('❌ '+(d.error||'خطأ'));}}
async function cnO(id){const r=prompt('سبب الإلغاء (اختياري):')||'';const d=await api('cancel',{order_id:id,reason:r},'POST');alert(d.ok?d.msg:'❌ '+(d.error||'خطأ'));if(d.ok){go('orders');}}

async function markDelivered(id){
const ic='w-full bg-[#252a35]/60 border border-[#424754]/30 rounded-2xl py-3 px-4 text-sm text-[#dfe2f1] font-[Cairo] outline-none';
$('MB').innerHTML=`<div class="w-4 h-1 bg-slate-600 rounded-full mx-auto mb-4"></div>
<div class="flex items-center gap-2 mb-4"><span class="mi text-purple-400">inventory</span><h2 class="font-bold text-lg text-white">تسليم الخدمة</h2></div>
<form id="dlvF" class="space-y-3">
<input type="hidden" name="order_id" value="${id}">
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">نوع التسليم</label><select name="delivery_type" class="${ic}"><option value="account">🔑 حساب (بريد + كلمة مرور)</option><option value="code">🔢 كود</option><option value="text" selected>📝 بيانات نصية</option><option value="other">📎 أخرى</option></select></div>
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">بيانات التسليم *</label><textarea name="delivery_data" rows="4" required placeholder="أدخل بيانات التسليم (حساب / رابط / كود / وصف)" class="${ic} resize-y" minlength="10"></textarea><div class="text-[9px] text-slate-500 mt-1">الحد الأدنى 10 أحرف</div></div>
<button type="submit" id="dlvB" class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-purple-600 to-purple-500 text-white font-bold shadow-lg active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi">check</span>تأكيد التسليم</button>
</form>`;oM();
$('dlvF').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.target);fd.append('action','mark_delivered');const b=$('dlvB');b.disabled=true;b.innerHTML='<span class="mi animate-spin">progress_activity</span>';
try{const r=await fetch(U+'/api/p2p.php',{method:'POST',body:fd,credentials:'same-origin'});const t=await r.text();let d;try{d=JSON.parse(t)}catch{alert(t.substring(0,200));b.disabled=false;return;}
if(d.ok){cM();alert('✅ '+d.msg);rO(id);}else{alert('❌ '+(d.error||'خطأ'));b.disabled=false;b.innerHTML='<span class="mi">check</span>تأكيد التسليم';}}catch(er){alert(er.message);b.disabled=false;}};}

async function reqCancel(id){
const r=prompt('سبب طلب الإلغاء:')||'';
const d=await api('request_cancel',{order_id:id,reason:r},'POST');
alert(d.ok?'✅ '+d.msg:'❌ '+(d.error||'خطأ'));if(d.ok)rO(id);}

async function approveCancel(id){if(!confirm('هل تريد الموافقة على الإلغاء؟ سيتم إرجاع المبالغ.'))return;
const d=await api('approve_cancel',{order_id:id},'POST');alert(d.ok?d.msg:'❌ '+(d.error||'خطأ'));if(d.ok){go('orders');}}

async function rejectCancel(id){const d=await api('reject_cancel',{order_id:id},'POST');alert(d.ok?d.msg:'❌ '+(d.error||'خطأ'));if(d.ok)rO(id);}

async function openDispute(id){const r=prompt('سبب فتح النزاع:');if(!r)return;
const d=await api('open_dispute',{order_id:id,reason:r},'POST');alert(d.ok?'✅ '+d.msg:'❌ '+(d.error||'خطأ'));if(d.ok){go('orders');}}

function showRating(orderId){
$('MB').innerHTML=`<div class="w-4 h-1 bg-slate-600 rounded-full mx-auto mb-4"></div>
<div class="text-center mb-4"><span class="mi text-4xl text-amber-400 block mb-2">star</span><h2 class="font-bold text-lg text-white">قيّم التجربة</h2><p class="text-xs text-slate-400">كيف كانت تجربتك مع هذا الطلب؟</p></div>
<div id="stars" class="flex justify-center gap-3 mb-4">${[1,2,3,4,5].map(n=>'<button onclick="pickStar('+n+')" class="stB text-3xl text-slate-600 hover:text-amber-400 transition-colors" data-n="'+n+'">★</button>').join('')}</div>
<textarea id="rCmt" rows="2" placeholder="تعليق (اختياري)" class="w-full bg-[#252a35]/60 border border-[#424754]/30 rounded-2xl py-3 px-4 text-sm text-[#dfe2f1] font-[Cairo] outline-none resize-none mb-3"></textarea>
<button onclick="submitRate(${orderId})" id="rBtn" class="w-full py-3 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-400 text-white font-bold active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi">send</span>إرسال التقييم</button>
<button onclick="cM();go('orders')" class="w-full py-2 mt-2 text-slate-400 text-sm">تخطي</button>`;oM();}
let _sr=0;
function pickStar(n){_sr=n;document.querySelectorAll('.stB').forEach(b=>{b.style.color=parseInt(b.dataset.n)<=n?'#f59e0b':'#475569'});}
async function submitRate(id){if(!_sr){alert('اختر تقييم');return;}const d=await api('rate_order',{order_id:id,rating:_sr,comment:$('rCmt')?.value||''},'POST');if(d.ok){cM();alert(d.msg);go('orders');}else alert('❌ '+(d.error||'خطأ'));}

// ══ SELLER PROFILE ══
async function openProfile(userId){
const c=$('A');c.innerHTML=spin();
document.querySelectorAll('.bn').forEach(b=>b.classList.remove('on'));
$('nT').textContent='الملف الشخصي';$('nT').className='font-bold text-lg text-blue-400';
const d=await api('seller_profile',{user_id:userId});
if(!d.ok){c.innerHTML='<div class="text-center py-20 text-red-400">'+E(d.error||'خطأ')+'</div>';return;}
const p=d;
const avSrc=p.avatar?(p.avatar.startsWith('http')?p.avatar:U+'/'+p.avatar):'';
const trustColor=p.trust_score>=80?'text-green-400':p.trust_score>=60?'text-blue-400':p.trust_score>=40?'text-amber-400':'text-red-400';
const trustBg=p.trust_score>=80?'from-green-600 to-green-500':p.trust_score>=60?'from-blue-600 to-blue-500':p.trust_score>=40?'from-amber-600 to-amber-500':'from-red-600 to-red-500';
const stars=n=>{let s='';for(let i=1;i<=5;i++)s+=`<span style="color:${i<=n?'#f59e0b':'#475569'}">★</span>`;return s;};
const memberDays=Math.floor((Date.now()-new Date(p.member_since).getTime())/86400000);

let ratingsHtml='';
if(p.seller_ratings&&p.seller_ratings.length){
ratingsHtml+=`<div class="mt-4"><div class="flex items-center gap-2 text-xs font-bold text-slate-400 mb-3"><span class="mi text-sm">rate_review</span>تقييمات المشترين (${p.seller_ratings.length})</div>`;
p.seller_ratings.forEach(r=>{const nm=r.display_name||r.full_name||'مستخدم';const rav=r.profile_avatar||r.google_avatar||'';
ratingsHtml+=`<div class="glass-card rounded-2xl p-3 mb-2 flex gap-3"><div class="w-8 h-8 rounded-lg bg-[#252a35] flex items-center justify-center text-sm overflow-hidden flex-shrink-0">${rav?`<img src="${rav.startsWith('http')?rav:U+'/'+rav}" class="w-full h-full object-cover">`:'👤'}</div><div class="flex-1 min-w-0"><div class="flex items-center gap-2 mb-1"><span class="text-xs font-bold text-white">${E(nm)}</span><span class="text-[10px]">${stars(r.rating)}</span></div>${r.comment?`<p class="text-[11px] text-slate-400 leading-relaxed">${E(r.comment)}</p>`:''}<div class="text-[9px] text-slate-600 mt-1">${tA(r.created_at)}</div></div></div>`;});
ratingsHtml+='</div>';}

if(p.buyer_ratings&&p.buyer_ratings.length){
ratingsHtml+=`<div class="mt-4"><div class="flex items-center gap-2 text-xs font-bold text-slate-400 mb-3"><span class="mi text-sm">shopping_cart</span>تقييمات البائعين له (${p.buyer_ratings.length})</div>`;
p.buyer_ratings.forEach(r=>{const nm=r.display_name||r.full_name||'مستخدم';const rav=r.profile_avatar||r.google_avatar||'';
ratingsHtml+=`<div class="glass-card rounded-2xl p-3 mb-2 flex gap-3"><div class="w-8 h-8 rounded-lg bg-[#252a35] flex items-center justify-center text-sm overflow-hidden flex-shrink-0">${rav?`<img src="${rav.startsWith('http')?rav:U+'/'+rav}" class="w-full h-full object-cover">`:'👤'}</div><div class="flex-1 min-w-0"><div class="flex items-center gap-2 mb-1"><span class="text-xs font-bold text-white">${E(nm)}</span><span class="text-[10px]">${stars(r.rating)}</span></div>${r.comment?`<p class="text-[11px] text-slate-400 leading-relaxed">${E(r.comment)}</p>`:''}</div></div>`;});
ratingsHtml+='</div>';}

c.innerHTML=`<div class="fade-up">
<button onclick="go('market')" class="flex items-center gap-2 text-slate-400 text-xs font-semibold mb-4 hover:text-white transition-colors"><span class="mi text-sm">arrow_forward</span>رجوع</button>
<div class="glass-card rounded-[22px] p-6 mb-4 text-center relative overflow-hidden">
<div class="absolute inset-0 bg-gradient-to-b ${trustBg} opacity-[.04]"></div>
<div class="relative">
<div class="w-20 h-20 rounded-2xl bg-[#252a35] flex items-center justify-center text-3xl overflow-hidden mx-auto mb-3 border-2 border-white/10">${avSrc?`<img src="${avSrc}" class="w-full h-full object-cover">`:'👤'}</div>
<h2 class="font-black text-xl text-white mb-1">${E(p.name)}</h2>
<div class="flex items-center justify-center gap-2 text-xs text-slate-400 mb-4">
${p.verified?'<span class="text-green-400 flex items-center gap-0.5"><span class="mi text-sm" style="font-variation-settings:\'FILL\' 1">verified</span>موثق</span>':'<span class="text-slate-500">غير موثق</span>'}
<span>·</span>
${p.online?'<span class="text-green-400">● متصل</span>':`<span class="text-slate-500">● ${tA(p.last_seen)}</span>`}
<span>·</span>
<span>عضو منذ ${memberDays} يوم</span>
</div>
<div class="bg-gradient-to-r ${trustBg} rounded-2xl p-4 mb-4 shadow-lg">
<div class="text-[10px] text-white/70 mb-1">نسبة الثقة</div>
<div class="text-4xl font-black text-white">${p.trust_score}%</div>
<div class="w-full bg-white/20 rounded-full h-2 mt-2"><div class="h-2 rounded-full bg-white/80 transition-all" style="width:${p.trust_score}%"></div></div>
</div>
</div></div>
<div class="grid grid-cols-2 gap-2 mb-4">
<div class="glass-card rounded-2xl p-4 text-center"><div class="text-2xl font-black text-green-400">${p.sell_completed}</div><div class="text-[9px] text-slate-500">مبيعات مكتملة</div><div class="text-[9px] text-slate-600">من ${p.sell_total}</div></div>
<div class="glass-card rounded-2xl p-4 text-center"><div class="text-2xl font-black text-blue-400">${p.buy_completed}</div><div class="text-[9px] text-slate-500">مشتريات مكتملة</div><div class="text-[9px] text-slate-600">من ${p.buy_total}</div></div>
<div class="glass-card rounded-2xl p-4 text-center"><div class="text-2xl font-black text-amber-400">${p.avg_rating||'—'}</div><div class="text-[10px]">${stars(Math.round(p.avg_rating||0))}</div><div class="text-[9px] text-slate-500">${p.total_ratings} تقييم</div></div>
<div class="glass-card rounded-2xl p-4 text-center"><div class="text-2xl font-black text-purple-400">${p.active_services}</div><div class="text-[9px] text-slate-500">خدمات نشطة</div></div>
</div>
<button onclick="copyProfileLink(${userId})" class="w-full py-3 rounded-2xl bg-white/5 border border-white/10 text-slate-300 font-bold text-sm active:scale-[.97] transition-transform flex items-center justify-center gap-2 mb-4"><span class="mi text-lg">link</span>نسخ رابط الملف الشخصي</button>
${p.services&&p.services.length?`<div class="mb-4"><div class="flex items-center gap-2 text-xs font-bold text-slate-400 mb-3"><span class="mi text-sm">storefront</span>خدمات البائع (${p.services.length})</div><div class="space-y-2">${p.services.map(s=>`<div onclick="oS(${s.id})" class="glass-card rounded-2xl p-4 flex items-center gap-3 cursor-pointer hover:bg-white/[.03] active:scale-[.98] transition-all"><div class="flex-1 min-w-0"><div class="font-bold text-sm text-white truncate">${E(s.title)}</div><div class="text-[10px] text-slate-500">${s.cat_name?'<span style="color:'+s.cat_color+'">'+E(s.cat_name)+'</span> · ':''} متاح: ${s.remaining}</div></div><div class="text-left flex-shrink-0"><div class="font-black text-sm text-blue-400">${F(s.buyer_price)}$</div></div></div>`).join('')}</div></div>`:''}
${ratingsHtml}
</div>`;}

function copyProfileLink(userId){
const url=U+'/p2p.php?profile='+userId;
navigator.clipboard.writeText(url).then(()=>alert('✅ تم نسخ الرابط:\n'+url)).catch(()=>{prompt('انسخ الرابط:',url);});}

// ══ SELL ══
function lS(){if(!LG){$('A').innerHTML=empty('login','سجل دخولك أولاً');return;}if(!VR){$('A').innerHTML=empty('verified_user','وثّق حسابك لبيع الخدمات');return;}
const ic='w-full bg-[#252a35]/60 border border-[#424754]/30 rounded-2xl py-3 px-4 text-sm text-[#dfe2f1] focus:ring-2 focus:ring-blue-500/30 placeholder:text-slate-500 font-[Cairo] outline-none';
$('A').innerHTML=`<div class="fade-up"><div class="flex items-center gap-2 mb-6"><span class="mi text-blue-400">add_circle</span><h2 class="font-black text-lg text-white">إضافة خدمة جديدة</h2></div><form id="sf" class="space-y-4"><div><label class="text-xs text-slate-400 font-semibold mb-1 block">القسم *</label><select name="category_id" required id="catSel" class="${ic}"><option value="">-- اختر القسم --</option></select></div><div><label class="text-xs text-slate-400 font-semibold mb-1 block">اسم الخدمة *</label><input name="title" required placeholder="مثال: تصميم شعار احترافي" class="${ic}"></div><div><label class="text-xs text-slate-400 font-semibold mb-1 block">وصف الخدمة</label><textarea name="description" rows="3" placeholder="وصف واضح..." class="${ic} resize-y"></textarea></div><div class="grid grid-cols-2 gap-3"><div><label class="text-xs text-slate-400 font-semibold mb-1 block">السعر ($) *</label><input name="price" type="number" step="any" min="0.01" required placeholder="0.00" class="${ic}"></div><div><label class="text-xs text-slate-400 font-semibold mb-1 block">الكمية *</label><input name="quantity" type="number" min="1" value="1" required class="${ic}"></div></div><div><label class="text-xs text-slate-400 font-semibold mb-1 block">من يتحمل العمولة</label><select name="commission_payer" class="${ic}"><option value="buyer">🛒 المشتري</option><option value="seller">🏪 أنا البائع</option></select></div><div><label class="text-xs text-slate-400 font-semibold mb-1 block">صورة (اختياري)</label><input name="image" type="file" accept="image/*" class="${ic} text-slate-400"></div><button type="submit" id="sb" class="w-full py-4 rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 text-white font-bold shadow-lg shadow-blue-500/25 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi">add</span>إضافة الخدمة</button></form></div>`;
// تعبئة الأقسام
(async()=>{if(!_cats.length){const cd=await api('categories');if(cd.ok)_cats=cd.categories||[];}const sel=$('catSel');if(sel)_cats.forEach(c=>{const o=document.createElement('option');o.value=c.id;o.textContent=c.name;sel.appendChild(o);});})();
$('sf').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.target);fd.append('action','add_service');await _p2pCompressFormData(fd,'image');const b=$('sb');b.disabled=true;b.innerHTML='<span class="mi animate-spin">progress_activity</span>';try{const r=await fetch(U+'/api/p2p.php',{method:'POST',body:fd,credentials:'same-origin'});const t=await r.text();let d;try{d=JSON.parse(t)}catch{alert('خطأ:\n'+t.substring(0,300));b.disabled=false;b.innerHTML='<span class="mi">add</span>إضافة';return;}if(d.ok){alert('✅ '+d.msg);go('mysvcs');}else{alert('❌ '+(d.error||'خطأ'));b.disabled=false;b.innerHTML='<span class="mi">add</span>إضافة';}}catch(er){alert(er.message);b.disabled=false;b.innerHTML='<span class="mi">add</span>إضافة';}}}

// ══ MY SERVICES ══
async function lMS(){if(!VR){$('A').innerHTML=empty('storefront','وثّق حسابك لعرض خدماتك');return;}const c=$('A');c.innerHTML=spin();const d=await api('my_services');
const addBtn='<button onclick="go(\'sell\')" class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 text-white font-bold shadow-lg shadow-blue-500/25 active:scale-[.97] transition-transform flex items-center justify-center gap-2 mb-4"><span class="mi">add_circle</span>إضافة خدمة جديدة</button>';
if(!d.ok||!d.services.length){c.innerHTML=addBtn+empty('storefront','لم تضف أي خدمات بعد');return;}
const sl={active:'نشط',paused:'متوقف',sold_out:'نفد',suspended:'معلق',pending_review:'⏳ بانتظار الموافقة',rejected:'❌ مرفوض'},sc={active:'text-green-400 bg-green-500/10 border-green-500/15',paused:'text-amber-400 bg-amber-500/10 border-amber-500/15',suspended:'text-red-400 bg-red-500/10 border-red-500/15',pending_review:'text-cyan-400 bg-cyan-500/10 border-cyan-500/15',rejected:'text-red-400 bg-red-500/10 border-red-500/15'};
c.innerHTML=addBtn+'<div class="space-y-3">'+d.services.map((s,i)=>`<div class="glass-card rounded-[18px] p-5 fade-up ${['','fd1','fd2','fd3','fd4'][i%5]}">
<div class="flex justify-between items-start mb-2"><h3 class="font-bold text-[15px] text-white">${E(s.title)}</h3><span class="text-xs font-semibold px-2.5 py-1 rounded-full border ${sc[s.status]||'text-slate-400 bg-white/5 border-white/10'}">${sl[s.status]||s.status}</span></div>
${s.status==='rejected'&&s.reject_reason?`<div class="text-xs text-red-400 bg-red-500/5 border border-red-500/10 rounded-lg px-3 py-1.5 mb-2">سبب الرفض: ${E(s.reject_reason)}</div>`:''}
${s.pending_changes?`<div class="text-xs text-cyan-400 bg-cyan-500/5 border border-cyan-500/10 rounded-lg px-3 py-1.5 mb-2">📝 لديك تعديلات بانتظار موافقة الإدارة</div>`:''}
<div class="flex gap-4 text-xs text-slate-400 mb-3"><span>💰 ${F(s.price)}$</span><span>📦 ${s.sold_count}/${s.quantity}</span></div>
<div class="flex gap-2"><button onclick="openEdit(${s.id})" class="flex-1 py-2 rounded-xl bg-blue-500/10 text-blue-400 border border-blue-500/15 text-xs font-bold active:scale-95 transition-transform flex items-center justify-center gap-1"><span class="mi text-sm">edit</span>تعديل</button>
${s.status==='active'?`<button onclick="togSvc(${s.id})" class="py-2 px-4 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/15 text-xs font-bold active:scale-95 transition-transform"><span class="mi text-sm">pause</span></button>`:s.status==='paused'?`<button onclick="togSvc(${s.id})" class="py-2 px-4 rounded-xl bg-green-500/10 text-green-400 border border-green-500/15 text-xs font-bold active:scale-95 transition-transform"><span class="mi text-sm">play_arrow</span></button>`:''}</div></div>`).join('')+'</div>';}

async function togSvc(id){const d=await api('toggle_service',{id},'POST');if(d.ok){alert(d.msg);lMS();}else alert('❌ '+(d.error||'خطأ'));}

async function openEdit(id){
const d=await api('get_service',{id});if(!d.ok){alert(d.error||'خطأ');return;}const s=d.service;
if(!_cats.length){const cd=await api('categories');if(cd.ok)_cats=cd.categories||[];}
const ic='w-full bg-[#252a35]/60 border border-[#424754]/30 rounded-2xl py-3 px-4 text-sm text-[#dfe2f1] focus:ring-2 focus:ring-blue-500/30 placeholder:text-slate-500 font-[Cairo] outline-none';
$('MB').innerHTML=`<div class="w-4 h-1 bg-slate-600 rounded-full mx-auto mb-4"></div>
<div class="flex items-center gap-2 mb-5"><span class="mi text-blue-400">edit</span><h2 class="font-bold text-lg text-white">تعديل الخدمة</h2></div>
<form id="ef" class="space-y-3">
<input type="hidden" name="id" value="${s.id}">
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">القسم</label><select name="category_id" id="eCat" class="${ic}"><option value="">-- اختر --</option></select></div>
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">اسم الخدمة *</label><input name="title" required value="${E(s.title)}" class="${ic}"></div>
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">الوصف</label><textarea name="description" rows="3" class="${ic} resize-y">${E(s.description||'')}</textarea></div>
<div class="grid grid-cols-2 gap-3"><div><label class="text-xs text-slate-400 font-semibold mb-1 block">السعر ($)</label><input name="price" type="number" step="any" min="0.01" value="${s.price}" class="${ic}"></div><div><label class="text-xs text-slate-400 font-semibold mb-1 block">الكمية</label><input name="quantity" type="number" min="1" value="${s.quantity}" class="${ic}"></div></div>
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">من يتحمل العمولة</label><select name="commission_payer" class="${ic}"><option value="buyer" ${s.commission_payer==='buyer'?'selected':''}>🛒 المشتري</option><option value="seller" ${s.commission_payer==='seller'?'selected':''}>🏪 أنا البائع</option></select></div>
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">صورة الخدمة</label>${s.image?`<div class="mb-2"><img src="${U}/${s.image}" class="w-16 h-16 rounded-xl object-cover border border-white/10"></div>`:LOGO?`<div class="mb-2 text-[10px] text-slate-500">الصورة الحالية: شعار النظام</div>`:''}<input name="image" type="file" accept="image/*" class="${ic} text-slate-400"></div>
<button type="submit" id="eBtn" class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 text-white font-bold shadow-lg shadow-blue-500/25 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi">save</span>حفظ التعديلات</button>
<button type="button" onclick="cM()" class="w-full py-2.5 rounded-xl text-slate-400 font-semibold hover:bg-white/5">إلغاء</button>
</form>`;
// fill categories
const sel=$('eCat');if(sel)_cats.forEach(c=>{const o=document.createElement('option');o.value=c.id;o.textContent=c.name;if(c.id==s.category_id)o.selected=true;sel.appendChild(o);});
oM();
$('ef').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.target);fd.append('action','edit_service');await _p2pCompressFormData(fd,'image');const b=$('eBtn');b.disabled=true;b.innerHTML='<span class="mi animate-spin">progress_activity</span>';
try{const r=await fetch(U+'/api/p2p.php',{method:'POST',body:fd,credentials:'same-origin'});const t=await r.text();let d;try{d=JSON.parse(t)}catch{alert('خطأ:\n'+t.substring(0,300));b.disabled=false;b.innerHTML='<span class="mi">save</span>حفظ';return;}
if(d.ok){cM();alert('✅ '+d.msg);lMS();}else{alert('❌ '+(d.error||'خطأ'));b.disabled=false;b.innerHTML='<span class="mi">save</span>حفظ';}}catch(er){alert(er.message);b.disabled=false;b.innerHTML='<span class="mi">save</span>حفظ';}};}

// ══ CHATS LIST ══
let _chatTab='bought';
async function lChats(){
if(!LG){$('A').innerHTML=empty('chat_bubble','سجل دخولك لعرض الدردشات');return;}
const c=$('A');c.innerHTML=spin();
const d=await api('orders');
if(!d.ok||!d.orders.length){c.innerHTML=empty('chat_bubble','لا توجد دردشات');return;}
const withChat=d.orders.filter(o=>o.status==='active'||o.status==='completed');
if(!withChat.length){c.innerHTML=empty('chat_bubble','لا توجد دردشات');return;}
const bought=withChat.filter(o=>o.is_buyer), sold=withChat.filter(o=>!o.is_buyer);
function grp(list){const g={};list.forEach(o=>{const k=o.other_name+'_'+(o.is_buyer?o.seller_id:o.buyer_id);if(!g[k])g[k]={name:o.other_name,avatar:o.other_avatar||'',orders:[],unread:0,hasActive:false,latestOrder:o};g[k].orders.push(o);g[k].unread+=parseInt(o.unread||0);if(o.status==='active')g[k].hasActive=true;if(o.other_avatar&&!g[k].avatar)g[k].avatar=o.other_avatar;if(new Date(o.created_at||0)>new Date(g[k].latestOrder.created_at||0))g[k].latestOrder=o;});return Object.values(g);}
const bList=grp(bought),sList=grp(sold),bCnt=bList.reduce((s,c)=>s+c.unread,0),sCnt=sList.reduce((s,c)=>s+c.unread,0);
function rndr(list){if(!list.length)return'<div class="text-center py-10 text-slate-500 text-sm">لا توجد دردشات هنا</div>';
return'<div class="space-y-2">'+list.map((ch,i)=>{const lo=ch.latestOrder,isAct=ch.hasActive,cnt=ch.orders.length,av=ch.avatar;
const avSrc=av?(av.startsWith('http')?av:U+'/'+av):'';
return`<div onclick="rO(${lo.id})" class="glass-card rounded-[18px] p-4 flex items-center gap-3 cursor-pointer hover:bg-slate-900/80 transition-all active:scale-[.98] fade-up ${['','fd1','fd2','fd3','fd4'][i%5]}">
<div class="w-11 h-11 rounded-xl ${isAct?'bg-blue-500/10':'bg-slate-700/50'} flex items-center justify-center flex-shrink-0 relative overflow-hidden">${avSrc?`<img src="${avSrc}" class="w-full h-full object-cover">`:`<span class="mi text-xl ${isAct?'text-blue-400':'text-slate-400'}">person</span>`}${isAct?'<span class="absolute -bottom-0.5 -left-0.5 w-3 h-3 bg-green-500 rounded-full border-2 border-[#0a0e18]"></span>':''}</div>
<div class="flex-1 min-w-0"><div class="font-bold text-sm text-white">${E(ch.name)}</div><div class="text-[10px] text-slate-500 truncate">${E(lo.svc_title)}${cnt>1?' <span class="text-blue-400">(+'+String(cnt-1)+')</span>':''}</div></div>
${ch.unread>0?`<div class="bg-blue-600 text-white text-[10px] font-bold min-w-[20px] h-5 rounded-full flex items-center justify-center px-1">${ch.unread}</div>`:`<span class="mi text-sm text-slate-600">chevron_left</span>`}
</div>`;}).join('')+'</div>';}
c.innerHTML=`<div class="flex items-center gap-2 mb-4 px-1"><span class="mi text-blue-400">chat_bubble</span><h2 class="font-bold text-base text-white">الدردشات</h2></div>
<div class="flex items-center justify-between mb-4 bg-[#171b26] p-1.5 rounded-2xl">
<button onclick="_chatTab='bought';lChats()" class="flex-1 py-2.5 px-4 rounded-xl text-center font-bold text-sm transition-all duration-300 ${_chatTab==='bought'?'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg':'text-slate-400 hover:bg-white/5'}">🛒 اشتريت منهم ${bCnt>0?'<span class="bg-white/20 text-[9px] px-1.5 py-0.5 rounded-full mr-1">'+bCnt+'</span>':''}</button>
<button onclick="_chatTab='sold';lChats()" class="flex-1 py-2.5 px-4 rounded-xl text-center font-bold text-sm transition-all duration-300 ${_chatTab==='sold'?'bg-gradient-to-r from-purple-600 to-purple-500 text-white shadow-lg':'text-slate-400 hover:bg-white/5'}">🏪 بعت لهم ${sCnt>0?'<span class="bg-white/20 text-[9px] px-1.5 py-0.5 rounded-full mr-1">'+sCnt+'</span>':''}</button>
</div>${_chatTab==='bought'?rndr(bList):rndr(sList)}`;
}

// ══ CHAT MENU (inside order detail) ══
let _chatMenuOrd=null;
function openChatMenu(orderId){_chatMenuOrd=orderId;
$('MB').innerHTML=`<div class="w-4 h-1 bg-slate-600 rounded-full mx-auto mb-6"></div>
<div class="space-y-2">
<button onclick="cM();openReport(${orderId})" class="w-full flex items-center gap-3 p-4 rounded-2xl bg-red-500/5 border border-red-500/10 hover:bg-red-500/10 transition-colors text-right">
<span class="mi text-red-400">flag</span><div class="flex-1"><div class="font-bold text-sm text-red-400">إبلاغ</div><div class="text-[10px] text-slate-500">الإبلاغ عن مشكلة في هذا الطلب</div></div><span class="mi text-sm text-slate-600">chevron_left</span></button>
<button onclick="cM();openTranslate()" class="w-full flex items-center gap-3 p-4 rounded-2xl bg-white/[.03] border border-white/5 hover:bg-white/[.06] transition-colors text-right">
<span class="mi text-blue-400">translate</span><div class="flex-1"><div class="font-bold text-sm text-white">الترجمة</div><div class="text-[10px] text-slate-500">ترجمة الرسائل تلقائياً</div></div><span class="mi text-sm text-slate-600">chevron_left</span></button>
<button onclick="cM();openChatSearch()" class="w-full flex items-center gap-3 p-4 rounded-2xl bg-white/[.03] border border-white/5 hover:bg-white/[.06] transition-colors text-right">
<span class="mi text-purple-400">search</span><div class="flex-1"><div class="font-bold text-sm text-white">بحث في الدردشة</div><div class="text-[10px] text-slate-500">البحث عن رسالة معينة</div></div><span class="mi text-sm text-slate-600">chevron_left</span></button>
<button onclick="cM()" class="w-full py-3 rounded-xl text-slate-400 font-semibold hover:bg-white/5 mt-2">إلغاء</button>
</div>`;oM();}

function openTranslate(){alert('قريباً — ميزة الترجمة التلقائية');}
function openChatSearch(){const q=prompt('ابحث عن:');if(!q)return;const box=$('ms');if(!box)return;const divs=box.querySelectorAll('p');let found=false;divs.forEach(p=>{if(p.textContent.includes(q)){p.parentElement.parentElement.style.background='rgba(59,130,246,.15)';p.parentElement.parentElement.scrollIntoView({behavior:'smooth',block:'center'});found=true;}});if(!found)alert('لم يتم العثور على نتائج');}

// ══ REPORT SYSTEM ══
async function openReport(orderId){
// جلب أسباب الإبلاغ من الإدارة
let reasons=[];
try{const d=await api('report_reasons');if(d.ok)reasons=d.reasons||[];}catch(e){}
if(!reasons.length)reasons=[{id:1,name:'احتيال',freezes:1},{id:2,name:'عدم تسليم الخدمة',freezes:1},{id:3,name:'سلوك غير لائق',freezes:0},{id:4,name:'أخرى',freezes:0}];

const ic='w-full bg-[#252a35]/60 border border-[#424754]/30 rounded-2xl py-3 px-4 text-sm text-[#dfe2f1] focus:ring-2 focus:ring-blue-500/30 placeholder:text-slate-500 font-[Cairo] outline-none';
$('MB').innerHTML=`<div class="w-4 h-1 bg-slate-600 rounded-full mx-auto mb-4"></div>
<div class="flex items-center gap-2 mb-4"><span class="mi text-red-400">flag</span><h2 class="font-bold text-lg text-white">إبلاغ</h2></div>
<div class="bg-amber-500/10 border border-amber-500/20 rounded-2xl p-4 mb-5 text-center">
<span class="mi text-amber-400 text-2xl block mb-2">warning</span>
<p class="text-xs text-amber-300 font-bold leading-relaxed">ستؤدي الإبلاغات الخبيثة إلى تجميد الحساب.</p>
</div>
<form id="rpForm" class="space-y-3" enctype="multipart/form-data">
<input type="hidden" name="order_id" value="${orderId}">
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">رقم الطلب</label><input value="#${orderId}" disabled class="${ic} opacity-60"></div>
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">سبب الإبلاغ *</label><select name="reason_id" required class="${ic}""><option value="">-- اختر السبب --</option>${reasons.map(r=>`<option value="${r.id}">${E(r.name)}</option>`).join('')}</select></div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">البريد الإلكتروني</label><input name="email" type="email" placeholder="email@example.com" class="${ic}"></div>
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">رقم الهاتف</label><input name="phone" type="tel" placeholder="+966..." class="${ic}"></div>
</div>
<div><label class="text-xs text-slate-400 font-semibold mb-1 block">التفاصيل</label><textarea name="details" rows="4" placeholder="يرجى تقديم أكبر قدر ممكن من التفاصيل" class="${ic} resize-y" required></textarea></div>
<div>
<label class="text-xs text-slate-400 font-semibold mb-1 block">تحميل الدليل</label>
<div class="text-[10px] text-slate-500 mb-2">ما يصل إلى 5 ملفات و 50 ميجابايت إجمالًا. يُسمح بالملفات ذات التنسيق jpg, jpeg, png, mp3, mp4, avi, mov, wmv فقط.</div>
<input name="evidence[]" type="file" multiple accept=".jpg,.jpeg,.png,.mp3,.mp4,.avi,.rm,.rmvb,.mov,.wmv" class="${ic} text-slate-400">
</div>
<button type="submit" id="rpBtn" class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-red-600 to-red-500 text-white font-bold shadow-lg shadow-red-500/20 active:scale-[.97] transition-transform flex items-center justify-center gap-2"><span class="mi">send</span>إرسال البلاغ</button>
<button type="button" onclick="openMyReports()" class="w-full py-2.5 rounded-xl bg-white/[.03] border border-white/5 text-slate-400 font-semibold text-sm flex items-center justify-center gap-2"><span class="mi text-sm">history</span>بلاغاتي</button>
</form>`;
oM();
$('rpForm').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.target);fd.append('action','submit_report');const b=$('rpBtn');b.disabled=true;b.innerHTML='<span class="mi animate-spin">progress_activity</span>';
try{const r=await fetch(U+'/api/p2p.php',{method:'POST',body:fd,credentials:'same-origin'});const t=await r.text();let d;try{d=JSON.parse(t)}catch{alert('خطأ:\n'+t.substring(0,200));b.disabled=false;b.innerHTML='<span class="mi">send</span>إرسال';return;}
if(d.ok){cM();alert('✅ '+d.msg);}else{alert('❌ '+(d.error||'خطأ'));b.disabled=false;b.innerHTML='<span class="mi">send</span>إرسال';}}catch(er){alert(er.message);b.disabled=false;b.innerHTML='<span class="mi">send</span>إرسال';}};
}

async function openMyReports(){
const d=await api('my_reports');
if(!d.ok||!d.reports.length){alert('لا توجد بلاغات سابقة');return;}
const stl={pending:'⏳ معلق',reviewing:'🔍 قيد المراجعة',resolved:'✅ تم الحل',rejected:'❌ مرفوض'};
$('MB').innerHTML=`<div class="w-4 h-1 bg-slate-600 rounded-full mx-auto mb-4"></div>
<div class="flex items-center gap-2 mb-4"><span class="mi text-amber-400">history</span><h2 class="font-bold text-lg text-white">بلاغاتي</h2></div>
<div class="space-y-3">${d.reports.map(r=>`<div class="glass-card rounded-2xl p-4">
<div class="flex justify-between items-start mb-2"><span class="text-sm font-bold text-white">طلب #${r.order_id}</span><span class="text-[10px] font-bold ${r.status==='resolved'?'text-green-400':r.status==='rejected'?'text-red-400':'text-amber-400'}">${stl[r.status]||r.status}</span></div>
<div class="text-xs text-slate-400 mb-1">${E(r.reason_name||'')}</div>
<div class="text-xs text-slate-500">${E((r.details||'').substring(0,100))}</div>
${r.admin_reply?`<div class="mt-2 p-2 rounded-lg bg-blue-500/5 border border-blue-500/10 text-xs text-blue-300"><strong>رد الإدارة:</strong> ${E(r.admin_reply)}</div>`:''}
</div>`).join('')}</div>
<button onclick="cM()" class="w-full py-3 rounded-xl text-slate-400 font-semibold hover:bg-white/5 mt-3">إغلاق</button>`;
oM();}

if(LG){setInterval(()=>api('heartbeat',{},'POST'),60000);api('auto_complete',{},'POST');}
// فتح ملف شخصي من الرابط
const urlP=new URLSearchParams(window.location.search);
if(urlP.get('profile')){openProfile(parseInt(urlP.get('profile')));}
else{go('market');}
</script></body></html>
