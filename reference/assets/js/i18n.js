// ══════════════════════════════════════════════════════════════════════
// نظام الترجمة الشامل — i18n v2
// يعمل بدون data-i18n: يستبدل كل النصوص في الصفحة دفعة واحدة
// ══════════════════════════════════════════════════════════════════════

const LANG_DIR = { ar: 'rtl', en: 'ltr', tr: 'ltr' };

// ── قاموس الترجمة الكامل ─────────────────────────────────────────────
const T = {

  ar: {}, // العربية هي اللغة الأصلية — لا نغير شيئاً

  en: {
    // ── Nav ───────────────────────────────────────────────────────────
    'الرئيسية':            'Home',
    'محفظتي':              'Wallet',
    'طلباتي':              'Orders',
    'حسابي':               'Account',
    'الإشعارات':           'Notifications',
    // ── Sidebar ───────────────────────────────────────────────────────
    'القائمة':             'Menu',
    'شحن الرصيد':          'Top Up',
    'الإعدادات':           'Settings',
    'تحقق الهوية':         'Verify Identity',
    'الإدارة':             'Admin',
    'لوحة التحكم':         'Dashboard',
    'المطورين':            'Developers',
    'التوثيق':             'Docs',
    'معلومات':             'Info',
    'من نحن':              'About Us',
    'سياسة الخصوصية':      'Privacy Policy',
    'الأمان':              'Security',
    'احمِ حسابك':          'Secure Account',
    'تسجيل الخروج':        'Sign Out',
    'دخول / تسجيل':        'Sign In / Register',
    'زائر':                'Guest',
    // ── Balance bar ───────────────────────────────────────────────────
    'الرصيد الإجمالي':     'Total Balance',
    // ── Home quick actions ────────────────────────────────────────────
    'الخدمات':             'Services',
    'إجراءات سريعة':       'Quick Actions',
    'تصفح الخدمات':        'Browse Services',
    'الباقات':             'Packages',
    'بحث...':              'Search...',
    // ── Orders page ───────────────────────────────────────────────────
    'طلب':                 'order',
    'الكل':                'All',
    'انتظار':              'Pending',
    'تنفيذ':               'Processing',
    'مكتمل':               'Completed',
    'ملغي':                'Cancelled',
    'فشل':                 'Failed',
    'لا توجد طلبات بعد':   'No orders yet',
    'طلب جديد':            'New Order',
    // ── Status labels ─────────────────────────────────────────────────
    'قيد الانتظار':        'Pending',
    'قيد التنفيذ':         'Processing',
    'مكتمل ✓':             'Completed ✓',
    'في انتظار التنفيذ':   'Awaiting execution',
    'جاري تنفيذ الطلب':    'Processing order',
    'تم تنفيذ الطلب بنجاح':'Order completed',
    'تم إلغاء الطلب':      'Order cancelled',
    'رُفض الطلب من المزود':'Rejected by provider',
    'فشل تنفيذ الطلب':     'Order failed',
    // ── Order detail ──────────────────────────────────────────────────
    'رقم الطلب':           'Order #',
    'التاريخ':             'Date',
    'رقم المزود':          'Provider ID',
    'بيانات الطلب':        'Order Data',
    'سجل التطورات':        'Status History',
    'المجموع':             'Total',
    'سعر الوحدة':          'Unit Price',
    'الكمية':              'Quantity',
    'السعر':               'Price',
    // ── Wallet ────────────────────────────────────────────────────────
    'رصيدي الحالي':        'My Balance',
    'السجل الكامل':        'Full History',
    'آخر العمليات':        'Recent Transactions',
    'لا توجد معاملات':     'No transactions',
    // ── Profile ───────────────────────────────────────────────────────
    'رقم الحساب':          'Account ID',
    'الاسم الكامل':        'Full Name',
    'البريد الإلكتروني':   'Email',
    'رقم الهاتف':          'Phone',
    'موثّق':               'Verified',
    'حساب نشط':            'Active Account',
    'تعديل الاسم':         'Edit Name',
    'تغيير الصورة':        'Change Photo',
    'أدخل اسمك الكامل':    'Enter your full name',
    'حفظ':                 'Save',
    'إلغاء':               'Cancel',
    // ── Settings ──────────────────────────────────────────────────────
    'المنطقة الزمنية':     'Timezone',
    'اختر منطقتك الزمنية لعرض الأوقات بشكل صحيح': 'Select your timezone to display times correctly',
    'الوقت الحالي':        'Current time',
    'المظهر':              'Theme',
    'داكن':                'Dark',
    'فاتح':                'Light',
    'اللغة':               'Language',
    'حُفظ':                'Saved',
    // ── Auth ──────────────────────────────────────────────────────────
    'مرحباً بك':           'Welcome',
    'سجل دخولك لبدء الشحن':'Sign in to start',
    'تسجيل الدخول':        'Sign In',
    'إنشاء حساب':          'Register',
    'اسم المستخدم أو البريد':'Username or Email',
    'كلمة المرور':          'Password',
    'أو':                  'or',
    'ليس لديك حساب؟':     "Don't have an account?",
    'إنشاء حساب جديد':    'Create new account',
    'لديك حساب؟':          'Have an account?',
    'تم بنجاح!':           'Success!',
    'جاري تحديث الصفحة...':'Refreshing...',
    'هل تريد تسجيل الخروج؟':'Sign out?',
    'سجل دخولك لعرض طلباتك':'Sign in to view your orders',
    'سجل دخولك لعرض محفظتك':'Sign in to view your wallet',
    'سجل دخولك لرؤية الإشعارات':'Sign in to see notifications',
    'سجل دخولك للوصول لحسابك': 'Sign in to access your account',
    'أنشئ حساباً مجانياً الآن': 'Create a free account now',
    'انقر للدخول أو إنشاء حساب جديد': 'Tap to sign in or register',
    'ستصلك إشعارات طلباتك ومحفظتك': 'You\'ll receive order & wallet notifications',
    // ── Topup ─────────────────────────────────────────────────────────
    'اختر طريقة الدفع':    'Select Payment Method',
    'يدوي':                'Manual',
    'مباشر':               'Instant',
    'تفاصيل الإيداع':      'Deposit Details',
    'اتبع التعليمات أدناه':'Follow instructions below',
    'بيانات الحساب':       'Account Details',
    'المبلغ وحساب الرصيد': 'Amount & Balance',
    'سيُضاف لرصيدك':       'Will be added to balance',
    'إيصال الدفع':         'Payment Receipt',
    'اضغط لرفع الإيصال':   'Tap to upload receipt',
    'صورة أو PDF — حد أقصى 5MB': 'Image or PDF — max 5MB',
    'ملاحظة اختيارية (اسم المُرسِل...)': 'Optional note (sender name...)',
    'إرسال طلب الشحن':     'Submit Topup Request',
    'لا توجد طرق دفع يدوية':'No manual payment methods',
    'لا توجد طرق دفع مباشرة':'No instant payment methods',
    // ── Buy sheet ─────────────────────────────────────────────────────
    'إجمالي السعر':        'Total Price',
    'رصيدك':               'Your Balance',
    'رصيد غير كافٍ':       'Insufficient Balance',
    'شـراء الآن':          'Buy Now',
    'جاري التنفيذ...':     'Processing...',
    'تم الطلب بنجاح! 🎉':  'Order placed! 🎉',
    'متابعة التسوق':       'Continue Shopping',
    'متابعة طلباتي':       'View My Orders',
    // ── Notifications ─────────────────────────────────────────────────
    'إشعاراتي':            'My Notifications',
    'مسح الكل':            'Clear All',
    'حذف جميع الإشعارات؟': 'Delete all notifications?',
    'لا توجد إشعارات بعد': 'No notifications yet',
    'ستظهر هنا إشعارات طلباتك وحسابك': 'Order & account notifications appear here',
    'تعليم الكل كمقروء':   'Mark all as read',
    // ── KYC ───────────────────────────────────────────────────────────
    'KYC — تحقق الهوية':   'KYC — Identity Verification',
    'نوع الهوية':          'ID Type',
    'بطاقة شخصية':         'National ID',
    'جواز سفر':            'Passport',
    'بطاقة عائلية':        'Family Card',
    'بطاقة إلكترونية':     'Electronic Card',
    'الاسم':               'Name',
    'تاريخ الميلاد':       'Birth Date',
    'مكان الميلاد':        'Birth Place',
    'تاريخ الإصدار':       'Issue Date',
    'تاريخ الانتهاء':      'Expiry Date',
    'السابق':              'Previous',
    'التالي':              'Next',
    'إرسال الطلب':         'Submit Request',
    'جاري الإرسال...':     'Submitting...',
    'بياناتك محمية ومشفرة ولن تُشارك مع أي طرف ثالث': 'Your data is encrypted and will not be shared',
    // ── Install prompt ────────────────────────────────────────────────
    'ثبّت التطبيق على جهازك!': 'Install the app!',
    'وصول أسرع بدون متصفح — مجاناً تماماً': 'Faster access without browser — completely free',
    'تثبيت الآن':          'Install Now',
    'لاحقاً':              'Later',
    // ── Common ────────────────────────────────────────────────────────
    'حسناً':               'OK',
    'إغلاق':               'Close',
    'رجوع':                'Back',
    'نسخ':                 'Copy',
    'تم':                  'Done',
    'خطأ في الاتصال':      'Connection error',
    'خطأ في الاتصال، حاول مجدداً': 'Connection error, try again',
  },

  tr: {
    // ── Nav ───────────────────────────────────────────────────────────
    'الرئيسية':            'Ana Sayfa',
    'محفظتي':              'Cüzdan',
    'طلباتي':              'Siparişler',
    'حسابي':               'Hesabım',
    'الإشعارات':           'Bildirimler',
    // ── Sidebar ───────────────────────────────────────────────────────
    'القائمة':             'Menü',
    'شحن الرصيد':          'Bakiye Yükle',
    'الإعدادات':           'Ayarlar',
    'تحقق الهوية':         'Kimlik Doğrula',
    'الإدارة':             'Yönetim',
    'لوحة التحكم':         'Kontrol Paneli',
    'المطورين':            'Geliştiriciler',
    'التوثيق':             'Belgeler',
    'معلومات':             'Bilgi',
    'من نحن':              'Hakkımızda',
    'سياسة الخصوصية':      'Gizlilik Politikası',
    'الأمان':              'Güvenlik',
    'احمِ حسابك':          'Hesabı Koru',
    'تسجيل الخروج':        'Çıkış Yap',
    'دخول / تسجيل':        'Giriş / Kayıt',
    'زائر':                'Misafir',
    // ── Balance ───────────────────────────────────────────────────────
    'الرصيد الإجمالي':     'Toplam Bakiye',
    // ── Home ──────────────────────────────────────────────────────────
    'الخدمات':             'Hizmetler',
    'إجراءات سريعة':       'Hızlı İşlemler',
    'تصفح الخدمات':        'Hizmetlere Göz At',
    'الباقات':             'Paketler',
    'بحث...':              'Ara...',
    // ── Orders ────────────────────────────────────────────────────────
    'طلب':                 'sipariş',
    'الكل':                'Tümü',
    'انتظار':              'Bekliyor',
    'تنفيذ':               'İşleniyor',
    'مكتمل':               'Tamamlandı',
    'ملغي':                'İptal',
    'فشل':                 'Başarısız',
    'لا توجد طلبات بعد':   'Henüz sipariş yok',
    'طلب جديد':            'Yeni Sipariş',
    // ── Status ────────────────────────────────────────────────────────
    'قيد الانتظار':        'Beklemede',
    'قيد التنفيذ':         'İşleniyor',
    'مكتمل ✓':             'Tamamlandı ✓',
    'في انتظار التنفيذ':   'Bekliyor',
    'جاري تنفيذ الطلب':    'Sipariş işleniyor',
    'تم تنفيذ الطلب بنجاح':'Sipariş tamamlandı',
    'تم إلغاء الطلب':      'Sipariş iptal edildi',
    'رُفض الطلب من المزود':'Sağlayıcı tarafından reddedildi',
    'فشل تنفيذ الطلب':     'Sipariş başarısız',
    // ── Order detail ──────────────────────────────────────────────────
    'رقم الطلب':           'Sipariş #',
    'التاريخ':             'Tarih',
    'رقم المزود':          'Sağlayıcı ID',
    'بيانات الطلب':        'Sipariş Verileri',
    'سجل التطورات':        'Durum Geçmişi',
    'المجموع':             'Toplam',
    'سعر الوحدة':          'Birim Fiyat',
    'الكمية':              'Miktar',
    'السعر':               'Fiyat',
    // ── Wallet ────────────────────────────────────────────────────────
    'رصيدي الحالي':        'Bakiyem',
    'السجل الكامل':        'Tüm Geçmiş',
    'آخر العمليات':        'Son İşlemler',
    'لا توجد معاملات':     'İşlem yok',
    // ── Profile ───────────────────────────────────────────────────────
    'رقم الحساب':          'Hesap ID',
    'الاسم الكامل':        'Tam Ad',
    'البريد الإلكتروني':   'E-posta',
    'رقم الهاتف':          'Telefon',
    'موثّق':               'Doğrulandı',
    'حساب نشط':            'Aktif Hesap',
    'تعديل الاسم':         'Adı Düzenle',
    'تغيير الصورة':        'Fotoğraf Değiştir',
    'أدخل اسمك الكامل':    'Tam adınızı girin',
    'حفظ':                 'Kaydet',
    'إلغاء':               'İptal',
    // ── Settings ──────────────────────────────────────────────────────
    'المنطقة الزمنية':     'Saat Dilimi',
    'اختر منطقتك الزمنية لعرض الأوقات بشكل صحيح': 'Zamanları doğru görmek için saat diliminizi seçin',
    'الوقت الحالي':        'Şu anki saat',
    'المظهر':              'Tema',
    'داكن':                'Koyu',
    'فاتح':                'Açık',
    'اللغة':               'Dil',
    'حُفظ':                'Kaydedildi',
    // ── Auth ──────────────────────────────────────────────────────────
    'مرحباً بك':           'Hoş Geldiniz',
    'سجل دخولك لبدء الشحن':'Başlamak için giriş yapın',
    'تسجيل الدخول':        'Giriş Yap',
    'إنشاء حساب':          'Kayıt Ol',
    'اسم المستخدم أو البريد':'Kullanıcı adı veya e-posta',
    'كلمة المرور':          'Şifre',
    'أو':                  'veya',
    'ليس لديك حساب؟':     'Hesabınız yok mu?',
    'إنشاء حساب جديد':    'Yeni hesap oluştur',
    'لديك حساب؟':          'Hesabınız var mı?',
    'تم بنجاح!':           'Başarılı!',
    'جاري تحديث الصفحة...':'Güncelleniyor...',
    'هل تريد تسجيل الخروج؟':'Çıkış yapmak istiyor musunuz?',
    'سجل دخولك لعرض طلباتك':'Siparişlerinizi görmek için giriş yapın',
    'سجل دخولك لعرض محفظتك':'Cüzdanınızı görmek için giriş yapın',
    'سجل دخولك لرؤية الإشعارات':'Bildirimleri görmek için giriş yapın',
    'سجل دخولك للوصول لحسابك': 'Hesabınıza erişmek için giriş yapın',
    'أنشئ حساباً مجانياً الآن': 'Şimdi ücretsiz hesap oluşturun',
    'انقر للدخول أو إنشاء حساب جديد': 'Giriş veya kayıt için tıklayın',
    'ستصلك إشعارات طلباتك ومحفظتك': 'Sipariş ve cüzdan bildirimleriniz gelecek',
    // ── Topup ─────────────────────────────────────────────────────────
    'اختر طريقة الدفع':    'Ödeme Yöntemi Seçin',
    'يدوي':                'Manuel',
    'مباشر':               'Anında',
    'تفاصيل الإيداع':      'Yatırma Detayları',
    'اتبع التعليمات أدناه':'Aşağıdaki talimatları izleyin',
    'بيانات الحساب':       'Hesap Bilgileri',
    'المبلغ وحساب الرصيد': 'Tutar ve Bakiye',
    'سيُضاف لرصيدك':       'Bakiyenize eklenecek',
    'إيصال الدفع':         'Ödeme Makbuzu',
    'اضغط لرفع الإيصال':   'Makbuz yüklemek için tıklayın',
    'صورة أو PDF — حد أقصى 5MB': 'Görsel veya PDF — maks 5MB',
    'ملاحظة اختيارية (اسم المُرسِل...)': 'İsteğe bağlı not (gönderen adı...)',
    'إرسال طلب الشحن':     'Yükleme Talebi Gönder',
    'لا توجد طرق دفع يدوية':'Manuel ödeme yöntemi yok',
    'لا توجد طرق دفع مباشرة':'Anında ödeme yöntemi yok',
    // ── Buy ───────────────────────────────────────────────────────────
    'إجمالي السعر':        'Toplam Fiyat',
    'رصيدك':               'Bakiyeniz',
    'رصيد غير كافٍ':       'Yetersiz Bakiye',
    'شـراء الآن':          'Şimdi Al',
    'جاري التنفيذ...':     'İşleniyor...',
    'تم الطلب بنجاح! 🎉':  'Sipariş verildi! 🎉',
    'متابعة التسوق':       'Alışverişe Devam',
    'متابعة طلباتي':       'Siparişlerimi Görüntüle',
    // ── Notifications ─────────────────────────────────────────────────
    'إشعاراتي':            'Bildirimlerim',
    'مسح الكل':            'Tümünü Temizle',
    'حذف جميع الإشعارات؟': 'Tüm bildirimler silinsin mi?',
    'لا توجد إشعارات بعد': 'Henüz bildirim yok',
    'ستظهر هنا إشعارات طلباتك وحسابك': 'Sipariş ve hesap bildirimleri burada görünecek',
    'تعليم الكل كمقروء':   'Tümünü okundu işaretle',
    // ── KYC ───────────────────────────────────────────────────────────
    'KYC — تحقق الهوية':   'KYC — Kimlik Doğrulama',
    'نوع الهوية':          'Kimlik Türü',
    'بطاقة شخصية':         'Kimlik Kartı',
    'جواز سفر':            'Pasaport',
    'بطاقة عائلية':        'Aile Cüzdanı',
    'بطاقة إلكترونية':     'Elektronik Kart',
    'الاسم':               'Ad',
    'تاريخ الميلاد':       'Doğum Tarihi',
    'مكان الميلاد':        'Doğum Yeri',
    'تاريخ الإصدار':       'Veriliş Tarihi',
    'تاريخ الانتهاء':      'Geçerlilik Tarihi',
    'السابق':              'Önceki',
    'التالي':              'Sonraki',
    'إرسال الطلب':         'Talebi Gönder',
    'جاري الإرسال...':     'Gönderiliyor...',
    'بياناتك محمية ومشفرة ولن تُشارك مع أي طرف ثالث': 'Verileriniz şifrelenmiş ve üçüncü taraflarla paylaşılmaz',
    // ── Install ───────────────────────────────────────────────────────
    'ثبّت التطبيق على جهازك!': 'Uygulamayı yükle!',
    'وصول أسرع بدون متصفح — مجاناً تماماً': 'Tarayıcı olmadan daha hızlı erişim — tamamen ücretsiz',
    'تثبيت الآن':          'Şimdi Yükle',
    'لاحقاً':              'Sonra',
    // ── Common ────────────────────────────────────────────────────────
    'حسناً':               'Tamam',
    'إغلاق':               'Kapat',
    'رجوع':                'Geri',
    'نسخ':                 'Kopyala',
    'تم':                  'Tamam',
    'خطأ في الاتصال':      'Bağlantı hatası',
    'خطأ في الاتصال، حاول مجدداً': 'Bağlantı hatası, tekrar deneyin',
  }
};

