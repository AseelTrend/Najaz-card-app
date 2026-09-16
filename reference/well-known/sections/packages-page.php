<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <div class="page" id="page-packages">
      <div id="pkgHeaderBg" style="position:relative;text-align:center;padding:20px;background:linear-gradient(180deg,#0d1f5c 0%,var(--bg) 100%);transition:background .3s">
        <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,0.2) 0%,rgba(8,12,26,0.95) 100%);border-radius:0"></div>
        <button class="phic-back" onclick="backFromPackages()" style="position:absolute;top:12px;right:12px;background:rgba(255,255,255,0.1);border-radius:50%;width:36px;height:36px;border:none;color:#fff;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2"><i class="fas fa-arrow-right"></i></button>
        <div style="position:relative;z-index:1">
          <div style="font-size:60px;margin-bottom:8px" id="pkgHeaderIcon">🎯</div>
          <div style="font-size:20px;font-weight:900" id="pkgHeaderName">بوبجي</div>
          <div style="font-size:12px;color:var(--text2);margin-top:4px" id="pkgHeaderSub">اختر الباقة المناسبة</div>
        </div>
      </div>
      <div class="action-tabs">
        <button class="action-tab-btn active"><i class="fas fa-sync-alt"></i> الباقات</button>
        <button class="action-tab-btn" onclick="showToast('معلومات الخدمة')"><i class="fas fa-info-circle"></i> معلومات</button>
      </div>
      <div class="packages-list" id="pkgList">
        <!-- Filled by JS -->
      </div>
      <div style="height:20px"></div>
    </div>

