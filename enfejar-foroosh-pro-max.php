<?php
/*
Plugin Name: انفجار فروش PRO MAX
Description: دستیار حرفه‌ای افزایش فروش ووکامرس با Heatmap، سفر مشتری، تحلیل کانال ورودی و پیشنهادهای هوشمند عملی
Version: 1.0
Author: Enfejar Foroosh
Text Domain: enfejar-foroosh
*/

if (!defined('ABSPATH')) exit;

define('EFPX_VERSION', '1.0');
define('EFPX_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, 'efpx_install');
function efpx_install(){
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$wpdb->prefix}efpx_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(100) NOT NULL,
        page_url TEXT NULL,
        page_title TEXT NULL,
        referrer TEXT NULL,
        source_type VARCHAR(30) DEFAULT 'direct',
        device VARCHAR(20) DEFAULT 'desktop',
        event_type VARCHAR(40) NOT NULL,
        element_text VARCHAR(255) NULL,
        element_tag VARCHAR(60) NULL,
        x INT DEFAULT 0,
        y INT DEFAULT 0,
        scroll_y INT DEFAULT 0,
        duration_sec INT DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY(id),
        KEY session_id(session_id),
        KEY source_type(source_type),
        KEY device(device),
        KEY event_type(event_type),
        KEY created_at(created_at)
    ) $charset;");
}

add_action('wp_enqueue_scripts', 'efpx_front_assets');
function efpx_front_assets(){
    if (is_admin()) return;

    wp_enqueue_script('efpx-tracker', EFPX_URL.'assets/tracker.js', [], EFPX_VERSION, true);
    wp_localize_script('efpx-tracker', 'EFPX', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('efpx_nonce'),
        'sampleRate' => 45
    ]);

    wp_enqueue_style('efpx-front', EFPX_URL.'assets/front.css', [], EFPX_VERSION);
}

add_action('wp_ajax_efpx_track', 'efpx_track');
add_action('wp_ajax_nopriv_efpx_track', 'efpx_track');

function efpx_track(){
    check_ajax_referer('efpx_nonce','nonce');

    global $wpdb;

    $url = esc_url_raw($_POST['url'] ?? '');
    $ref = esc_url_raw($_POST['ref'] ?? '');
    $source = efpx_detect_source($ref, $url);

    $wpdb->insert($wpdb->prefix.'efpx_events', [
        'session_id' => sanitize_text_field($_POST['sid'] ?? ''),
        'page_url' => $url,
        'page_title' => sanitize_text_field($_POST['title'] ?? ''),
        'referrer' => $ref,
        'source_type' => $source,
        'device' => sanitize_text_field($_POST['device'] ?? 'desktop'),
        'event_type' => sanitize_text_field($_POST['type'] ?? ''),
        'element_text' => sanitize_text_field($_POST['text'] ?? ''),
        'element_tag' => sanitize_text_field($_POST['tag'] ?? ''),
        'x' => intval($_POST['x'] ?? 0),
        'y' => intval($_POST['y'] ?? 0),
        'scroll_y' => intval($_POST['scroll'] ?? 0),
        'duration_sec' => intval($_POST['duration'] ?? 0),
        'created_at' => current_time('mysql')
    ]);

    wp_send_json_success();
}

function efpx_detect_source($ref, $url=''){
    $u = strtolower($url);
    $r = strtolower($ref);

    if (strpos($u,'utm_medium=cpc') !== false || strpos($u,'gclid=') !== false || strpos($u,'utm_source=googleads') !== false) return 'paid';
    if (!$r) return 'direct';

    $host = parse_url($r, PHP_URL_HOST);
    if (!$host) return 'direct';

    foreach(['google.','bing.','yahoo.','duckduckgo.','yandex.'] as $x){
        if (strpos($host,$x) !== false) return 'organic';
    }

    foreach(['instagram.','telegram.','t.co','twitter.','x.com','facebook.','linkedin.','youtube.','whatsapp.','pinterest.'] as $x){
        if (strpos($host,$x) !== false) return 'social';
    }

    return 'referral';
}

function efpx_source_label($s){
    $map = [
        'organic'=>'ورودی ارگانیک',
        'direct'=>'ورودی مستقیم',
        'social'=>'شبکه اجتماعی',
        'paid'=>'تبلیغاتی',
        'referral'=>'ارجاعی'
    ];
    return $map[$s] ?? $s;
}