// ── المتغيرات العامة ─────────────────────────────────────────────────
let currentLang = 'ar';
let languageCatalogLoaded = false;
let contextTranslations = {};
const databaseLanguageLoaded = {};
let textTranslationScanScheduled = false;
const originalTextNodes = new WeakMap();

// ترجمة العناصر الديناميكية المرتبطة بمعرّفات نجاز الثابتة.
// لا تُستخدم الترجمة كقيمة للطلب؛ فهي تغيّر النص الظاهر فقط.
function tEntity(context, fallback) {
  const source = String(fallback ?? '');
  if (currentLang === 'ar') return source;
  return contextTranslations[currentLang]?.[String(context)] ?? source;
}

function tCategory(id, fallback) {
  return tEntity('entity:category:' + String(id) + ':name', fallback);
}

function tService(id, fallback) {
  return tEntity('entity:service:' + String(id) + ':name', fallback);
}

function tServiceDescription(id, fallback) {
  return tEntity('entity:service:' + String(id) + ':description', fallback);
}

function tField(id, fallback, part = 'label') {
  return tEntity('entity:field:' + String(id) + ':' + part, fallback);
}

function tFieldOption(id, index, fallback) {
  return tEntity('entity:field:' + String(id) + ':option:' + String(index), fallback);
}

