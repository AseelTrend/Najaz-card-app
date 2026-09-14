</div><!-- end main-content -->

<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <h4><i class="fas fa-rocket"></i> <?= getSetting('site_name') ?: SITE_NAME ?></h4>
                <p>منصة متكاملة لشحن الألعاب والتطبيقات والبطاقات الرقمية</p>
            </div>
            <div>
                <h4>روابط سريعة</h4>
                <ul>
                    <li><a href="<?= SITE_URL ?>">الرئيسية</a></li>
                    <li><a href="<?= SITE_URL ?>/services.php">الخدمات</a></li>
                    <?php if (isLoggedIn()): ?>
                    <li><a href="<?= SITE_URL ?>/orders.php">طلباتي</a></li>
                    <li><a href="<?= SITE_URL ?>/wallet.php">محفظتي</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div>
                <h4>أقسام الخدمات</h4>
                <ul>
                    <li><i class="fas fa-gamepad"></i> شحن الألعاب</li>
                    <li><i class="fas fa-mobile-alt"></i> شحن التطبيقات</li>
                    <li><i class="fas fa-credit-card"></i> البطاقات الرقمية</li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© <?= date('Y') ?> <?= getSetting('site_name') ?: SITE_NAME ?> - جميع الحقوق محفوظة | نسخة تجريبية</p>
        </div>
    </div>
</footer>

<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
