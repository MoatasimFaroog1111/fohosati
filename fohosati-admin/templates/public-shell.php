<?php
if (!defined('ABSPATH')) { exit; }
$home_id   = absint(get_option('fohosati_page_home'));
$search_id = absint(get_option('fohosati_page_search'));
$labs_id   = absint(get_option('fohosati_page_labs'));
$home_url_foh   = $home_id ? get_permalink($home_id) : home_url('/');
$search_url_foh = $search_id ? get_permalink($search_id) : home_url('/');
$labs_url_foh   = $labs_id ? get_permalink($labs_id) : home_url('/');
?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('foh-platform-body'); ?>>
<?php wp_body_open(); ?>
<header class="foh-global-header" role="banner">
    <div class="foh-global-inner">
        <a class="foh-brand" href="<?php echo esc_url($home_url_foh); ?>" aria-label="فحوصاتي - الرئيسية">
            <span class="foh-brand-mark">ف</span>
            <span class="foh-brand-copy"><strong>فحوصاتي</strong><small>دليل المعامل الطبية</small></span>
        </a>
        <button class="foh-menu-toggle" type="button" aria-expanded="false" aria-controls="foh-primary-nav" aria-label="فتح القائمة">☰</button>
        <nav id="foh-primary-nav" class="foh-global-nav" aria-label="التنقل الرئيسي">
            <a <?php echo is_page($home_id) ? 'class="is-active"' : ''; ?> href="<?php echo esc_url($home_url_foh); ?>">الرئيسية</a>
            <a <?php echo is_page($search_id) ? 'class="is-active"' : ''; ?> href="<?php echo esc_url($search_url_foh); ?>">البحث عن فحص</a>
            <a <?php echo is_page($labs_id) ? 'class="is-active"' : ''; ?> href="<?php echo esc_url($labs_url_foh); ?>">دليل المعامل</a>
        </nav>
        <a class="foh-header-cta" href="<?php echo esc_url($search_url_foh); ?>">ابحث الآن</a>
    </div>
</header>
<main id="foh-main" class="foh-platform-main" role="main">
<?php
while (have_posts()) {
    the_post();
    the_content();
}
?>
</main>
<footer class="foh-global-footer">
    <div class="foh-global-footer-inner">
        <div>
            <a class="foh-footer-brand" href="<?php echo esc_url($home_url_foh); ?>">فحوصاتي</a>
            <p>دليل بحث واتصال بالمعامل الطبية. لا يقدم تشخيصًا أو علاجًا، ويُنصح بتأكيد توفر الفحص والسعر مع المعمل قبل الزيارة.</p>
        </div>
        <nav aria-label="روابط فحوصاتي">
            <a href="<?php echo esc_url($home_url_foh); ?>">الرئيسية</a>
            <a href="<?php echo esc_url($search_url_foh); ?>">البحث عن فحص</a>
            <a href="<?php echo esc_url($labs_url_foh); ?>">دليل المعامل</a>
        </nav>
        <small>© <?php echo esc_html(wp_date('Y')); ?> فحوصاتي</small>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