function tFieldPlaceholder(id, fallback) {
  return tEntity('entity:field:' + String(id) + ':placeholder', fallback);
}

// تحميل المصدر الموحد للموقع والبوت. القاموس المضمن يبقى احتياطياً حتى لا تتأثر
// الصفحة إذا كانت قاعدة البيانات أو الواجهة غير متاحة مؤقتاً.
async function loadDatabaseLanguageCatalog(requestedLanguage = currentLang) {
  try {
    const languageCode = String(requestedLanguage || 'ar').toLowerCase().trim() || 'ar';
    const cacheKey = 'njaz_display_language_catalog_v3_' + languageCode;
    const now = Date.now();
    let payload = null;
    try {
      const cached = JSON.parse(sessionStorage.getItem(cacheKey) || 'null');
      if (cached && cached.expires > now && cached.payload) payload = cached.payload;
    } catch (e) { /* التخزين المؤقت اختياري */ }

    if (!payload) {
      const base = (typeof SITE_URL !== 'undefined' && SITE_URL)
        ? SITE_URL.replace(/\/$/, '')
        : new URL('.', document.baseURI).href.replace(/\/$/, '');
      const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      const timer = controller ? setTimeout(() => controller.abort(), 5000) : null;
      const response = await fetch(base + '/api/display_languages.php?lang=' + encodeURIComponent(languageCode), {
        credentials: 'same-origin', cache: 'default',
        headers: { 'Accept': 'application/json' },
        signal: controller ? controller.signal : undefined
      });
      if (timer) clearTimeout(timer);
      if (!response.ok) return;
      payload = await response.json();
      if (payload && payload.ok === true) {
        try { sessionStorage.setItem(cacheKey, JSON.stringify({ expires: now + 30000, payload })); } catch (e) { /* ignore */ }
      }
    }
    if (!payload || payload.ok !== true || !Array.isArray(payload.languages)) return;

    payload.languages.forEach(language => {
      const code = String(language.code || '').toLowerCase().trim();
      if (!code) return;
      LANG_DIR[code] = language.direction === 'rtl' ? 'rtl' : 'ltr';
      if (!T[code]) T[code] = {};
      const map = payload.translations?.[code] || {};
      Object.keys(map).forEach(key => { T[code][key] = map[key]; });
      if (payload.context_translations && Object.prototype.hasOwnProperty.call(payload.context_translations, code)) {
        contextTranslations[code] = payload.context_translations[code] || {};
      }
    });

    // تحديث أزرار اللغة إن كانت صفحة الإعدادات مفتوحة، من دون تغيير تصميمها.
    const languageButtons = document.getElementById('displayLanguageButtons');
    if (languageButtons) {
      languageButtons.textContent = '';
      payload.languages.forEach(language => {
        const code = String(language.code || '').toLowerCase().trim();
        if (!code) return;
        const button = document.createElement('button');
        button.className = 'lang-btn';
        button.dataset.lang = code;
        button.type = 'button';
        button.textContent = `${language.flag || ''} ${language.native_name || language.name || code}`.trim();
        button.onclick = () => setLang(code);
        button.style.cssText = 'flex:1;min-width:92px;padding:10px 6px;border-radius:10px;border:1.5px solid var(--border2);background:var(--bg2);color:var(--text);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:.2s';
        languageButtons.appendChild(button);
      });
    }
    databaseLanguageLoaded[languageCode] = true;
    languageCatalogLoaded = true;
    applyLang();
    if (typeof window.refreshLanguageCatalog === 'function') window.refreshLanguageCatalog();
  } catch (error) {
    // عدم توفر المصدر الديناميكي لا يعطل القاموس المضمن أو الصفحة.
    console.warn('Language catalog unavailable; using embedded translations.', error);
  }
}