add_action('admin_menu', 'efpx_menu');
function efpx_menu(){
    add_menu_page('انفجار فروش','انفجار فروش','manage_options','efpx-dashboard','efpx_dashboard','dashicons-chart-area',56);
    add_submenu_page('efpx-dashboard','داشبورد','داشبورد','manage_options','efpx-dashboard','efpx_dashboard');
    add_submenu_page('efpx-dashboard','Heatmap','Heatmap','manage_options','efpx-heatmap','efpx_heatmap');
    add_submenu_page('efpx-dashboard','سفر مشتری','سفر مشتری','manage_options','efpx-journey','efpx_journey');
    add_submenu_page('efpx-dashboard','کانال‌های ورودی','کانال‌های ورودی','manage_options','efpx-sources','efpx_sources');
    add_submenu_page('efpx-dashboard','پیشنهاد هوشمند','پیشنهاد هوشمند','manage_options','efpx-suggestions','efpx_suggestions');
    add_submenu_page('efpx-dashboard','گزارش مدیریتی','گزارش مدیریتی','manage_options','efpx-report','efpx_report');
}

add_action('admin_enqueue_scripts', function($hook){
    if (strpos($hook,'efpx') === false) return;
    wp_enqueue_style('efpx-admin', EFPX_URL.'assets/admin.css', [], EFPX_VERSION);
});

function efpx_head($title){
    echo '<div class="wrap efpx-wrap" dir="rtl"><h1>'.esc_html($title).'</h1>';
}
function efpx_foot(){ echo '</div>'; }
function efpx_short_url($url){
    $p = parse_url($url);
    return $p && !empty($p['path']) ? $p['path'] : ($url ?: '-');
}

