<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <!-- ══ CATEGORY PAGE (dynamic) ══ -->
    <div class="page" id="page-cat">
      <div class="page-header-img" id="catHeaderImg" style="background:linear-gradient(135deg,#1a237e,#0d47a1)">
        <div class="page-header-img-bg" id="catHeaderIcon">🎮</div>
        <div class="page-header-img-overlay"></div>
        <div class="page-header-img-content">
          <button class="phic-back" id="catBackBtn" onclick="catGoBack()"><i class="fas fa-arrow-right"></i></button>
          <div class="phic-title" id="catHeaderTitle">الألعاب</div>
          <div class="phic-sub" id="catHeaderSub">تصفح الخدمات</div>
        </div>
      </div>
      <!-- Breadcrumb التنقل المتسلسل -->
      <div class="cat-nav-crumbs" id="catNavCrumbs" style="display:none">
        <div class="cat-crumbs-scroll" id="catCrumbsScroll"></div>
      </div>
      <div class="search-bar">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="بحث..." id="catSearch" oninput="filterItems()">
      </div>
      <div class="items-grid" id="catItemsGrid">
        <!-- Filled by JS -->
      </div>
      <div style="height:20px"></div>
    </div>

    <!-- ══ PACKAGES PAGE (dynamic) ══ -->