// ── ترجمة نص واحد ────────────────────────────────────────────────────
function t(key) {
  if (currentLang === 'ar') return key;
  return T[currentLang]?.[key] ?? T['ar']?.[key] ?? key;
}

// ── تطبيق اللغة على الصفحة كاملة ────────────────────────────────────
function applyLang() {
  const lang = currentLang;
  const dict = T[lang] || {};

  // 1. عناصر data-i18n
  document.querySelectorAll('[data-i18n]').forEach(el => {
    const key = el.dataset.i18n;
    if (dict[key] !== undefined || T['en'][key] !== undefined) el.textContent = t(key);
  });

  // 2. الاستبدال النصي الشامل يُنفذ وقت الخمول، وليس أثناء إقلاع شاشة الدخول.
  // عناصر data-i18n والحقول المهمة تُحدّث فوراً أعلاه.
  scheduleTextTranslationScan();

  // 3. placeholders
  document.querySelectorAll('[placeholder]').forEach(el => {
    const orig = el.dataset.origPh || el.getAttribute('placeholder');
    el.dataset.origPh = orig;
    el.placeholder = dict[orig] || orig;
  });

  // 4. اتجاه النص والخط
  const dir = LANG_DIR[lang] || 'rtl';
  document.documentElement.lang = lang === 'ar' ? 'ar' : lang === 'tr' ? 'tr' : 'en';
  document.documentElement.dir  = dir;
  document.body.style.fontFamily = lang === 'ar'
    ? "'Cairo', sans-serif"
    : lang === 'tr'
    ? "'Cairo', sans-serif"
    : "'Cairo', sans-serif";

  // 5. تحديث أزرار اللغة
  document.querySelectorAll('.lang-btn').forEach(btn => {
    const on = btn.dataset.lang === lang;
    btn.style.borderColor = on ? 'var(--primary)' : 'var(--border2)';
    btn.style.background  = on ? 'rgba(30,111,255,.15)' : 'var(--bg2)';
    btn.style.color       = on ? 'var(--primary)' : 'var(--text)';
  });

  // 6. تحديث نص تأكيد تسجيل الخروج
  document.querySelectorAll('[data-logout]').forEach(el => {
    el.setAttribute('onclick', `if(confirm('${t("هل تريد تسجيل الخروج؟")}')) doLogout()`);
  });
}