function efpx_metrics(){
    global $wpdb;
    $t = $wpdb->prefix.'efpx_events';

    return [
        'sessions' => intval($wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $t")),
        'clicks' => intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE event_type='click'")),
        'views' => intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE event_type='view'")),
        'exits' => intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE event_type='exit'")),
        'mobile' => intval($wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $t WHERE device='mobile'")),
        'desktop' => intval($wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $t WHERE device='desktop'")),
        'tablet' => intval($wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $t WHERE device='tablet'")),
        'organic' => intval($wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $t WHERE source_type='organic'")),
        'social' => intval($wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM $t WHERE source_type='social'")),
        'cta_clicks' => intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE event_type='click' AND (element_text LIKE '%خرید%' OR element_text LIKE '%سبد%' OR element_text LIKE '%سفارش%')")),
        'image_clicks' => intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE event_type='click' AND (element_tag='IMG' OR element_text='تصویر محصول')")),
        'avg_duration' => floatval($wpdb->get_var("SELECT AVG(duration_sec) FROM $t WHERE duration_sec > 0")),
    ];
}

function efpx_dashboard(){
    $m = efpx_metrics();
    efpx_head('داشبورد حرفه‌ای انفجار فروش');

    echo '<div class="efpx-hero"><h2>مرکز تحلیل فروش و رفتار کاربران</h2><p>ببین کاربران از کجا آمده‌اند، چه چیزی دیده‌اند، روی چه بخش‌هایی کلیک کرده‌اند و برای افزایش فروش چه کاری باید انجام دهی.</p></div>';

    echo '<div class="efpx-grid">';
    efpx_card('کل کاربران',$m['sessions'],'نشست‌های ثبت‌شده');
    efpx_card('کلیک‌ها',$m['clicks'],'تعامل‌های مهم');
    efpx_card('ورودی ارگانیک',$m['organic'],'کاربران آمده از گوگل');
    efpx_card('میانگین حضور',round($m['avg_duration']).' ثانیه','زمان تقریبی حضور');
    echo '</div>';

    echo '<div class="efpx-grid">';
    efpx_card('موبایل',$m['mobile'],'کاربران موبایل');
    efpx_card('دسکتاپ',$m['desktop'],'کاربران کامپیوتر');
    efpx_card('کلیک CTA',$m['cta_clicks'],'خرید / سبد / سفارش');
    efpx_card('کلیک تصویر',$m['image_clicks'],'نیاز به جزئیات محصول');
    echo '</div>';

    echo '<div class="efpx-box"><h2>تحلیل سریع امروز</h2>';
    efpx_render_smart_tips($m, 3);
    echo '</div>';

    efpx_foot();
}

function efpx_card($title,$value,$desc){
    echo '<div class="efpx-card"><strong>'.esc_html($title).'</strong><span>'.esc_html($value).'</span><small>'.esc_html($desc).'</small></div>';
}

function efpx_heatmap(){
    global $wpdb;
    $t=$wpdb->prefix.'efpx_events';

    efpx_head('Heatmap و تحلیل کلیک‌ها');
    echo '<div class="efpx-help"><strong>این بخش چه کمکی می‌کند؟</strong><p>اینجا مشخص می‌شود کاربران روی چه عنصرهایی کلیک کرده‌اند؛ دکمه خرید، تصویر، لینک، منو یا بخش‌های بدون متن.</p></div>';

    $rows = $wpdb->get_results("SELECT element_text, element_tag, COUNT(*) c FROM $t WHERE event_type='click' GROUP BY element_text, element_tag ORDER BY c DESC LIMIT 40");

    echo '<div class="efpx-box"><table class="widefat striped"><tr><th>عنصر کلیک‌شده</th><th>نوع</th><th>کلیک</th><th>تحلیل</th><th>اقدام پیشنهادی</th></tr>';

    foreach($rows as $r){
        $text = $r->element_text ?: 'بدون متن / بخش تصویری';
        $analysis = 'تعامل معمولی';
        $action = 'این بخش را در کنار نرخ فروش بررسی کن.';

        if (stripos($text,'خرید') !== false || stripos($text,'سبد') !== false || stripos($text,'سفارش') !== false){
            $analysis = 'CTA دیده شده و کاربر قصد خرید نشان داده است.';
            $action = 'اگر خرید نهایی کم است، مشکل احتمالاً در قیمت، اعتماد یا مرحله پرداخت است.';
        } elseif ($r->element_tag === 'IMG' || $text === 'تصویر محصول'){
            $analysis = 'کاربر دنبال جزئیات تصویری بیشتر است.';
            $action = 'گالری، زوم تصویر، ویدیو محصول یا تصویر کاربرد محصول اضافه کن.';
        } elseif ($text === 'بدون متن / بخش تصویری'){
            $analysis = 'کاربر روی بخشی کلیک کرده که شاید انتظار تعامل داشته است.';
            $action = 'بررسی کن این بخش باید لینک، دکمه یا توضیح بیشتر داشته باشد یا نه.';
        }

        echo '<tr><td>'.esc_html($text).'</td><td>'.esc_html($r->element_tag ?: '-').'</td><td>'.intval($r->c).'</td><td>'.esc_html($analysis).'</td><td>'.esc_html($action).'</td></tr>';
    }

    echo '</table></div>';
    efpx_foot();
}

function efpx_journey(){
    global $wpdb;
    $t=$wpdb->prefix.'efpx_events';

    efpx_head('سفر مشتری');
    echo '<div class="efpx-help"><strong>این بخش چه می‌گوید؟</strong><p>مسیر کاربر را از ورود تا خروج نشان می‌دهد: از چه کانالی آمده، اولین و آخرین صفحه چه بوده، با چه دستگاهی وارد شده و چقدر مانده است.</p></div>';

    $rows=$wpdb->get_results("SELECT session_id, MAX(source_type) source_type, MAX(referrer) referrer, MAX(device) device, MIN(page_url) first_page, MAX(page_url) last_page, MAX(duration_sec) duration, COUNT(*) interactions FROM $t GROUP BY session_id ORDER BY MAX(id) DESC LIMIT 100");

    echo '<div class="efpx-box"><table class="widefat striped"><tr><th>نوع ورود</th><th>ورود از</th><th>اولین صفحه</th><th>خروج/آخرین صفحه</th><th>دستگاه</th><th>مدت</th><th>تعامل</th><th>تحلیل</th></tr>';

    foreach($rows as $r){
        $ref = $r->referrer ? (parse_url($r->referrer, PHP_URL_HOST) ?: $r->referrer) : 'مستقیم';
        $analysis = 'رفتار معمولی';
        if (intval($r->duration) < 10) $analysis = 'خروج سریع؛ بخش اول صفحه باید قوی‌تر شود.';
        elseif (intval($r->interactions) > 3) $analysis = 'تعامل خوب؛ اگر خرید نشده، پیشنهاد فروش یا اعتمادسازی ضعیف است.';

        echo '<tr><td>'.esc_html(efpx_source_label($r->source_type)).'</td><td>'.esc_html($ref).'</td><td>'.esc_html(efpx_short_url($r->first_page)).'</td><td>'.esc_html(efpx_short_url($r->last_page)).'</td><td>'.esc_html($r->device).'</td><td>'.intval($r->duration).' ثانیه</td><td>'.intval($r->interactions).'</td><td>'.esc_html($analysis).'</td></tr>';
    }

    echo '</table></div>';
    efpx_foot();
}

function efpx_sources(){
    global $wpdb;
    $t=$wpdb->prefix.'efpx_events';

    efpx_head('کانال‌های ورودی');
    echo '<div class="efpx-help"><strong>ورودی ارگانیک یعنی چه؟</strong><p>وقتی کاربر از گوگل یا موتورهای جستجو وارد شود، ورودی ارگانیک محسوب می‌شود. این کاربران معمولاً نیت جستجو دارند و باید سریع به پاسخ، اعتماد و دکمه خرید برسند.</p></div>';

    $rows=$wpdb->get_results("SELECT source_type, COUNT(DISTINCT session_id) sessions, COUNT(*) events, AVG(duration_sec) avg_time FROM $t GROUP BY source_type ORDER BY sessions DESC");

    echo '<div class="efpx-box"><table class="widefat striped"><tr><th>کانال</th><th>کاربران</th><th>تعامل</th><th>میانگین حضور</th><th>تحلیل</th><th>اقدام پیشنهادی</th></tr>';

    foreach($rows as $r){
        $analysis='رفتار عادی';
        $action='گزارش را با صفحات خروج بررسی کن.';

        if($r->source_type==='organic'){
            $analysis='کاربر با جستجو وارد شده و احتمالاً دنبال پاسخ یا محصول مشخص است.';
            $action='تیتر، توضیح کوتاه، مزیت اصلی و CTA را بالای صفحه قرار بده.';
        } elseif($r->source_type==='social'){
            $analysis='کاربر شبکه اجتماعی عجول‌تر و تصویری‌تر است.';
            $action='لندینگ کوتاه، تصویر قوی و پیشنهاد سریع بساز.';
        } elseif($r->source_type==='direct'){
            $analysis='کاربر برند یا آدرس سایت را می‌شناسد.';
            $action='اعتمادسازی و پیشنهاد ویژه برای خرید سریع‌تر نمایش بده.';
        } elseif($r->source_type==='paid'){
            $analysis='برای جذب این کاربر هزینه شده است.';
            $action='صفحه فرود را بدون حواس‌پرتی و با CTA مستقیم طراحی کن.';
        }

        echo '<tr><td>'.esc_html(efpx_source_label($r->source_type)).'</td><td>'.intval($r->sessions).'</td><td>'.intval($r->events).'</td><td>'.round(floatval($r->avg_time)).' ثانیه</td><td>'.esc_html($analysis).'</td><td>'.esc_html($action).'</td></tr>';
    }

    echo '</table></div>';
    efpx_foot();
}

function efpx_suggestions(){
    $m=efpx_metrics();
    efpx_head('پیشنهاد هوشمند افزایش فروش');

    echo '<div class="efpx-help"><strong>این بخش مهم‌ترین قسمت افزونه است.</strong><p>اینجا افزونه فقط آمار نمی‌دهد؛ بر اساس رفتار کاربران، مشکل فروش را توضیح می‌دهد و اقدام عملی پیشنهاد می‌کند.</p></div>';

    echo '<div class="efpx-box">';
    efpx_render_smart_tips($m, 20);
    echo '</div>';

    efpx_foot();
}

function efpx_render_smart_tips($m, $limit=20){
    $tips=[];

    if($m['organic'] > 3 && $m['cta_clicks'] < 3){
        $tips[]=['critical','ورودی ارگانیک داری اما کلیک خرید کم است','کاربر از گوگل آمده، یعنی نیاز یا سوال مشخص داشته؛ اما صفحه او را به خرید هدایت نکرده است.','بالای صفحه محصول یک عنوان فروش‌محور، مزیت اصلی و دکمه خرید واضح قرار بده. توضیحات طولانی را پایین‌تر ببر.'];
    }

    if($m['mobile'] > $m['desktop'] && $m['cta_clicks'] < 5){
        $tips[]=['warning','کاربران موبایل زیادند اما CTA ضعیف است','در موبایل اگر دکمه خرید پایین باشد یا کوچک دیده شود، فروش از دست می‌رود.','دکمه خرید چسبان موبایل، خلاصه مزایا و پیام اعتماد را بالای صفحه فعال کن.'];
    }

    if($m['image_clicks'] > $m['cta_clicks'] && $m['image_clicks'] > 3){
        $tips[]=['warning','کلیک روی تصاویر بیشتر از دکمه خرید است','کاربر هنوز دنبال اطمینان بصری و جزئیات بیشتر محصول است.','گالری محصول، ویدیو کوتاه، تصویر قبل/بعد یا تصویر کاربرد محصول اضافه کن.'];
    }

    if($m['avg_duration'] > 20 && $m['cta_clicks'] < 3){
        $tips[]=['critical','کاربر می‌ماند اما اقدام نمی‌کند','محتوا دیده می‌شود ولی پیشنهاد خرید قانع‌کننده نیست.','پیشنهاد ویژه، ضمانت بازگشت، ارسال سریع و متن CTA قوی‌تر اضافه کن.'];
    }

    if($m['avg_duration'] < 10 && $m['sessions'] > 5){
        $tips[]=['critical','خروج سریع کاربران زیاد است','کاربر در چند ثانیه اول قانع نمی‌شود که بماند.','هدر صفحه، تیتر اصلی، تصویر اول و مزیت اصلی محصول را بازطراحی کن.'];
    }

    if($m['social'] > 3){
        $tips[]=['good','ورودی شبکه اجتماعی داری','کاربران شبکه اجتماعی معمولاً سریع تصمیم می‌گیرند و حوصله متن طولانی ندارند.','برای این ورودی‌ها لندینگ کوتاه، تصویری و مستقیم با دکمه خرید واضح بساز.'];
    }

    $tips[]=['good','اعتمادسازی نزدیک دکمه خرید','بسیاری از کاربران قبل از پرداخت دنبال اطمینان هستند.','کنار CTA مواردی مثل ضمانت بازگشت وجه، ارسال سریع، پشتیبانی، تعداد فروش و رضایت مشتری را نمایش بده.'];
    $tips[]=['good','افزایش میانگین سبد خرید','بعد از جلب اعتماد کاربر، بهترین رشد از پیشنهاد مکمل می‌آید.','برای محصولات پربازدید، محصول مکمل یا باندل اقتصادی پیشنهاد بده.'];
    $tips[]=['good','تست متن دکمه خرید','متن دکمه خرید روی نرخ تبدیل تأثیر مستقیم دارد.','عبارت‌هایی مثل «همین الان سفارش بده»، «خرید با ضمانت» یا «ارسال فوری» را تست کن.'];

    $i=0;
    foreach($tips as $tip){
        if($i >= $limit) break;
        $i++;
        echo '<div class="efpx-tip '.esc_attr($tip[0]).'"><h3>'.esc_html($tip[1]).'</h3><p><b>چرا؟</b> '.esc_html($tip[2]).'</p><p><b>اقدام پیشنهادی:</b> '.esc_html($tip[3]).'</p></div>';
    }
}

function efpx_report(){
    efpx_head('گزارش مدیریتی');
    $m=efpx_metrics();

    echo '<div class="efpx-box"><button onclick="window.print()" class="button button-primary">چاپ / ذخیره PDF</button><h2>خلاصه مدیریتی</h2><p>این گزارش برای صاحب سایت طراحی شده تا بدون دانش فنی بداند وضعیت فروش و رفتار کاربران چگونه است.</p></div>';

    echo '<div class="efpx-grid">';
    efpx_card('کل کاربران',$m['sessions'],'نشست‌های ثبت‌شده');
    efpx_card('ورودی ارگانیک',$m['organic'],'از گوگل و جستجو');
    efpx_card('CTA',$m['cta_clicks'],'کلیک خرید/سبد');
    efpx_card('میانگین حضور',round($m['avg_duration']).' ثانیه','زمان تقریبی');
    echo '</div>';

    echo '<div class="efpx-box"><h2>پیشنهادهای اصلی</h2>';
    efpx_render_smart_tips($m, 5);
    echo '</div>';

    efpx_foot();
}
?>