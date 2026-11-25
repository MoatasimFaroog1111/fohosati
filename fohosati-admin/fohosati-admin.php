<?php
/**
 * Plugin Name: Fohosati Admin
 * Description: منصة فحوصاتي: إدارة المعامل والفروع والفحوصات والتوفر والمناطق، مع واجهة عامة عربية للبحث ودليل المعامل وصفحات الفروع.
 * Version: 0.6.0
 * Author: Fohosati
 * Text Domain: fohosati-admin
 */

if (!defined('ABSPATH')) { exit; }

final class Fohosati_Admin_Plugin {
    const VERSION = '0.6.0';
    const OPTION_DB_VERSION = 'fohosati_db_version';
    const OPTION_SEEDED = 'fohosati_seeded_v4';
    private static $instance = null;
    private $tables = [];

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->tables = [
            'laboratories' => $wpdb->prefix . 'fohosati_laboratories',
            'branches'     => $wpdb->prefix . 'fohosati_branches',
            'tests'        => $wpdb->prefix . 'fohosati_tests',
            'offers'       => $wpdb->prefix . 'fohosati_offers',
            'areas'        => $wpdb->prefix . 'fohosati_areas',
        ];
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'maybe_upgrade']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
        add_filter('template_include', [$this, 'template_include'], 99);
        add_filter('show_admin_bar', [$this, 'hide_admin_bar_on_public_pages'], 99);
        add_action('admin_post_fohosati_save', [$this, 'handle_save']);
        add_action('admin_post_fohosati_delete', [$this, 'handle_delete']);
        add_action('admin_post_fohosati_seed', [$this, 'handle_seed']);
        add_action('admin_post_fohosati_export_json', [$this, 'handle_export_json']);
        add_shortcode('fohosati_home', [$this, 'shortcode_home']);
        add_shortcode('fohosati_search', [$this, 'shortcode_search']);
        add_shortcode('fohosati_labs', [$this, 'shortcode_labs']);
        add_shortcode('fohosati_branch', [$this, 'shortcode_branch']);
    }

    public static function activate() {
        $plugin = self::instance();
        $plugin->create_tables();
        $plugin->seed_if_needed();
        $plugin->ensure_public_pages();
        flush_rewrite_rules();
    }

    public function maybe_upgrade() {
        if (get_option(self::OPTION_DB_VERSION) !== self::VERSION) {
            $this->create_tables();
        }
        $this->seed_if_needed();
        $this->ensure_public_pages();
    }

    public function ensure_public_pages() {
        $pages = [
            'home'   => ['title'=>'فحوصاتي', 'slug'=>'fohosati', 'content'=>'[fohosati_home]'],
            'search' => ['title'=>'البحث عن فحص', 'slug'=>'fohosati-search', 'content'=>'[fohosati_search]'],
            'labs'   => ['title'=>'دليل المعامل', 'slug'=>'fohosati-labs', 'content'=>'[fohosati_labs]'],
        ];
        foreach ($pages as $key => $page) {
            $option = 'fohosati_page_' . $key;
            $page_id = absint(get_option($option));
            if ($page_id && get_post($page_id)) continue;
            $existing = get_page_by_path($page['slug']);
            if ($existing) {
                update_option($option, $existing->ID);
                continue;
            }
            $new_id = wp_insert_post([
                'post_title'   => $page['title'],
                'post_name'    => $page['slug'],
                'post_content' => $page['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);
            if (!is_wp_error($new_id) && $new_id) update_option($option, $new_id);
        }

        // Make Fohosati the actual site homepage.
        $home_id = absint(get_option('fohosati_page_home'));
        if ($home_id && get_post($home_id)) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $home_id);
        }

        // Remove the default WordPress sample page from the public experience.
        $sample = get_page_by_path('sample-page');
        if ($sample && $sample->post_type === 'page' && $sample->post_status !== 'trash') {
            wp_trash_post($sample->ID);
        }
    }


    public function hide_admin_bar_on_public_pages($show) {
        if (is_admin()) return $show;
        $page_ids = array_filter([
            absint(get_option('fohosati_page_home')),
            absint(get_option('fohosati_page_search')),
            absint(get_option('fohosati_page_labs')),
        ]);
        if ($page_ids && is_page($page_ids)) {
            return false;
        }
        return $show;
    }

    public function template_include($template) {
        if (is_admin()) return $template;
        $page_ids = array_filter([
            absint(get_option('fohosati_page_home')),
            absint(get_option('fohosati_page_search')),
            absint(get_option('fohosati_page_labs')),
        ]);
        if ($page_ids && is_page($page_ids)) {
            $custom = plugin_dir_path(__FILE__) . 'templates/public-shell.php';
            if (file_exists($custom)) return $custom;
        }
        return $template;
    }

    private function public_page_url($key, $args = []) {
        $page_id = absint(get_option('fohosati_page_' . $key));
        $url = $page_id ? get_permalink($page_id) : home_url('/');
        return $args ? add_query_arg($args, $url) : $url;
    }

    public function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql = [];
        $sql[] = "CREATE TABLE {$this->tables['laboratories']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            name_ar VARCHAR(255) NOT NULL,
            name_en VARCHAR(255) NULL,
            description TEXT NULL,
            website VARCHAR(500) NULL,
            general_phone VARCHAR(80) NULL,
            general_whatsapp VARCHAR(80) NULL,
            publication_status VARCHAR(40) NOT NULL DEFAULT 'Published',
            contact_status VARCHAR(40) NOT NULL DEFAULT 'Verified',
            license_status VARCHAR(40) NOT NULL DEFAULT 'Not Checked',
            publication_consent VARCHAR(10) NOT NULL DEFAULT 'Yes',
            source_type VARCHAR(60) NULL,
            source_reference TEXT NULL,
            last_general_review DATE NULL,
            notes LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY code (code), KEY name_ar (name_ar)
        ) $charset;";

        $sql[] = "CREATE TABLE {$this->tables['areas']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            city_ar VARCHAR(150) NULL,
            city_en VARCHAR(150) NULL,
            area_ar VARCHAR(150) NULL,
            area_en VARCHAR(150) NULL,
            alternative_names TEXT NULL,
            record_status VARCHAR(30) NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY code (code), KEY area_ar (area_ar)
        ) $charset;";

        $sql[] = "CREATE TABLE {$this->tables['branches']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            laboratory_code VARCHAR(30) NOT NULL,
            branch_name_ar VARCHAR(255) NOT NULL,
            branch_name_en VARCHAR(255) NULL,
            city VARCHAR(150) NULL,
            area_code VARCHAR(30) NULL,
            address TEXT NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            phone VARCHAR(80) NULL,
            whatsapp VARCHAR(80) NULL,
            opening_hours VARCHAR(255) NULL,
            publication_status VARCHAR(40) NOT NULL DEFAULT 'Published',
            contact_status VARCHAR(40) NOT NULL DEFAULT 'Verified',
            location_status VARCHAR(40) NOT NULL DEFAULT 'Verified',
            contact_verified_at DATE NULL,
            location_verified_at DATE NULL,
            last_general_review DATE NULL,
            next_review_due DATE NULL,
            source_type VARCHAR(60) NULL,
            source_reference TEXT NULL,
            notes LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY code (code), KEY laboratory_code (laboratory_code), KEY area_code (area_code), KEY branch_name_ar (branch_name_ar)
        ) $charset;";

        $sql[] = "CREATE TABLE {$this->tables['tests']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            name_ar VARCHAR(255) NOT NULL,
            name_en VARCHAR(255) NULL,
            abbreviation VARCHAR(80) NULL,
            synonyms TEXT NULL,
            category VARCHAR(120) NULL,
            short_description TEXT NULL,
            record_status VARCHAR(30) NOT NULL DEFAULT 'Active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY code (code), KEY name_ar (name_ar), KEY abbreviation (abbreviation)
        ) $charset;";

        $sql[] = "CREATE TABLE {$this->tables['offers']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            branch_code VARCHAR(30) NOT NULL,
            test_code VARCHAR(30) NOT NULL,
            availability_status VARCHAR(50) NOT NULL DEFAULT 'Confirmed Available',
            price DECIMAL(18,2) NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'SDG',
            availability_verified_at DATE NULL,
            price_verified_at DATE NULL,
            source_type VARCHAR(60) NULL,
            source_reference TEXT NULL,
            verified_by VARCHAR(150) NULL,
            last_reviewed_at DATE NULL,
            notes LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY code (code), UNIQUE KEY branch_test (branch_code,test_code), KEY branch_code (branch_code), KEY test_code (test_code)
        ) $charset;";

        foreach ($sql as $statement) dbDelta($statement);
        update_option(self::OPTION_DB_VERSION, self::VERSION);
    }

    private function entity_config($entity) {
        $selects = [
            'publication_status' => ['Published'=>'Published','Pending Review'=>'Pending Review','Needs Update'=>'Needs Update','Suspended'=>'Suspended','Inactive'=>'Inactive'],
            'contact_status' => ['Verified'=>'Verified','Unverified'=>'Unverified','Needs Recheck'=>'Needs Recheck','Inactive'=>'Inactive'],
            'location_status' => ['Verified'=>'Verified','Unverified'=>'Unverified','Needs Recheck'=>'Needs Recheck','Unknown'=>'Unknown'],
            'license_status' => ['Not Checked'=>'Not Checked','Document Provided'=>'Document Provided','Verified from Source'=>'Verified from Source','Expired/Invalid'=>'Expired/Invalid','Unclear'=>'Unclear'],
            'record_status' => ['Active'=>'Active','Inactive'=>'Inactive'],
            'availability_status' => ['Confirmed Available'=>'Confirmed Available','Reported Available'=>'Reported Available','Unknown'=>'Unknown','Temporarily Unavailable'=>'Temporarily Unavailable','Not Available'=>'Not Available'],
            'currency' => ['SDG'=>'SDG','USD'=>'USD'],
            'publication_consent' => ['Yes'=>'Yes','No'=>'No'],
        ];
        $cfg = [
            'laboratories' => [
                'title'=>'المعامل','prefix'=>'LAB','name_field'=>'name_ar',
                'fields'=>[
                    'name_ar'=>['label'=>'اسم المعمل بالعربية','type'=>'text','required'=>true],
                    'name_en'=>['label'=>'الاسم بالإنجليزية','type'=>'text'],
                    'description'=>['label'=>'الوصف','type'=>'textarea'],
                    'website'=>['label'=>'الموقع الإلكتروني','type'=>'url'],
                    'general_phone'=>['label'=>'الهاتف العام','type'=>'text'],
                    'general_whatsapp'=>['label'=>'واتساب','type'=>'text'],
                    'publication_status'=>['label'=>'حالة النشر','type'=>'select','options'=>$selects['publication_status'],'default'=>'Published'],
                    'contact_status'=>['label'=>'حالة التواصل','type'=>'select','options'=>$selects['contact_status'],'default'=>'Verified'],
                    'license_status'=>['label'=>'حالة الترخيص','type'=>'select','options'=>$selects['license_status'],'default'=>'Not Checked'],
                    'publication_consent'=>['label'=>'موافقة النشر','type'=>'select','options'=>$selects['publication_consent'],'default'=>'Yes'],
                    'source_type'=>['label'=>'نوع المصدر','type'=>'text'],
                    'source_reference'=>['label'=>'رابط/مرجع المصدر','type'=>'textarea'],
                    'last_general_review'=>['label'=>'آخر مراجعة','type'=>'date'],
                    'notes'=>['label'=>'ملاحظات','type'=>'textarea'],
                ]
            ],
            'branches' => [
                'title'=>'الفروع','prefix'=>'BR','name_field'=>'branch_name_ar',
                'fields'=>[
                    'laboratory_code'=>['label'=>'المعمل','type'=>'relation','relation'=>'laboratories','required'=>true],
                    'branch_name_ar'=>['label'=>'اسم الفرع بالعربية','type'=>'text','required'=>true],
                    'branch_name_en'=>['label'=>'اسم الفرع بالإنجليزية','type'=>'text'],
                    'city'=>['label'=>'المدينة','type'=>'text','default'=>'Khartoum'],
                    'area_code'=>['label'=>'المنطقة','type'=>'relation','relation'=>'areas'],
                    'address'=>['label'=>'العنوان','type'=>'textarea'],
                    'latitude'=>['label'=>'Latitude','type'=>'number','step'=>'0.0000001'],
                    'longitude'=>['label'=>'Longitude','type'=>'number','step'=>'0.0000001'],
                    'phone'=>['label'=>'الهاتف','type'=>'text'],
                    'whatsapp'=>['label'=>'واتساب','type'=>'text'],
                    'opening_hours'=>['label'=>'ساعات العمل','type'=>'text'],
                    'publication_status'=>['label'=>'حالة النشر','type'=>'select','options'=>$selects['publication_status'],'default'=>'Published'],
                    'contact_status'=>['label'=>'حالة التواصل','type'=>'select','options'=>$selects['contact_status'],'default'=>'Verified'],
                    'location_status'=>['label'=>'حالة الموقع','type'=>'select','options'=>$selects['location_status'],'default'=>'Verified'],
                    'contact_verified_at'=>['label'=>'تاريخ تحقق الاتصال','type'=>'date'],
                    'location_verified_at'=>['label'=>'تاريخ تحقق الموقع','type'=>'date'],
                    'last_general_review'=>['label'=>'آخر مراجعة','type'=>'date'],
                    'next_review_due'=>['label'=>'المراجعة القادمة','type'=>'date'],
                    'source_type'=>['label'=>'نوع المصدر','type'=>'text'],
                    'source_reference'=>['label'=>'مرجع المصدر','type'=>'textarea'],
                    'notes'=>['label'=>'ملاحظات','type'=>'textarea'],
                ]
            ],
            'tests' => [
                'title'=>'الفحوصات','prefix'=>'TEST','name_field'=>'name_ar',
                'fields'=>[
                    'name_ar'=>['label'=>'اسم الفحص بالعربية','type'=>'text','required'=>true],
                    'name_en'=>['label'=>'اسم الفحص بالإنجليزية','type'=>'text'],
                    'abbreviation'=>['label'=>'الاختصار','type'=>'text'],
                    'synonyms'=>['label'=>'المرادفات','type'=>'textarea'],
                    'category'=>['label'=>'التصنيف','type'=>'text'],
                    'short_description'=>['label'=>'وصف مختصر','type'=>'textarea'],
                    'record_status'=>['label'=>'الحالة','type'=>'select','options'=>$selects['record_status'],'default'=>'Active'],
                ]
            ],
            'offers' => [
                'title'=>'توفر الفحوصات','prefix'=>'OFFER','name_field'=>'code',
                'fields'=>[
                    'branch_code'=>['label'=>'الفرع','type'=>'relation','relation'=>'branches','required'=>true],
                    'test_code'=>['label'=>'الفحص','type'=>'relation','relation'=>'tests','required'=>true],
                    'availability_status'=>['label'=>'حالة التوفر','type'=>'select','options'=>$selects['availability_status'],'default'=>'Confirmed Available'],
                    'price'=>['label'=>'السعر','type'=>'number','step'=>'0.01'],
                    'currency'=>['label'=>'العملة','type'=>'select','options'=>$selects['currency'],'default'=>'SDG'],
                    'availability_verified_at'=>['label'=>'تاريخ تحقق التوفر','type'=>'date'],
                    'price_verified_at'=>['label'=>'تاريخ تحقق السعر','type'=>'date'],
                    'source_type'=>['label'=>'نوع المصدر','type'=>'text'],
                    'source_reference'=>['label'=>'مرجع المصدر','type'=>'textarea'],
                    'verified_by'=>['label'=>'تم التحقق بواسطة','type'=>'text'],
                    'last_reviewed_at'=>['label'=>'آخر مراجعة','type'=>'date'],
                    'notes'=>['label'=>'ملاحظات','type'=>'textarea'],
                ]
            ],
            'areas' => [
                'title'=>'المناطق','prefix'=>'AREA','name_field'=>'area_ar',
                'fields'=>[
                    'city_ar'=>['label'=>'المدينة بالعربية','type'=>'text','default'=>'الخرطوم'],
                    'city_en'=>['label'=>'المدينة بالإنجليزية','type'=>'text','default'=>'Khartoum'],
                    'area_ar'=>['label'=>'المنطقة بالعربية','type'=>'text'],
                    'area_en'=>['label'=>'المنطقة بالإنجليزية','type'=>'text'],
                    'alternative_names'=>['label'=>'أسماء بديلة','type'=>'textarea'],
                    'record_status'=>['label'=>'الحالة','type'=>'select','options'=>$selects['record_status'],'default'=>'Active'],
                    'notes'=>['label'=>'ملاحظات','type'=>'textarea'],
                ]
            ],
        ];
        return $cfg[$entity] ?? null;
    }

    private function sanitize_value($value, $type) {
        if ($type === 'textarea') return sanitize_textarea_field($value);
        if ($type === 'url') return esc_url_raw($value);
        if ($type === 'number') return ($value === '' ? null : (float)$value);
        if ($type === 'date') return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        return sanitize_text_field($value);
    }

    private function next_code($entity, $prefix) {
        global $wpdb;
        $table = $this->tables[$entity];
        $codes = $wpdb->get_col("SELECT code FROM $table WHERE code LIKE '" . esc_sql($prefix) . "-%'");
        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/-(\d+)$/', $code, $m)) $max = max($max, (int)$m[1]);
        }
        return sprintf('%s-%03d', $prefix, $max + 1);
    }

    public function seed_if_needed() {
        if (get_option(self::OPTION_SEEDED)) return;
        $this->seed_data(false);
    }

    private function seed_data($force_missing_only = true) {
        global $wpdb;
        $file = plugin_dir_path(__FILE__) . 'data/seed.json';
        if (!file_exists($file)) return new WP_Error('seed_missing', 'Seed file missing');
        $data = json_decode(file_get_contents($file), true);
        if (!$data) return new WP_Error('seed_invalid', 'Seed JSON invalid');
        $maps = [
            'laboratories'=>['laboratory_id'=>'code'], 'branches'=>['branch_id'=>'code','laboratory_id'=>'laboratory_code','area_id'=>'area_code'],
            'tests'=>['test_id'=>'code'], 'offers'=>['offer_id'=>'code','branch_id'=>'branch_code','test_id'=>'test_code'], 'areas'=>['area_id'=>'code']
        ];
        $allowed = [];
        foreach (array_keys($this->tables) as $e) {
            $cfg = $this->entity_config($e); $allowed[$e] = array_merge(['code'], array_keys($cfg['fields']));
        }
        foreach (['laboratories','areas','branches','tests','offers'] as $entity) {
            foreach (($data[$entity] ?? []) as $row) {
                $insert = [];
                foreach ($row as $key=>$value) {
                    $key2 = $maps[$entity][$key] ?? $key;
                    if (in_array($key2, $allowed[$entity], true)) $insert[$key2] = $value;
                }
                if (empty($insert['code'])) continue;
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables[$entity]} WHERE code=%s", $insert['code']));
                if ($exists) {
                    if (!$force_missing_only) { $insert['updated_at']=current_time('mysql'); $wpdb->update($this->tables[$entity], $insert, ['id'=>(int)$exists]); }
                } else {
                    $insert['created_at']=current_time('mysql'); $insert['updated_at']=current_time('mysql');
                    $wpdb->insert($this->tables[$entity], $insert);
                }
            }
        }
        update_option(self::OPTION_SEEDED, current_time('mysql'));
        return true;
    }

    public function admin_menu() {
        add_menu_page('فحوصاتي', 'فحوصاتي', 'manage_options', 'fohosati-admin', [$this,'render_page'], 'dashicons-heart', 26);
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'fohosati-admin') === false) return;
        wp_enqueue_style('fohosati-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('fohosati-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], self::VERSION, true);
    }

    public function enqueue_public_assets() {
        wp_enqueue_style('fohosati-public', plugin_dir_url(__FILE__) . 'assets/public.css', [], self::VERSION);
        wp_enqueue_script('fohosati-public', plugin_dir_url(__FILE__) . 'assets/public.js', [], self::VERSION, true);
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('fohosati_save');
        global $wpdb;
        $entity = sanitize_key($_POST['entity'] ?? ''); $cfg=$this->entity_config($entity);
        if (!$cfg) wp_die('Invalid entity');
        $id = absint($_POST['id'] ?? 0); $row=[];
        foreach ($cfg['fields'] as $field=>$meta) {
            $raw = $_POST[$field] ?? '';
            $row[$field] = $this->sanitize_value(wp_unslash($raw), $meta['type']);
        }
        foreach ($cfg['fields'] as $field=>$meta) {
            if (!empty($meta['required']) && ($row[$field] === '' || $row[$field] === null)) {
                wp_safe_redirect(add_query_arg(['page'=>'fohosati-admin','entity'=>$entity,'msg'=>'required'], admin_url('admin.php'))); exit;
            }
        }
        $row['updated_at']=current_time('mysql');
        if ($id) {
            $wpdb->update($this->tables[$entity], $row, ['id'=>$id]);
        } else {
            $row['code']=$this->next_code($entity,$cfg['prefix']); $row['created_at']=current_time('mysql');
            $wpdb->insert($this->tables[$entity],$row);
        }
        wp_safe_redirect(add_query_arg(['page'=>'fohosati-admin','entity'=>$entity,'msg'=>'saved'], admin_url('admin.php'))); exit;
    }

    public function handle_delete() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $entity=sanitize_key($_GET['entity'] ?? ''); $id=absint($_GET['id'] ?? 0); $cfg=$this->entity_config($entity);
        if (!$cfg || !$id) wp_die('Invalid request');
        check_admin_referer('fohosati_delete_'.$entity.'_'.$id);
        global $wpdb; $wpdb->delete($this->tables[$entity], ['id'=>$id]);
        wp_safe_redirect(add_query_arg(['page'=>'fohosati-admin','entity'=>$entity,'msg'=>'deleted'], admin_url('admin.php'))); exit;
    }

    public function handle_seed() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('fohosati_seed');
        $this->seed_data(true);
        wp_safe_redirect(add_query_arg(['page'=>'fohosati-admin','msg'=>'seeded'], admin_url('admin.php'))); exit;
    }

    public function handle_export_json() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('fohosati_export_json');
        global $wpdb; $out=['exported_at'=>current_time('mysql')];
        foreach ($this->tables as $entity=>$table) $out[$entity]=$wpdb->get_results("SELECT * FROM $table ORDER BY id ASC", ARRAY_A);
        nocache_headers(); header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename=fohosati-backup-'.gmdate('Ymd-His').'.json');
        echo wp_json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
    }

    private function relation_options($relation) {
        global $wpdb; $table=$this->tables[$relation];
        if ($relation==='laboratories') return $wpdb->get_results("SELECT code,name_ar AS label FROM $table ORDER BY name_ar", ARRAY_A);
        if ($relation==='branches') return $wpdb->get_results("SELECT code,branch_name_ar AS label FROM $table ORDER BY branch_name_ar", ARRAY_A);
        if ($relation==='tests') return $wpdb->get_results("SELECT code,CONCAT(name_ar, IF(abbreviation IS NULL OR abbreviation='', '', CONCAT(' (',abbreviation,')'))) AS label FROM $table ORDER BY name_ar", ARRAY_A);
        if ($relation==='areas') return $wpdb->get_results("SELECT code,CONCAT(COALESCE(area_ar,''), CASE WHEN area_ar IS NULL OR area_ar='' THEN COALESCE(city_ar,'') ELSE CONCAT(' — ',COALESCE(city_ar,'')) END) AS label FROM $table ORDER BY city_ar,area_ar", ARRAY_A);
        return [];
    }

    private function render_field($name,$meta,$value='') {
        $id='foh_'.$name; $required=!empty($meta['required'])?' required':''; $type=$meta['type'];
        echo '<label class="foh-field"><span>'.esc_html($meta['label']).'</span>';
        if ($type==='textarea') {
            echo '<textarea id="'.esc_attr($id).'" name="'.esc_attr($name).'" rows="3"'.$required.'>'.esc_textarea($value).'</textarea>';
        } elseif ($type==='select') {
            echo '<select id="'.esc_attr($id).'" name="'.esc_attr($name).'"'.$required.'>';
            foreach ($meta['options'] as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected($value?:($meta['default']??''),$k,false).'>'.esc_html($label).'</option>';
            echo '</select>';
        } elseif ($type==='relation') {
            echo '<select id="'.esc_attr($id).'" name="'.esc_attr($name).'"'.$required.'><option value="">— اختر —</option>';
            foreach ($this->relation_options($meta['relation']) as $o) echo '<option value="'.esc_attr($o['code']).'" '.selected($value,$o['code'],false).'>'.esc_html($o['label'].' ['.$o['code'].']').'</option>';
            echo '</select>';
        } else {
            $step=isset($meta['step'])?' step="'.esc_attr($meta['step']).'"':'';
            echo '<input id="'.esc_attr($id).'" type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value ?: ($meta['default']??'')).'"'.$step.$required.'>';
        }
        echo '</label>';
    }

    public function render_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $entity=sanitize_key($_GET['entity'] ?? 'laboratories'); if (!$this->entity_config($entity)) $entity='laboratories'; $cfg=$this->entity_config($entity);
        $edit_id=absint($_GET['edit'] ?? 0); $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables[$entity]} WHERE id=%d",$edit_id),ARRAY_A):[];
        $search=sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $where='1=1'; $args=[];
        if ($search) { $nf=$cfg['name_field']; $where="($nf LIKE %s OR code LIKE %s)"; $like='%'.$wpdb->esc_like($search).'%'; $args=[$like,$like]; }
        $sql="SELECT * FROM {$this->tables[$entity]} WHERE $where ORDER BY id DESC LIMIT 200"; if ($args) $sql=$wpdb->prepare($sql,$args);
        $rows=$wpdb->get_results($sql,ARRAY_A);
        $counts=[]; foreach ($this->tables as $k=>$table) $counts[$k]=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
        ?>
        <div class="wrap foh-wrap" dir="rtl">
            <div class="foh-hero"><div><h1>فحوصاتي — إدارة البيانات</h1><p>لوحة تشغيل موحدة لإدارة بيانات الـPilot وإنشاء السجلات الجديدة.</p></div><div class="foh-actions">
                <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fohosati_export_json'),'fohosati_export_json')); ?>">تصدير نسخة JSON</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fohosati_seed'),'fohosati_seed')); ?>">استيراد بيانات Pilot المفقودة</a>
            </div></div>
            <div class="foh-cards">
                <?php foreach (['laboratories'=>'المعامل','branches'=>'الفروع','tests'=>'الفحوصات','offers'=>'علاقات التوفر','areas'=>'المناطق'] as $k=>$label): ?>
                <a class="foh-card" href="<?php echo esc_url(add_query_arg(['page'=>'fohosati-admin','entity'=>$k],admin_url('admin.php'))); ?>"><strong><?php echo number_format_i18n($counts[$k]); ?></strong><span><?php echo esc_html($label); ?></span></a>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($_GET['msg'])): ?><div class="notice notice-success is-dismissible"><p>تم تنفيذ العملية بنجاح.</p></div><?php endif; ?>
            <nav class="foh-tabs">
                <?php foreach (['laboratories'=>'المعامل','branches'=>'الفروع','tests'=>'الفحوصات','offers'=>'توفر الفحوصات','areas'=>'المناطق'] as $k=>$label): ?>
                <a class="<?php echo $entity===$k?'active':''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>'fohosati-admin','entity'=>$k],admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="foh-layout">
                <section class="foh-panel foh-form-panel">
                    <div class="foh-panel-head"><h2><?php echo $edit_id?'تعديل':'إضافة'; ?> — <?php echo esc_html($cfg['title']); ?></h2><?php if($edit_id): ?><a href="<?php echo esc_url(add_query_arg(['page'=>'fohosati-admin','entity'=>$entity],admin_url('admin.php'))); ?>">إلغاء التعديل</a><?php endif; ?></div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="fohosati_save"><input type="hidden" name="entity" value="<?php echo esc_attr($entity); ?>"><input type="hidden" name="id" value="<?php echo (int)$edit_id; ?>">
                        <?php wp_nonce_field('fohosati_save'); ?>
                        <?php if($edit_id): ?><div class="foh-code">الكود: <strong><?php echo esc_html($edit['code']); ?></strong></div><?php endif; ?>
                        <div class="foh-form-grid"><?php foreach ($cfg['fields'] as $field=>$meta) $this->render_field($field,$meta,$edit[$field]??''); ?></div>
                        <p><button class="button button-primary button-hero" type="submit"><?php echo $edit_id?'حفظ التعديلات':'إنشاء السجل'; ?></button></p>
                    </form>
                </section>
                <section class="foh-panel foh-table-panel">
                    <div class="foh-panel-head"><h2>السجلات</h2><form class="foh-search" method="get"><input type="hidden" name="page" value="fohosati-admin"><input type="hidden" name="entity" value="<?php echo esc_attr($entity); ?>"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="بحث بالاسم أو الكود"><button class="button">بحث</button></form></div>
                    <div class="foh-table-scroll"><table class="widefat striped"><thead><tr><th>الكود</th><th>الاسم/المرجع</th><th>الحالة</th><th>إجراءات</th></tr></thead><tbody>
                    <?php if(!$rows): ?><tr><td colspan="4">لا توجد سجلات.</td></tr><?php endif; ?>
                    <?php foreach($rows as $row): $name=$row[$cfg['name_field']]??$row['code']; $status=$row['publication_status']??($row['record_status']??($row['availability_status']??'')); ?>
                    <tr><td><code><?php echo esc_html($row['code']); ?></code></td><td><?php echo esc_html($name); ?></td><td><span class="foh-status"><?php echo esc_html($status); ?></span></td><td><a href="<?php echo esc_url(add_query_arg(['page'=>'fohosati-admin','entity'=>$entity,'edit'=>$row['id']],admin_url('admin.php'))); ?>">تعديل</a> · <a class="foh-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fohosati_delete&entity='.$entity.'&id='.$row['id']),'fohosati_delete_'.$entity.'_'.$row['id'])); ?>">حذف</a></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                </section>
            </div>
            <p class="description">لإظهار البحث العام في أي صفحة استخدم الشورت كود: <code>[fohosati_search]</code></p>
        </div>
        <?php
    }

    private function public_areas() {
        global $wpdb;
        return $wpdb->get_results("SELECT code, city_ar, area_ar, area_en FROM {$this->tables['areas']} WHERE record_status='Active' ORDER BY city_ar, area_ar", ARRAY_A);
    }

    private function public_stats() {
        global $wpdb;
        return [
            'labs' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['laboratories']} WHERE publication_status='Published'"),
            'branches' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['branches']} WHERE publication_status='Published'"),
            'tests' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['tests']} WHERE record_status='Active'"),
        ];
    }

    private function phone_href($phone) {
        return preg_replace('/[^0-9+]/', '', (string)$phone);
    }

    private function whatsapp_href($phone) {
        $n = preg_replace('/\D+/', '', (string)$phone);
        if (!$n) return '';
        if (strpos($n, '00') === 0) $n = substr($n, 2);
        if (strpos($n, '0') === 0) $n = '249' . substr($n, 1);
        return 'https://wa.me/' . $n;
    }

    private function map_href($row) {
        if (!empty($row['latitude']) && !empty($row['longitude'])) {
            $lat = rawurlencode($row['latitude']); $lon = rawurlencode($row['longitude']);
            return "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lon}#map=17/{$lat}/{$lon}";
        }
        if (!empty($row['address'])) return 'https://www.openstreetmap.org/search?query=' . rawurlencode($row['address']);
        return '';
    }

    private function render_search_form($q='', $area='', $compact=false) {
        $areas = $this->public_areas();
        $action = $this->public_page_url('search');
        ob_start(); ?>
        <form class="foh-search-box <?php echo $compact?'is-compact':''; ?>" method="get" action="<?php echo esc_url($action); ?>">
            <div class="foh-search-input"><span class="foh-icon">⌕</span><input type="search" name="foh_q" value="<?php echo esc_attr($q); ?>" placeholder="اكتب اسم الفحص أو الاختصار مثل CBC" aria-label="اسم الفحص"></div>
            <select name="foh_area" aria-label="المنطقة"><option value="">كل المناطق</option><?php foreach($areas as $a): ?><option value="<?php echo esc_attr($a['code']); ?>" <?php selected($area,$a['code']); ?>><?php echo esc_html($a['area_ar'] ?: $a['city_ar']); ?></option><?php endforeach; ?></select>
            <button type="submit">ابحث الآن</button>
        </form>
        <?php return ob_get_clean();
    }

    private function result_card($r, $show_test=true) {
        $detail = $this->public_page_url('search', ['foh_branch'=>$r['branch_code']]);
        $phone = !empty($r['phone']) ? $this->phone_href($r['phone']) : '';
        $wa = !empty($r['whatsapp']) ? $this->whatsapp_href($r['whatsapp']) : '';
        $map = $this->map_href($r);
        ob_start(); ?>
        <article class="foh-lab-card">
            <div class="foh-card-top">
                <div><span class="foh-verified">✓ بيانات متحققة</span><h3><a href="<?php echo esc_url($detail); ?>"><?php echo esc_html($r['branch_name_ar']); ?></a></h3><?php if(!empty($r['laboratory_name_ar']) && $r['laboratory_name_ar'] !== $r['branch_name_ar']): ?><p class="foh-muted"><?php echo esc_html($r['laboratory_name_ar']); ?></p><?php endif; ?></div>
                <?php if($show_test && !empty($r['test_name_ar'])): ?><span class="foh-test-pill"><?php echo esc_html($r['test_name_ar']); ?><?php echo !empty($r['abbreviation'])?' · '.esc_html($r['abbreviation']):''; ?></span><?php endif; ?>
            </div>
            <div class="foh-meta">
                <?php if(!empty($r['area_ar'])): ?><span>⌖ <?php echo esc_html($r['area_ar']); ?></span><?php endif; ?>
                <?php if(!empty($r['address'])): ?><span>العنوان: <?php echo esc_html($r['address']); ?></span><?php endif; ?>
                <?php if(!empty($r['opening_hours'])): ?><span>◷ <?php echo esc_html($r['opening_hours']); ?></span><?php endif; ?>
                <?php if($show_test && !empty($r['availability_verified_at'])): ?><span>آخر تحقق: <?php echo esc_html($r['availability_verified_at']); ?></span><?php endif; ?>
            </div>
            <?php if($show_test && isset($r['price']) && $r['price'] !== null && $r['price'] !== ''): ?><div class="foh-price"><?php echo esc_html(number_format_i18n((float)$r['price'],2)); ?> <small><?php echo esc_html($r['currency']); ?></small></div><?php endif; ?>
            <div class="foh-card-actions">
                <a class="foh-btn primary" href="<?php echo esc_url($detail); ?>">عرض التفاصيل</a>
                <?php if($phone): ?><a class="foh-btn" href="tel:<?php echo esc_attr($phone); ?>">اتصال</a><?php endif; ?>
                <?php if($wa): ?><a class="foh-btn whatsapp" target="_blank" rel="noopener" href="<?php echo esc_url($wa); ?>">واتساب</a><?php endif; ?>
                <?php if($map): ?><a class="foh-btn" target="_blank" rel="noopener" href="<?php echo esc_url($map); ?>">الخريطة</a><?php endif; ?>
            </div>
        </article>
        <?php return ob_get_clean();
    }

    public function shortcode_home($atts=[]) {
        global $wpdb;
        $stats = $this->public_stats();
        $popular = $wpdb->get_results("SELECT t.code,t.name_ar,t.name_en,t.abbreviation,COUNT(o.id) AS offers_count FROM {$this->tables['tests']} t LEFT JOIN {$this->tables['offers']} o ON o.test_code=t.code AND o.availability_status='Confirmed Available' WHERE t.record_status='Active' GROUP BY t.code,t.name_ar,t.name_en,t.abbreviation ORDER BY offers_count DESC,t.id ASC LIMIT 8", ARRAY_A);
        $latest = $wpdb->get_results("SELECT b.code AS branch_code,b.branch_name_ar,b.address,b.phone,b.whatsapp,b.opening_hours,b.latitude,b.longitude,l.name_ar AS laboratory_name_ar,a.area_ar FROM {$this->tables['branches']} b LEFT JOIN {$this->tables['laboratories']} l ON l.code=b.laboratory_code LEFT JOIN {$this->tables['areas']} a ON a.code=b.area_code WHERE b.publication_status='Published' ORDER BY b.id DESC LIMIT 6", ARRAY_A);
        ob_start(); ?>
        <div class="foh-site" dir="rtl">
            <section class="foh-hero-public">
                <div class="foh-container">
                    <span class="foh-eyebrow">دليل المعامل الطبية في الخرطوم</span>
                    <h1>اعثر على المعمل الذي يوفر <em>فحصك</em> بسرعة</h1>
                    <p>ابحث باسم الفحص أو اختصاره، اختر منطقتك، ثم تواصل مباشرة مع الفرع.</p>
                    <?php echo $this->render_search_form(); ?>
                    <div class="foh-trust-row"><span>✓ بيانات Pilot متحققة</span><span>✓ بحث عربي وإنجليزي</span><span>✓ اتصال وواتساب وخريطة</span></div>
                </div>
            </section>
            <section class="foh-section">
                <div class="foh-container">
                    <div class="foh-stats"><div><strong><?php echo number_format_i18n($stats['branches']); ?>+</strong><span>فرع معمل</span></div><div><strong><?php echo number_format_i18n($stats['tests']); ?>+</strong><span>فحص قابل للبحث</span></div><div><strong><?php echo number_format_i18n($stats['labs']); ?>+</strong><span>معمل في الدليل</span></div></div>
                </div>
            </section>
            <section class="foh-section alt">
                <div class="foh-container">
                    <div class="foh-section-head"><div><span class="foh-eyebrow">خدمات فحوصاتي</span><h2>كل ما تحتاجه قبل زيارة المعمل</h2></div></div>
                    <div class="foh-feature-grid">
                        <article class="foh-feature-card"><span>🔎</span><h3>بحث ذكي للفحوصات</h3><p>ابحث بالعربية أو الإنجليزية أو بالاختصار للوصول لنتائج دقيقة بسرعة.</p></article>
                        <article class="foh-feature-card"><span>📍</span><h3>فروع قريبة منك</h3><p>فلترة حسب المنطقة مع روابط خرائط مباشرة لتسهيل الوصول.</p></article>
                        <article class="foh-feature-card"><span>💬</span><h3>تواصل فوري</h3><p>اتصال أو واتساب مباشرة مع الفرع للاستفسار عن التوفر والسعر.</p></article>
                        <article class="foh-feature-card"><span>🛡️</span><h3>بيانات موثقة</h3><p>عرض بيانات مراجعة للتأكد من موثوقية معلومات التواصل الأساسية.</p></article>
                    </div>
                </div>
            </section>
            <section class="foh-section">
                <div class="foh-container">
                    <div class="foh-section-head"><div><span class="foh-eyebrow">ابدأ من الفحص</span><h2>فحوصات شائعة</h2></div><a href="<?php echo esc_url($this->public_page_url('search')); ?>">عرض البحث الكامل ←</a></div>
                    <div class="foh-test-grid"><?php foreach($popular as $t): ?><a class="foh-test-tile" href="<?php echo esc_url($this->public_page_url('search',['foh_q'=>$t['abbreviation']?:$t['name_ar']])); ?>"><strong><?php echo esc_html($t['name_ar']); ?></strong><span><?php echo esc_html($t['abbreviation'] ?: $t['name_en']); ?></span><small><?php echo number_format_i18n((int)$t['offers_count']); ?> فرع</small></a><?php endforeach; ?></div>
                </div>
            </section>
            <section class="foh-section alt">
                <div class="foh-container">
                    <div class="foh-section-head"><div><span class="foh-eyebrow">كيف تستخدم المنصة؟</span><h2>3 خطوات بسيطة</h2></div></div>
                    <div class="foh-steps">
                        <div class="foh-step"><strong>1</strong><h3>ابحث عن الفحص</h3><p>اكتب اسم الفحص أو اختصاره وحدد منطقتك.</p></div>
                        <div class="foh-step"><strong>2</strong><h3>قارن النتائج</h3><p>راجع الفروع المتاحة ومعلومات التواصل وساعات العمل.</p></div>
                        <div class="foh-step"><strong>3</strong><h3>تواصل مباشرة</h3><p>اتصل أو راسل واتساب لتأكيد التوفر والسعر قبل الزيارة.</p></div>
                    </div>
                </div>
            </section>
            <section class="foh-section">
                <div class="foh-container">
                    <div class="foh-section-head"><div><span class="foh-eyebrow">الدليل</span><h2>معامل وفروع مضافة</h2></div><a href="<?php echo esc_url($this->public_page_url('labs')); ?>">كل المعامل ←</a></div>
                    <div class="foh-card-grid"><?php foreach($latest as $r) echo $this->result_card($r,false); ?></div>
                </div>
            </section>
            <section class="foh-section alt">
                <div class="foh-container">
                    <div class="foh-section-head"><div><span class="foh-eyebrow">الأسئلة الشائعة</span><h2>FAQ</h2></div></div>
                    <div class="foh-faq">
                        <details class="foh-faq-item"><summary>هل فحوصاتي يقدم تشخيصًا طبيًا؟</summary><p>لا. فحوصاتي دليل معلومات واتصال فقط، ولا يقدم تشخيصًا أو علاجًا.</p></details>
                        <details class="foh-faq-item"><summary>هل الأسعار المعروضة نهائية؟</summary><p>الأسعار إرشادية بناءً على آخر تحديث. يُنصح دائمًا بالتأكيد المباشر مع المعمل قبل الزيارة.</p></details>
                        <details class="foh-faq-item"><summary>كيف أعرف أن الفحص متوفر؟</summary><p>نتائج البحث تعرض الفروع المتاحة، لكن الأفضل الاتصال أو واتساب لتأكيد التوفر الفوري.</p></details>
                        <details class="foh-faq-item"><summary>هل يمكن اقتراح إضافة معمل أو تحديث بيانات؟</summary><p>نعم، يمكن تحديث وإدارة البيانات عبر لوحة إدارة المنصة بشكل مستمر.</p></details>
                    </div>
                </div>
            </section>
            <section class="foh-section foh-disclaimer"><div class="foh-container"><strong>تنبيه مهم</strong><p>فحوصاتي دليل معلومات واتصال، وليس جهة تشخيص أو علاج. تأكد من المعمل مباشرة من توفر الفحص والسعر قبل الزيارة.</p></div></section>
        </div><?php return ob_get_clean();
    }

    public function shortcode_search($atts=[]) {
        global $wpdb;
        $branch = sanitize_text_field(wp_unslash($_GET['foh_branch'] ?? ''));
        if ($branch) return $this->render_branch_detail($branch);
        $q=sanitize_text_field(wp_unslash($_GET['foh_q']??''));
        $area=sanitize_text_field(wp_unslash($_GET['foh_area']??''));
        $results=[];
        if($q){
            $like='%'.$wpdb->esc_like($q).'%';
            $params=[$like,$like,$like,$like];
            $area_sql='';
            if($area){$area_sql=' AND b.area_code=%s';$params[]=$area;}
            $sql="SELECT DISTINCT b.code AS branch_code,b.branch_name_ar,b.address,b.phone,b.whatsapp,b.opening_hours,b.latitude,b.longitude,l.name_ar AS laboratory_name_ar,a.area_ar,t.name_ar AS test_name_ar,t.abbreviation,o.price,o.currency,o.availability_verified_at
                FROM {$this->tables['offers']} o
                JOIN {$this->tables['branches']} b ON b.code=o.branch_code
                JOIN {$this->tables['tests']} t ON t.code=o.test_code
                LEFT JOIN {$this->tables['laboratories']} l ON l.code=b.laboratory_code
                LEFT JOIN {$this->tables['areas']} a ON a.code=b.area_code
                WHERE b.publication_status='Published' AND o.availability_status='Confirmed Available'
                AND (t.name_ar LIKE %s OR t.name_en LIKE %s OR t.abbreviation LIKE %s OR t.synonyms LIKE %s) $area_sql
                ORDER BY b.branch_name_ar LIMIT 100";
            $results=$wpdb->get_results($wpdb->prepare($sql,$params),ARRAY_A);
        }
        ob_start(); ?>
        <div class="foh-site foh-search-page" dir="rtl"><section class="foh-page-head"><div class="foh-container"><span class="foh-eyebrow">ابحث في فحوصاتي</span><h1>البحث عن فحص</h1><p>اكتب اسم الفحص بالعربية أو الإنجليزية أو الاختصار، ثم اختر المنطقة.</p><?php echo $this->render_search_form($q,$area,true); ?></div></section><section class="foh-section"><div class="foh-container">
        <?php if($q): ?><div class="foh-results-head"><h2>نتائج البحث</h2><span><?php echo number_format_i18n(count($results)); ?> نتيجة</span></div><?php if($results): ?><div class="foh-card-grid"><?php foreach($results as $r) echo $this->result_card($r,true); ?></div><?php else: ?><div class="foh-empty"><strong>لم نجد نتائج مطابقة حتى الآن</strong><p>جرّب الاختصار الإنجليزي أو غيّر المنطقة. قاعدة البيانات قابلة للتحديث فورًا من لوحة الإدارة.</p><a class="foh-btn primary" href="<?php echo esc_url($this->public_page_url('labs')); ?>">استعرض دليل المعامل</a></div><?php endif; ?>
        <?php else: ?><div class="foh-empty"><strong>ابدأ بكتابة اسم الفحص</strong><p>مثال: CBC، صورة دم كاملة، Vitamin D، TSH.</p></div><?php endif; ?>
        </div></section></div><?php return ob_get_clean();
    }

    public function shortcode_labs($atts=[]) {
        global $wpdb;
        $q=sanitize_text_field(wp_unslash($_GET['foh_lab_q']??''));
        $area=sanitize_text_field(wp_unslash($_GET['foh_area']??''));
        $where="b.publication_status='Published'"; $params=[];
        if($q){$like='%'.$wpdb->esc_like($q).'%';$where.=" AND (b.branch_name_ar LIKE %s OR b.branch_name_en LIKE %s OR l.name_ar LIKE %s OR l.name_en LIKE %s)";$params=array_merge($params,[$like,$like,$like,$like]);}
        if($area){$where.=" AND b.area_code=%s";$params[]=$area;}
        $sql="SELECT b.code AS branch_code,b.branch_name_ar,b.address,b.phone,b.whatsapp,b.opening_hours,b.latitude,b.longitude,l.name_ar AS laboratory_name_ar,a.area_ar,(SELECT COUNT(*) FROM {$this->tables['offers']} o WHERE o.branch_code=b.code AND o.availability_status='Confirmed Available') AS test_count FROM {$this->tables['branches']} b LEFT JOIN {$this->tables['laboratories']} l ON l.code=b.laboratory_code LEFT JOIN {$this->tables['areas']} a ON a.code=b.area_code WHERE $where ORDER BY b.branch_name_ar LIMIT 150";
        if($params)$sql=$wpdb->prepare($sql,$params);
        $rows=$wpdb->get_results($sql,ARRAY_A); $areas=$this->public_areas();
        ob_start(); ?>
        <div class="foh-site" dir="rtl"><section class="foh-page-head"><div class="foh-container"><span class="foh-eyebrow">الدليل</span><h1>دليل المعامل</h1><p>استعرض الفروع المنشورة وتواصل معها مباشرة.</p><form class="foh-directory-filter" method="get"><input type="search" name="foh_lab_q" value="<?php echo esc_attr($q); ?>" placeholder="ابحث باسم المعمل أو الفرع"><select name="foh_area"><option value="">كل المناطق</option><?php foreach($areas as $a): ?><option value="<?php echo esc_attr($a['code']); ?>" <?php selected($area,$a['code']); ?>><?php echo esc_html($a['area_ar'] ?: $a['city_ar']); ?></option><?php endforeach; ?></select><button type="submit">تصفية</button></form></div></section><section class="foh-section"><div class="foh-container"><div class="foh-results-head"><h2>الفروع</h2><span><?php echo number_format_i18n(count($rows)); ?> فرع</span></div><div class="foh-card-grid"><?php foreach($rows as $r) echo $this->result_card($r,false); ?></div><?php if(!$rows): ?><div class="foh-empty"><strong>لا توجد فروع مطابقة</strong></div><?php endif; ?></div></section></div><?php return ob_get_clean();
    }

    public function shortcode_branch($atts=[]) {
        $atts=shortcode_atts(['code'=>''],$atts,'fohosati_branch');
        $code=$atts['code'] ?: sanitize_text_field(wp_unslash($_GET['foh_branch']??''));
        return $this->render_branch_detail($code);
    }

    private function render_branch_detail($code) {
        global $wpdb;
        $branch=$wpdb->get_row($wpdb->prepare("SELECT b.*,l.name_ar AS laboratory_name_ar,l.name_en AS laboratory_name_en,a.area_ar,a.city_ar FROM {$this->tables['branches']} b LEFT JOIN {$this->tables['laboratories']} l ON l.code=b.laboratory_code LEFT JOIN {$this->tables['areas']} a ON a.code=b.area_code WHERE b.code=%s AND b.publication_status='Published'",$code),ARRAY_A);
        if(!$branch) return '<div class="foh-site" dir="rtl"><div class="foh-container"><div class="foh-empty"><strong>الفرع غير موجود أو غير منشور.</strong></div></div></div>';
        $offers=$wpdb->get_results($wpdb->prepare("SELECT o.*,t.name_ar,t.name_en,t.abbreviation,t.category FROM {$this->tables['offers']} o JOIN {$this->tables['tests']} t ON t.code=o.test_code WHERE o.branch_code=%s AND o.availability_status='Confirmed Available' AND t.record_status='Active' ORDER BY t.category,t.name_ar",$code),ARRAY_A);
        $phone=$this->phone_href($branch['phone']);$wa=$this->whatsapp_href($branch['whatsapp']);$map=$this->map_href($branch);
        ob_start(); ?>
        <div class="foh-site" dir="rtl"><section class="foh-branch-hero"><div class="foh-container"><a class="foh-back" href="<?php echo esc_url($this->public_page_url('labs')); ?>">← العودة إلى دليل المعامل</a><span class="foh-verified">✓ بيانات متحققة</span><h1><?php echo esc_html($branch['branch_name_ar']); ?></h1><?php if($branch['laboratory_name_ar'] && $branch['laboratory_name_ar']!==$branch['branch_name_ar']): ?><p class="foh-branch-parent"><?php echo esc_html($branch['laboratory_name_ar']); ?></p><?php endif; ?><div class="foh-card-actions large"><?php if($phone): ?><a class="foh-btn primary" href="tel:<?php echo esc_attr($phone); ?>">اتصال بالفرع</a><?php endif; ?><?php if($wa): ?><a class="foh-btn whatsapp" target="_blank" rel="noopener" href="<?php echo esc_url($wa); ?>">واتساب</a><?php endif; ?><?php if($map): ?><a class="foh-btn" target="_blank" rel="noopener" href="<?php echo esc_url($map); ?>">فتح الخريطة</a><?php endif; ?></div></div></section>
        <section class="foh-section"><div class="foh-container foh-detail-layout"><main><div class="foh-detail-panel"><h2>الفحوصات المتاحة</h2><?php if($offers): ?><div class="foh-offer-list"><?php foreach($offers as $o): ?><div class="foh-offer-row"><div><strong><?php echo esc_html($o['name_ar']); ?></strong><span><?php echo esc_html(trim(($o['abbreviation']?:'').' '.($o['name_en']?:''))); ?></span></div><div><?php if($o['price']!==null && $o['price']!==''): ?><strong><?php echo esc_html(number_format_i18n((float)$o['price'],2).' '.$o['currency']); ?></strong><?php else: ?><span class="foh-muted">السعر عند التواصل</span><?php endif; ?><?php if($o['availability_verified_at']): ?><small>تم التحقق: <?php echo esc_html($o['availability_verified_at']); ?></small><?php endif; ?></div></div><?php endforeach; ?></div><?php else: ?><p>لم تُدخل قائمة فحوصات لهذا الفرع بعد.</p><?php endif; ?></div></main><aside><div class="foh-detail-panel"><h2>بيانات الفرع</h2><dl><?php if($branch['area_ar']): ?><dt>المنطقة</dt><dd><?php echo esc_html($branch['area_ar']); ?></dd><?php endif; ?><?php if($branch['address']): ?><dt>العنوان</dt><dd><?php echo esc_html($branch['address']); ?></dd><?php endif; ?><?php if($branch['phone']): ?><dt>الهاتف</dt><dd><?php echo esc_html($branch['phone']); ?></dd><?php endif; ?><?php if($branch['whatsapp']): ?><dt>واتساب</dt><dd><?php echo esc_html($branch['whatsapp']); ?></dd><?php endif; ?><?php if($branch['opening_hours']): ?><dt>ساعات العمل</dt><dd><?php echo esc_html($branch['opening_hours']); ?></dd><?php endif; ?><?php if($branch['last_general_review']): ?><dt>آخر مراجعة</dt><dd><?php echo esc_html($branch['last_general_review']); ?></dd><?php endif; ?></dl></div><div class="foh-note">يرجى تأكيد توفر الفحص والسعر مع المعمل قبل الزيارة.</div></aside></div></section></div><?php return ob_get_clean();
    }

}

register_activation_hook(__FILE__, ['Fohosati_Admin_Plugin','activate']);
Fohosati_Admin_Plugin::instance();