// ── استبدال TextNodes وقت الخمول فقط ───────────────────────────────────
function scheduleTextTranslationScan() {
  if (textTranslationScanScheduled) return;
  textTranslationScanScheduled = true;
  const run = () => {
    textTranslationScanScheduled = false;
    if (document.body && currentLang !== 'ar') {
      replaceTextNodes(document.body, T[currentLang] || {});
    }
  };
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 1200 });
  } else {
    window.setTimeout(run, 350);
  }
}

function replaceTextNodes(node, dict) {
  if (!node) return;
  if (['SCRIPT','STYLE','INPUT','TEXTAREA','SELECT'].includes(node.nodeName)) return;

  if (node.nodeType === 3) {
    if (!originalTextNodes.has(node)) originalTextNodes.set(node, node.textContent || '');
    const original = originalTextNodes.get(node) || '';
    const trimmed = original.trim();
    if (trimmed) {
      const translated = dict[trimmed] ?? trimmed;
      const leading = original.match(/^\s*/)?.[0] || '';
      const trailing = original.match(/\s*$/)?.[0] || '';
      node.textContent = leading + translated + trailing;
    }
    return;
  }

  if (node.dataset?.noTranslate || node.classList?.contains('no-translate')) return;
  Array.from(node.childNodes).forEach(child => replaceTextNodes(child, dict));
}

