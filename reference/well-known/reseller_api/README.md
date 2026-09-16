# API الموزّعين (Reseller API) — api.njaz.net

نسخة مطابقة في الشكل والمسارات وأسلوب الاستجابة لتوثيق Oranos المرجعي
(`https://api.oranosmarket.com/api-docs`) لكن تعمل على بيانات نجاز كارد
الفعلية (الخدمات، الأسعار، مجموعات التسعير، مخزون الأكواد، المستخدمون).

## 1) خطوات التركيب

1. ارفع مجلد `reseller_api/` بالكامل إلى **جذر مشروعك الرئيسي** (بجانب مجلد
   `includes/` الحالي مباشرة) — أي يصبح المسار: `public_html/reseller_api/`.
2. من لوحة استضافتك (cPanel → Subdomains) أنشئ نطاقاً فرعياً:
   - Subdomain: `api`
   - Domain: `njaz.net`
   - **Document Root**: وجّهه إلى `public_html/reseller_api` (وليس جذر الموقع).
3. فعّل شهادة SSL للنطاق الفرعي `api.njaz.net` (AutoSSL كافية عادة).
4. افتح `https://api.njaz.net/api-docs` للتأكد أن كل شيء يعمل.

بهذا الشكل `api.njaz.net` و `njaz.net` يشتركان في نفس قاعدة البيانات
ونفس ملفات `includes/` بدون أي تكرار للكود.

## 2) كيف يحصل الموزّع على api-token؟

نظام التوكن **نفسه الموجود عندك حالياً** في `/api.php` (لوحة "API" لكل
مستخدم — رصيد + توكن + IP allow-list). أي مستخدم يملك `api_enabled=1`
يمكنه استخدام توكنه الحالي مباشرة على `api.njaz.net` دون أي تعديل — هما
نفس عمود `users.api_key`. الفرق الوحيد: هنا اسم الـ Header هو `api-token`
(بدل `Authorization: Bearer`) ليطابق معيار Oranos تماماً كما طلبت.

## 3) نقاط النهاية (Endpoints)

| Method | Path | الوصف |
|---|---|---|
| GET | `/client/api/profile` | الرصيد + البريد |
| GET | `/client/api/products` | كل الخدمات (يدعم `?products_id=` و `?base=1`) |
| GET | `/client/api/content/{parent_id}` | أقسام ومنتجات قسم معيّن (0 = الرئيسية) |
| GET | `/client/api/newOrder/{service_id}/params?qty=&order_uuid=&...` | إنشاء طلب (Idempotent عبر order_uuid) |
| GET | `/client/api/check?orders=[id1,id2]` | فحص حالة طلب/طلبات (أو `&uuid=1` للفحص بالـ UUID) |

جميع رموز الأخطاء (120–130 وأخطاء الطلبات 100–500) مطابقة حرفياً لما ورد
في `api-docs`.

## 4) قرارات وتبسيطات مهمة اتخذتها — يجب أن تعرفها

- **base_price**: نظام نجاز لا يفصل بين "سعر التكلفة" و"سعر البيع"
  للموزّعين في جدول `services` (عمود سعر واحد فقط `price`، تُطبَّق عليه
  مجموعات التسعير `pricing_groups` إن وُجدت). لذلك حالياً
  `base_price == price الأساسي قبل خصم مجموعة التسعير`، وليس تكلفة
  منفصلة كما في مثال Oranos. إن أردت لاحقاً تمييزاً حقيقياً بين التكلفة
  والسعر، هذا يحتاج عمود تكلفة إضافي في `services` — أخبرني إن رغبت
  ببنائه.

- **قيمة `qty_values` كمصفوفة قيم محددة** (مثل `["110","150","210"]` في
  توثيق Oranos) **غير مدعومة حالياً** لأن جدول `services` عندك يخزّن فقط
  حد أدنى وحد أقصى (`min_qty`/`max_qty`)، وليس قائمة قيم مسموحة. النظام
  يُرجع دائماً إما `null` (كمية = 1) أو `{min, max}`.

- **التسليم الآلي عند الطلب (`/newOrder`)**:
  - الخدمات المرتبطة **بمخزون أكواد مفعّل** (نظام إدارة مخزون الأكواد
    الذي بنيناه سابقاً) → تُسلَّم فوراً وتُرجع `status: "accept"`،
    بنفس آلية FIFO + القفل الآمنة المستخدمة في باقي الموقع.
  - أي خدمة أخرى (مزوّد خارجي / تسليم يدوي) → تُرجع `status: "wait"`
    وتظهر كطلب عادي "معلّق" في لوحة الإدارة (صفحة الطلبات) ليتم تنفيذها
    يدوياً أو عبر آليتكم الحالية — **لا يوجد في هذا الإصدار إرسال آلي
    فوري للمزودين الخارجيين من داخل API الموزّعين نفسه** (بخلاف الموقع
    الرئيسي الذي يرسل للمزوّد عند الشراء). يمكن إضافة ذلك لاحقاً بنفس
    منطق `place_order.php` إن احتجته — أخبرني وسأبنيه.

- **رصيد الموزّع = رصيد محفظته العادي** في نجاز (`users.balance`) —
  نفس المحفظة المستخدمة في الموقع، وليس رصيداً منفصلاً خاصاً بالـ API.

- **تخزين معرّف الطلب**: أضفت عمودين جديدين لجدول `orders`:
  `api_order_id` (بصيغة `ID_xxxxxxxxxxxxxxxx` كما في مثال Oranos) و
  `api_order_uuid` (لتخزين الـ order_uuid ودعم التكرار الآمن Idempotency).
  يتم إنشاؤهما تلقائياً عند أول طلب (بنفس أسلوب باقي أنظمتك — لا حاجة
  لتشغيل SQL يدوياً).

## 5) الاختبار السريع

```bash
curl -H "api-token: TOKEN_HERE" https://api.njaz.net/client/api/profile

curl -H "api-token: TOKEN_HERE" https://api.njaz.net/client/api/products

curl -H "api-token: TOKEN_HERE" \
  "https://api.njaz.net/client/api/newOrder/6/params?qty=1&playerId=123456789&order_uuid=$(uuidgen)"

curl -H "api-token: TOKEN_HERE" \
  "https://api.njaz.net/client/api/check?orders=[ID_xxxxxxxxxxxxxxxx]"
```
