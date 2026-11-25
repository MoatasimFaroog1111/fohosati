# Fohosati Platform 0.7.0

إضافة WordPress عربية لمنصة **فحوصاتي** تجمع بين إدارة البيانات والواجهة العامة.

## ما الذي تنشئه الإضافة؟

عند التفعيل تنشئ جداول مستقلة للمعامل والفروع والفحوصات وعلاقات التوفر والمناطق، ثم تستورد بيانات الـPilot. كما تنشئ تلقائياً ثلاث صفحات منشورة:

- **فحوصاتي** — الصفحة الرئيسية (`[fohosati_home]`)
- **البحث عن فحص** — البحث والنتائج وصفحة الفرع الديناميكية (`[fohosati_search]`)
- **دليل المعامل** — استعراض وتصفية الفروع (`[fohosati_labs]`)

## لوحة الإدارة

من قائمة **فحوصاتي** في WordPress يمكن إنشاء وتعديل وحذف:

- المعامل
- الفروع
- الفحوصات
- علاقات توفر الفحوصات والأسعار
- المناطق

وتتوفر نسخة احتياطية JSON وإعادة استيراد بيانات الـPilot المفقودة.

## التثبيت

1. WordPress → الإضافات → أضف إضافة جديدة → رفع إضافة.
2. ارفع ملف ZIP ثم فعّله.
3. افتح **فحوصاتي** من لوحة الإدارة لمراجعة البيانات.
4. افتح الصفحات المنشأة تلقائياً من الموقع العام.

## التشغيل محليًا على اللابتوب (Docker + WP-CLI)

انسخ الأوامر التالية كما هي في التيرمينال:

```bash
git clone https://github.com/MoatasimFaroog1111/fohosati.git
cd fohosati

cat <<'YAML' > docker-compose.yml
services:
  db:
    image: mysql:8.0
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
      MYSQL_ROOT_PASSWORD: rootpass
    command: --default-authentication-plugin=mysql_native_password
    volumes:
      - db_data:/var/lib/mysql

  wordpress:
    image: wordpress:6.8-php8.2-apache
    restart: unless-stopped
    depends_on:
      - db
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - wp_data:/var/www/html
      - ./fohosati-admin:/var/www/html/wp-content/plugins/fohosati-admin

volumes:
  db_data:
  wp_data:
YAML

docker compose up -d

docker run --rm --network "$(basename "$PWD")_default" \
  -e WORDPRESS_DB_HOST=db:3306 \
  -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress \
  -e WORDPRESS_DB_NAME=wordpress \
  -v "$(basename "$PWD")_wp_data:/var/www/html" \
  -v "$PWD/fohosati-admin:/var/www/html/wp-content/plugins/fohosati-admin" \
  wordpress:cli-php8.2 \
  sh -lc '
    wp core is-installed --allow-root || wp core install \
      --url="http://localhost:8080" \
      --title="Fohosati Local" \
      --admin_user="admin" \
      --admin_password="admin123456" \
      --admin_email="admin@example.com" \
      --skip-email \
      --allow-root
    wp plugin activate fohosati-admin --allow-root
  '
```

بعدها ادخل على:

- الموقع: `http://localhost:8080`
- لوحة التحكم: `http://localhost:8080/wp-admin`
- بيانات الدخول: `admin / admin123456`

## ملاحظات

- الواجهة RTL ومتجاوبة للموبايل.
- روابط الخريطة تستخدم OpenStreetMap.
- البيانات الحالية للـPilot تعامل كـ Published/Verified بناءً على قرار مالك المشروع بتاريخ 2026-11-17.
- الحقول المفقودة لا تُخترع وتظل فارغة إلى أن يتم تعديلها من لوحة الإدارة.
- فحوصاتي دليل معلومات واتصال، وليس منصة تشخيص أو علاج.


## v0.3.0
- يجعل صفحة فحوصاتي الصفحة الرئيسية تلقائيًا.
- يستخدم قالب عرض مستقل للصفحات العامة بدون Header/Footer قالب WordPress.
- يحذف Sample Page الافتراضية إلى سلة المهملات.
- يضيف Header/Footer عربيين متجاوبين للمنصة.


## v0.4.0
- يخفي شريط إدارة WordPress تلقائيًا على صفحات فحوصاتي العامة فقط.
- يبقي شريط الإدارة داخل wp-admin دون تغيير.
- لا يغيّر قاعدة البيانات أو بيانات الـPilot عند التحديث من v0.3.0.

## v0.5.0
- تحديث واجهة المستخدم العامة بتصميم طبي حديث أكثر وضوحًا.
- إضافة تفاعلات JavaScript للعرض التدريجي للعناصر وتجربة تنقل أفضل على الجوال.
- تحسين شريط التنقل في القالب العام مع زر قائمة مخصص للموبايل.

## v0.6.0
- إضافة Landing page أوسع تشمل: الخدمات، خطوات الاستخدام، والأسئلة الشائعة.
- تحسين التجربة العامة للموقع بطابع طبي أكثر احترافية للمستخدم النهائي.
- دعم سلوك FAQ التفاعلي مع إبقاء إجابة واحدة مفتوحة في كل مرة.


## v0.7.0
- إضافة Landing تسويقية أقوى مع أزرار CTA مزدوجة في الواجهة الرئيسية.
- إضافة قسم شهادات المستخدمين وقسم شركاء النجاح داخل الصفحة الرئيسية.
- إضافة نموذج تواصل سريع يفتح البريد مباشرة مع تعبئة بيانات الرسالة تلقائيًا.