// ── تغيير اللغة ──────────────────────────────────────────────────────
function setLang(lang) {
  if (!T[lang] && !LANG_DIR[lang] && lang !== 'ar') return;
  currentLang = lang;
  // حفظ في settings
  if (typeof saveSetting === 'function') saveSetting('lang', lang);
  if (typeof showSaveTick === 'function') showSaveTick('langSaved');
  applyLang();
  if (lang !== 'ar' && !databaseLanguageLoaded[lang]) loadDatabaseLanguageCatalog(lang);
}

// ── تهيئة اللغة عند التحميل ──────────────────────────────────────────
function initLang() {
  const saved = (() => {
    try { return JSON.parse(localStorage.getItem('user_settings'))?.lang || 'ar'; }
    catch(e) { return 'ar'; }
  })();
  currentLang = saved;
  applyLang();
  loadDatabaseLanguageCatalog(currentLang);
}

// تصدير صريح للتوافق مع app-script-1.php والـ inline handlers.
// بعض البيئات أو طبقات التخزين المؤقت قد لا تجعل الدوال المعرّفة في الملف
// الخارجي متاحة كخصائص مباشرة في window، بينما يستدعيها التطبيق من هناك.
if (typeof window !== 'undefined') {
  Object.assign(window, {
    tEntity, tCategory, tService, tServiceDescription,
    tField, tFieldOption, tFieldPlaceholder,
    initLang, setLang, applyLang
  });
}
