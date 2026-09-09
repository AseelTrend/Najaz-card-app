<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <!-- ══ HOME ══ -->
    <div class="page active" id="page-home">

      <!-- ══ Hero Slider ══ -->
      <div class="slider-wrap" id="heroSlider">
        <div class="slider-track" id="sliderTrack">
          <?php foreach ($banners as $i => $b):
            $linkJs = '';
            if ($b['link_type'] === 'category')  $linkJs = "sliderNav('cat'," . (int)$b['link_value'] . ")";
            elseif ($b['link_type'] === 'service') $linkJs = "sliderNav('svc'," . (int)$b['link_value'] . ")";
            elseif ($b['link_type'] === 'url')     $linkJs = "window.open('" . addslashes($b['link_value']) . "','_blank')";
          ?>
          <?php
            // هل نعرض النص؟ نعرضه فقط إذا كان هناك عنوان أو تاج أو subtitle
            // وإذا كانت الصورة موجودة والعنوان فارغاً — نخفي النص كلياً
            $hasText  = !empty($b['title']) || !empty($b['subtitle']) || !empty($b['tag']);
            $imgOnly  = !empty($b['image']) && !$hasText;
          ?>
          <div class="slide <?= $hasText ? 'has-text' : '' ?>" <?= $linkJs ? 'onclick="' . htmlspecialchars($linkJs) . '"' : '' ?>>
            <div class="slide-bg" style="background:<?= htmlspecialchars($b['bg_color']) ?>"></div>
            <?php if ($b['image']): ?>
            <img class="slide-img" src="<?= SITE_URL.'/'.$b['image'] ?>" alt=""
                 style="<?= $imgOnly ? 'object-fit:cover;width:100%;height:100%' : '' ?>">
            <?php endif; ?>
            <?php if ($hasText): ?>
            <div class="slide-content">
              <div>
                <?php if ($b['tag']): ?>
                <div class="slide-tag" style="color:<?= htmlspecialchars($b['accent_color']) ?>;border-color:<?= htmlspecialchars($b['accent_color']) ?>55;background:<?= htmlspecialchars($b['accent_color']) ?>22">
                  <?= htmlspecialchars($b['tag']) ?>
                </div>
                <?php endif; ?>
                <?php if ($b['title']): ?>
                <div class="slide-title" style="color:<?= htmlspecialchars($b['text_color']) ?>;margin-top:6px">
                  <?= nl2br(htmlspecialchars($b['title'])) ?>
                </div>
                <?php endif; ?>
              </div>
              <?php if ($b['subtitle']): ?>
              <div class="slide-sub" style="color:<?= htmlspecialchars($b['text_color']) ?>">
                <?= htmlspecialchars($b['subtitle']) ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- dots -->
        <div class="slider-dots" id="sliderDots">
          <?php foreach ($banners as $i => $b): ?>
          <button class="slider-dot <?= $i===0?'active':'' ?>" onclick="goToSlide(<?= $i ?>)"></button>
          <?php endforeach; ?>
        </div>

        <?php if (count($banners) > 1): ?>
        <button class="slider-arrow prev" onclick="sliderStep(-1)">&#8250;</button>
        <button class="slider-arrow next" onclick="sliderStep(1)">&#8249;</button>
        <?php endif; ?>
      </div>

      <!-- Main Categories from DB -->
      <div class="sec-header">
        <div class="sec-title">الخدمات</div>
      </div>
      <div class="cat-grid">
        <?php foreach ($mainCats as $mc):
          $style = getCatStyle($mc['name']);
          $serviceCount = 0;
          foreach ($allServices as $sv) {
            if ($sv['parent_id'] == $mc['id'] || $sv['cat_id'] == $mc['id']) $serviceCount++;
          }
        ?>
        <?php
          $mcType   = $mc['category_type'] ?? 'default';
          $mcOnclick = $mcType === 'telecom'
            ? "location.href='".SITE_URL."/telecom.php'"
            : "openCat({$mc['id']}, '".addslashes(htmlspecialchars($mc['name']))."')";
        ?>
        <?php $mcUnavail = ($mc['status'] ?? 1) == 0; ?>
        <?php $mcOnclickFinal = $mcUnavail ? "showToast('القسم غير متاح في الوقت الحالي','warning')" : htmlspecialchars($mcOnclick); ?>
        <div class="cat-card<?= $mcUnavail ? ' unavail' : '' ?>" onclick="<?= $mcOnclickFinal ?>">
          <div class="cat-card-imgwrap" style="background:<?= $style['grad'] ?>">
            <?php if (!empty($mc['image'])): ?>
            <img src="<?= SITE_URL ?>/<?= htmlspecialchars($mc['image']) ?>" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:<?= $mcUnavail ? '0.45' : '0.85' ?>">
            <?php else: ?>
            <div class="cat-card-bg"><?= $style['icon'] ?></div>
            <?php endif; ?>
            <?php if ($mcUnavail): ?>
            <div class="cat-unavail-strip"><i class="fas fa-ban" style="font-size:8px"></i> غير متاح</div>
            <?php endif; ?>
          </div>
          <div class="cat-card-namebox">
            <div class="cat-card-name"><?= htmlspecialchars($mc['name']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Quick Actions -->
      <div class="sec-header"><div class="sec-title">إجراءات سريعة</div></div>
      <div class="quick-actions" style="grid-template-columns:repeat(4,1fr)">
        <div class="qa-item" onclick="navTo('orders')">
          <div class="qa-icon" style="background:rgba(30,111,255,0.15)"><i class="fas fa-list-alt" style="color:var(--primary)"></i></div>
          <div class="qa-label">طلباتي</div>
        </div>
        <div class="qa-item" onclick="navTo('wallet')">
          <div class="qa-icon" style="background:rgba(0,230,118,0.15)"><i class="fas fa-wallet" style="color:var(--green)"></i></div>
          <div class="qa-label">محفظتي</div>
        </div>
        <div class="qa-item" onclick="window.location.href='<?= SITE_URL ?>/p2p.php'">
          <div class="qa-icon" style="background:rgba(139,92,246,0.15)"><i class="fas fa-handshake" style="color:#8b5cf6"></i></div>
          <div class="qa-label">سوق P2P</div>
        </div>
        <div class="qa-item" onclick="navTo('profile')">
          <div class="qa-icon" style="background:rgba(0,212,255,0.15)"><i class="fas fa-user" style="color:var(--cyan)"></i></div>
          <div class="qa-label">حسابي</div>
        </div>
      </div>
      <div style="height:20px"></div>
    </div>

