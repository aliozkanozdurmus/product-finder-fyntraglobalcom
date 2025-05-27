<?php
/*
Plugin Name: Product Finder - INVENIRE
Description: A product finder plugin where you can upload an Excel spreadsheet and search for products.
Version: 1.0
Author: Ali Özkan Özdurmuş
*/

if (!defined('ABSPATH')) exit;

class ProductFinderInvenire {
    public function __construct() {
        // Admin menüsü
        add_action('admin_menu', [$this, 'add_admin_menu']);
        // Veritabanı tablosu oluşturma
        register_activation_hook(__FILE__, [$this, 'create_table']);
        // Shortcode
        add_shortcode('product_finder_invenire', [$this, 'shortcode']);
        // Excel yükleme işlemi
        add_action('admin_post_pfi_upload', [$this, 'handle_excel_upload']);
        // Gerekli script ve stiller
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        // AJAX filtre endpointleri
        add_action('wp_ajax_pfi_get_filter_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_nopriv_pfi_get_filter_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_pfi_filter_search', [$this, 'ajax_filter_search']);
        add_action('wp_ajax_nopriv_pfi_filter_search', [$this, 'ajax_filter_search']);
        add_action('wp_ajax_pfi_admin_load_more', [$this, 'admin_load_more']);
        add_action('wp_head', [$this, 'add_theme_css_vars']);
        add_action('init', [$this, 'load_textdomain']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'settings_link']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        // AJAX suggestions for search autocomplete
        add_action('wp_ajax_pfi_suggest', [$this, 'ajax_suggest']);
        add_action('wp_ajax_nopriv_pfi_suggest', [$this, 'ajax_suggest']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Product Finder INVENIRE',
            'Product Finder',
            'manage_options',
            'product-finder-invenire',
            [$this, 'admin_page'],
            'dashicons-search',
            26
        );
    }

    public function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pfi_products';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            industry varchar(255),
            technology varchar(255),
            product_name varchar(255),
            chemical_structure varchar(255),
            cas_number varchar(255),
            applications text,
            product_page_url varchar(255),
            PRIMARY KEY  (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function admin_page() {
        // Activation check
        if (!get_option('pfi_activated')) {
            if (isset($_POST['pfi_activate'])) {
                if (isset($_POST['pfi_activation_code']) && $_POST['pfi_activation_code'] === '3e8f79cc') {
                    update_option('pfi_activated', 1);
                    echo '<div class="pfi-admin-msg success">Plugin activated successfully.</div>';
                } else {
                    echo '<div class="pfi-admin-msg error">Invalid activation code.</div>';
                }
            }
            ?>
            <div class="pfi-admin-wrap">
                <h1>Product Finder - INVENIRE</h1>
                <div style="margin-bottom:18px;font-size:1.08em;opacity:0.85;">INVENIRE // Developed by Ali Özkan Özdurmuş.</div>
                <div style="margin-bottom:18px;font-size:1.01em;opacity:0.7;">With this plugin, you can upload an Excel spreadsheet, search, filter, and manage products. You may need to press CTRL+F5 to see updates.</div>
                <form method="post" style="margin-top:24px;">
                    <label for="pfi_activation_code" style="font-weight:600;">Aktivasyon Kodu:</label>
                    <input type="password" id="pfi_activation_code" name="pfi_activation_code" style="margin-left:10px;padding:6px;border:1px solid #ccc;border-radius:4px;" required />
                    <input type="submit" name="pfi_activate" class="button button-primary" style="margin-left:10px;" value="Aktifleştir" />
                </form>
            </div>
            <?php
            return;
        }
        $msg = '';
        if (isset($_GET['import'])) {
            if ($_GET['import'] === 'success') {
                $msg = '<div class="pfi-admin-msg success">Excel uploaded successfully and products added.</div>';
            } elseif ($_GET['import'] === 'error') {
                $msg = '<div class="pfi-admin-msg error">An error occurred during upload.</div>';
            } elseif ($_GET['import'] === 'cleared') {
                $msg = '<div class="pfi-admin-msg success">All products deleted successfully.</div>';
            }
        }
        // Save custom Search Behavior settings
        if (isset($_POST['pfi_save_search_behavior'])) {
            $results_per_page = max(0, intval($_POST['pfi_results_per_page']));
            $sort_field = sanitize_text_field($_POST['pfi_default_sort_field']);
            $sort_order = in_array($_POST['pfi_default_sort_order'], ['ASC','DESC']) ? $_POST['pfi_default_sort_order'] : 'ASC';
            update_option('pfi_results_per_page', $results_per_page);
            update_option('pfi_default_sort_field', $sort_field);
            update_option('pfi_default_sort_order', $sort_order);
            $msg .= '<div class="pfi-admin-msg success">Search behavior settings saved.</div>';
        }
        // Save custom Appearance settings
        if (isset($_POST['pfi_save_appearance'])) {
            $card_bg = sanitize_hex_color($_POST['pfi_card_bg_color'] ?? '');
            $card_text = sanitize_hex_color($_POST['pfi_card_text_color'] ?? '');
            $card_border = sanitize_hex_color($_POST['pfi_card_border_color'] ?? '');
            $custom_css = wp_strip_all_tags($_POST['pfi_custom_css'] ?? '');
            $card_padding = max(0, intval($_POST['pfi_card_padding'] ?? 24));
            $typography = sanitize_text_field($_POST['pfi_typography_font'] ?? '');
            update_option('pfi_card_bg_color', $card_bg);
            update_option('pfi_card_text_color', $card_text);
            update_option('pfi_card_border_color', $card_border);
            update_option('pfi_custom_css', $custom_css);
            update_option('pfi_typography_font', $typography);
            update_option('pfi_card_padding', $card_padding);
            // Save icon settings
            foreach (['industry','technology','chemical_structure','cas_number','applications','product_page_url'] as $f) {
                $val = sanitize_text_field($_POST['pfi_icon_' . $f] ?? '');
                update_option('pfi_icon_' . $f, $val);
            }
            // Save filter font size and border radius from Appearance settings
            $filter_font_size = max(8, intval($_POST['pfi_filter_font_size'] ?? 12));
            update_option('pfi_filter_font_size', $filter_font_size);
            $filter_border_radius = max(0, intval($_POST['pfi_filter_border_radius'] ?? 32));
            update_option('pfi_filter_border_radius', $filter_border_radius);
            // Save filter display on search click
            $filters_click = isset($_POST['pfi_filters_click']) ? 1 : 0;
            update_option('pfi_filters_click', $filters_click);
            $card_clickable = isset($_POST['pfi_card_clickable']) ? 1 : 0;
            update_option('pfi_card_clickable', $card_clickable);
            $msg .= '<div class="pfi-admin-msg success">Appearance settings saved.</div>';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pfi_products';
        // Handle bulk delete action
        if (isset($_POST['pfi_bulk_delete'])) {
            if (!isset($_POST['pfi_bulk_delete_nonce']) || !wp_verify_nonce($_POST['pfi_bulk_delete_nonce'], 'pfi_bulk_delete')) {
                wp_die('Invalid operation.');
            }
            if (!empty($_POST['pfi_bulk_ids']) && is_array($_POST['pfi_bulk_ids'])) {
                $ids = array_map('intval', $_POST['pfi_bulk_ids']);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...$ids));
                $msg .= '<div class="pfi-admin-msg success">Selected products deleted.</div>';
            }
        }
        $products = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 5");
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $last_upload = get_option('pfi_last_upload');
        $search_title = get_option('pfi_search_title', 'What are you looking for?');
        $search_btn = get_option('pfi_search_btn', '🔍');
        $search_placeholder = get_option('pfi_search_placeholder', 'What are you searching for?');
        // Tema ayarları
        $theme_mode = get_option('pfi_theme_mode', 'dark');
        $theme_color = get_option('pfi_theme_color', '#ff4c1e');
        $card_style = get_option('pfi_card_style', 'shadowed');
        if (isset($_POST['pfi_save_theme'])) {
            $theme_mode = in_array($_POST['pfi_theme_mode'], ['light','dark','business']) ? $_POST['pfi_theme_mode'] : 'dark';
            $theme_color = sanitize_hex_color($_POST['pfi_theme_color']);
            $card_style = in_array($_POST['pfi_card_style'], ['flat','shadowed','rounded','glass','gradient']) ? $_POST['pfi_card_style'] : 'shadowed';
            update_option('pfi_theme_mode', $theme_mode);
            update_option('pfi_theme_color', $theme_color);
            update_option('pfi_card_style', $card_style);
            echo '<div class="pfi-admin-msg success">Design settings updated.</div>';
        }
        // Başlık ve buton kaydetme işlemi
        if (isset($_POST['pfi_save_title'])) {
            $new_title = sanitize_text_field($_POST['pfi_search_title'] ?? '');
            update_option('pfi_search_title', $new_title);
            $search_title = $new_title;
            $new_btn = sanitize_text_field($_POST['pfi_search_btn'] ?? '');
            update_option('pfi_search_btn', $new_btn);
            $search_btn = $new_btn;
            $new_placeholder = sanitize_text_field($_POST['pfi_search_placeholder'] ?? '');
            update_option('pfi_search_placeholder', $new_placeholder);
            $search_placeholder = $new_placeholder;
            echo '<div class="pfi-admin-msg success">Search title, button or placeholder updated.</div>';
        }
        ?>
        <style>
        :root {
            --pfi-main-color: <?php echo esc_attr($theme_color); ?>;
        }
        </style>
        <div class="pfi-admin-wrap pfi-theme-<?php echo esc_attr($theme_mode); ?> pfi-card-<?php echo esc_attr($card_style); ?>" style="color:#232733;font-family:'Montserrat',Arial,sans-serif;">
            <h1>Product Finder - INVENIRE</h1>
            <div style="margin-bottom:18px;font-size:1.08em;opacity:0.85;">INVENIRE // Developed by Ali Özkan Özdurmuş.</div>
            <div style="margin-bottom:18px;font-size:1.01em;opacity:0.7;">With this plugin, you can upload an Excel spreadsheet, search, filter, and manage products. You may need to press CTRL+F5 to see updates.</div>
            <form method="post" style="margin-bottom:18px;padding:18px 18px 12px 18px;background:#f8f8f8;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                <div style="font-weight:600;font-size:1.12em;margin-bottom:10px;">Design Settings</div>
                <label for="pfi_theme_mode">Theme:</label>
                <select id="pfi_theme_mode" name="pfi_theme_mode" style="margin-right:18px;">
                    <option value="light" <?php if($theme_mode=='light') echo 'selected'; ?>>Light</option>
                    <option value="dark" <?php if($theme_mode=='dark') echo 'selected'; ?>>Dark</option>
                    <option value="business" <?php if($theme_mode=='business') echo 'selected'; ?>>Business</option>
                </select>
                <label for="pfi_theme_color">Primary Color:</label>
                <input type="color" id="pfi_theme_color" name="pfi_theme_color" value="<?php echo esc_attr($theme_color); ?>" style="margin-right:18px;">
                <label for="pfi_card_style">Card Style:</label>
                <select id="pfi_card_style" name="pfi_card_style">
                    <option value="flat" <?php if($card_style=='flat') echo 'selected'; ?>>Flat</option>
                    <option value="shadowed" <?php if($card_style=='shadowed') echo 'selected'; ?>>Shadowed</option>
                    <option value="rounded" <?php if($card_style=='rounded') echo 'selected'; ?>>Rounded</option>
                    <option value="glass" <?php if($card_style=='glass') echo 'selected'; ?>>Glass</option>
                    <option value="gradient" <?php if($card_style=='gradient') echo 'selected'; ?>>Gradient</option>
                </select>
                <input type="submit" name="pfi_save_theme" class="button" style="background:#232733;color:#fff;border:none;padding:7px 22px;border-radius:7px;font-weight:600;margin-left:18px;" value="Save">
            </form>
            <form method="post" style="margin-bottom:18px;padding:18px 18px 12px 18px;background:#f8f8f8;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                <div style="font-weight:600;font-size:1.12em;margin-bottom:10px;">Search Field Settings</div>
                <label for="pfi_search_title" style="font-weight:600;">Search Title (frontend):</label>
                <input type="text" id="pfi_search_title" name="pfi_search_title" value="<?php echo esc_attr($search_title); ?>" style="width:220px;padding:7px 10px;margin-left:10px;border-radius:6px;border:1px solid #ccc;">
                <br><label for="pfi_search_btn" style="font-weight:600;display:inline-block;margin-top:10px;">Search Button (text or emoji):</label>
                <input type="text" id="pfi_search_btn" name="pfi_search_btn" value="<?php echo esc_attr($search_btn); ?>" style="width:120px;padding:7px 10px;margin-left:10px;border-radius:6px;border:1px solid #ccc;">
                <span style="font-size:0.97em;opacity:0.7;margin-left:8px;">Example: 🔍 or "Search"</span>
                <br><label for="pfi_search_placeholder" style="font-weight:600;display:inline-block;margin-top:10px;">Search Input Placeholder:</label>
                <input type="text" id="pfi_search_placeholder" name="pfi_search_placeholder" value="<?php echo esc_attr($search_placeholder); ?>" style="width:220px;padding:7px 10px;margin-left:10px;border-radius:6px;border:1px solid #ccc;">
                <br><input type="submit" name="pfi_save_title" class="button" style="background:#232733;color:#fff;border:none;padding:7px 22px;border-radius:7px;font-weight:600;margin-top:10px;" value="Save">
                <span style="font-size:0.97em;opacity:0.7;margin-left:8px;">If left empty, the title will not appear.</span>
            </form>
            <?php // Search Behavior Settings Form ?>
            <form method="post" style="margin-bottom:18px;padding:18px;background:#f8f8f8;border-radius:10px;">
                <div style="font-weight:600;font-size:1.12em;margin-bottom:10px;">Search Behavior Settings</div>
                <label for="pfi_results_per_page">Results Per Page:</label>
                <input type="number" id="pfi_results_per_page" name="pfi_results_per_page" value="<?php echo esc_attr(get_option('pfi_results_per_page',3)); ?>" style="width:80px;margin-left:10px;border-radius:6px;border:1px solid #ccc;padding:6px;" min="0" />
                <br><label for="pfi_default_sort_field" style="display:inline-block;margin-top:10px;font-weight:600;">Default Sort Field:</label>
                <select id="pfi_default_sort_field" name="pfi_default_sort_field" style="margin-left:10px;padding:6px;border:1px solid #ccc;border-radius:6px;">
                    <?php foreach(['industry','technology','product_name','chemical_structure','cas_number','applications'] as $field): ?>
                    <option value="<?php echo $field; ?>" <?php selected(get_option('pfi_default_sort_field','product_name'),$field); ?>><?php echo ucwords(str_replace('_',' ',$field)); ?></option>
                    <?php endforeach; ?>
                </select>
                <br><label for="pfi_default_sort_order" style="display:inline-block;margin-top:10px;font-weight:600;">Sort Order:</label>
                <select id="pfi_default_sort_order" name="pfi_default_sort_order" style="margin-left:10px;padding:6px;border:1px solid #ccc;border-radius:6px;">
                    <option value="ASC" <?php selected(get_option('pfi_default_sort_order','ASC'),'ASC'); ?>>Ascending</option>
                    <option value="DESC" <?php selected(get_option('pfi_default_sort_order','ASC'),'DESC'); ?>>Descending</option>
                </select>
                <br><input type="submit" name="pfi_save_search_behavior" class="button" value="Save" style="margin-top:10px;" />
            </form>
            <?php // Appearance Settings Form ?>
            <form method="post" style="margin-bottom:18px;padding:18px;background:#f8f8f8;border-radius:10px;">
                <div style="font-weight:600;font-size:1.12em;margin-bottom:10px;">Appearance Settings</div>
                <label for="pfi_card_bg_color">Card Background Color:</label>
                <input type="color" id="pfi_card_bg_color" name="pfi_card_bg_color" value="<?php echo esc_attr(get_option('pfi_card_bg_color','#f8f8f8')); ?>" style="margin-left:10px;" />
                <label for="pfi_card_text_color" style="margin-left:18px;">Card Text Color:</label>
                <input type="color" id="pfi_card_text_color" name="pfi_card_text_color" value="<?php echo esc_attr(get_option('pfi_card_text_color','#232733')); ?>" style="margin-left:10px;" />
                <label for="pfi_card_border_color" style="margin-left:18px;">Card Border Color:</label>
                <input type="color" id="pfi_card_border_color" name="pfi_card_border_color" value="<?php echo esc_attr(get_option('pfi_card_border_color','#e3eaf3')); ?>" style="margin-left:10px;" />
                <br><label for="pfi_card_padding" style="display:inline-block;margin-top:10px;font-weight:600;">Card Padding (px):</label>
                <input type="number" id="pfi_card_padding" name="pfi_card_padding" value="<?php echo esc_attr(get_option('pfi_card_padding',24)); ?>" min="0" style="width:80px;padding:6px 10px;margin-left:10px;border-radius:6px;border:1px solid #ccc;" />
                <br><label for="pfi_typography_font" style="display:inline-block;margin-top:10px;font-weight:600;">Typography (Google Font):</label>
                <input type="text" id="pfi_typography_font" name="pfi_typography_font" value="<?php echo esc_attr(get_option('pfi_typography_font','Montserrat')); ?>" style="width:220px;padding:7px 10px;margin-left:10px;border-radius:6px;border:1px solid #ccc;" />
                <br><label for="pfi_custom_css" style="display:block;margin-top:10px;font-weight:600;">Custom CSS:</label>
                <textarea id="pfi_custom_css" name="pfi_custom_css" style="width:100%;height:80px;border:1px solid #ccc;border-radius:6px;"><?php echo esc_textarea(get_option('pfi_custom_css','')); ?></textarea>
                <br><label for="pfi_filter_font_size" style="display:inline-block;margin-top:10px;font-weight:600;">Filter Font Size (px):</label>
                <input type="number" id="pfi_filter_font_size" name="pfi_filter_font_size" value="<?php echo esc_attr(get_option('pfi_filter_font_size',12)); ?>" min="8" style="width:80px;padding:6px;margin-left:10px;border-radius:6px;border:1px solid #ccc;" />
                <br><label for="pfi_filter_border_radius" style="display:inline-block;margin-top:10px;font-weight:600;">Filter Button Border Radius (px):</label>
                <input type="number" id="pfi_filter_border_radius" name="pfi_filter_border_radius" value="<?php echo esc_attr(get_option('pfi_filter_border_radius',32)); ?>" min="0" style="width:80px;padding:6px;margin-left:10px;border-radius:6px;border:1px solid #ccc;" />
                <br><label for="pfi_filters_click" style="display:inline-block;margin-top:10px;font-weight:600;">Show filters on search click:</label>
                <input type="checkbox" id="pfi_filters_click" name="pfi_filters_click" <?php checked(get_option('pfi_filters_click',1),1); ?> style="margin-left:10px;vertical-align:middle;" />
                <br><label style="display:block;font-weight:600;margin-top:10px;">Field Icons (FontAwesome class):</label>
                <label for="pfi_icon_industry" style="display:inline-block;width:140px;">Industry:</label>
                <input type="text" id="pfi_icon_industry" name="pfi_icon_industry" value="<?php echo esc_attr(get_option('pfi_icon_industry','fas fa-industry')); ?>" style="width:200px;margin-left:10px;border-radius:6px;border:1px solid #ccc;padding:6px;" />
                <label for="pfi_icon_technology" style="display:inline-block;width:140px;margin-left:20px;">Technology:</label>
                <input type="text" id="pfi_icon_technology" name="pfi_icon_technology" value="<?php echo esc_attr(get_option('pfi_icon_technology','fas fa-microchip')); ?>" style="width:200px;margin-left:10px;border-radius:6px;border:1px solid #ccc;padding:6px;" />
                <br>
                <label for="pfi_icon_chemical_structure" style="display:inline-block;width:140px;">Chemical Struct.:</label>
                <input type="text" id="pfi_icon_chemical_structure" name="pfi_icon_chemical_structure" value="<?php echo esc_attr(get_option('pfi_icon_chemical_structure','fas fa-vial')); ?>" style="width:200px;margin-left:10px;border-radius:6px;border:1px solid #ccc;padding:6px;" />
                <label for="pfi_icon_cas_number" style="display:inline-block;width:140px;margin-left:20px;">CAS Number:</label>
                <input type="text" id="pfi_icon_cas_number" name="pfi_icon_cas_number" value="<?php echo esc_attr(get_option('pfi_icon_cas_number','fas fa-barcode')); ?>" style="width:200px;margin-left:10px;border-radius:6px;border:1px solid #ccc;padding:6px;" />
                <br>
                <label for="pfi_icon_applications" style="display:inline-block;width:140px;">Applications:</label>
                <input type="text" id="pfi_icon_applications" name="pfi_icon_applications" value="<?php echo esc_attr(get_option('pfi_icon_applications','fas fa-cogs')); ?>" style="width:200px;margin-left:10px;border-radius:6px;border:1px solid #ccc;padding:6px;" />
                <label for="pfi_icon_product_page_url" style="display:inline-block;width:140px;">Product URL:</label>
                <input type="text" id="pfi_icon_product_page_url" name="pfi_icon_product_page_url" value="<?php echo esc_attr(get_option('pfi_icon_product_page_url','fas fa-link')); ?>" style="width:200px;margin-left:10px;border-radius:6px;border:1px solid #ccc;padding:6px;" />
                <br><label for="pfi_card_clickable" style="display:inline-block;margin-top:10px;font-weight:600;">Clickable Cards:</label>
                <input type="checkbox" id="pfi_card_clickable" name="pfi_card_clickable" <?php checked(get_option('pfi_card_clickable', 0), 1); ?> style="margin-left:10px;vertical-align:middle;" />
                <br><input type="submit" name="pfi_save_appearance" class="button" value="Save" style="margin-top:10px;" />
            </form>
            <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>" class="pfi-admin-form" style="margin-bottom:18px;">
                <input type="hidden" name="action" value="pfi_upload">
                <?php wp_nonce_field('pfi_upload_excel', 'pfi_excel_nonce'); ?>
                <input type="file" name="pfi_excel" accept=".xls,.xlsx" required />
                <br>
                <input type="submit" class="button button-primary" value="Upload Excel">
            </form>
            <form method="post" action="" onsubmit="return confirm('All products will be deleted. Are you sure?');" style="margin-bottom:18px;">
                <input type="hidden" name="pfi_clear_all" value="1">
                <input type="submit" class="button" style="background:#b00;color:#fff;border:none;padding:8px 28px;border-radius:7px;font-weight:600;" value="Clear All Products">
            </form>
            <?php echo $msg; ?>
            <div style="margin-top:28px;margin-bottom:10px;font-size:1.08em;">
                <strong>Last Excel upload date:</strong> <?php echo $last_upload ? date('d.m.Y H:i', strtotime($last_upload)) : 'Not uploaded yet'; ?>
            </div>
            <div style="margin-top:18px;font-size:1.12em;font-weight:600;">Recently Added Products (<?php echo $total; ?> total)</div>
            <?php // Bulk delete form start ?>
            <form method="post" onsubmit="return confirm('Are you sure you want to delete the selected products?');">
                <?php wp_nonce_field('pfi_bulk_delete','pfi_bulk_delete_nonce'); ?>
                <input type="submit" name="pfi_bulk_delete" class="button button-danger" value="Delete Selected Products" style="margin-bottom:10px;" />
                <div style="overflow-x:auto; width:100%;">
            <table style="width:100%;margin-top:10px;background:#f8f8f8;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.13);">
                <thead style="background:#232733;color:#ff4c1e;">
                    <tr>
                        <th style="padding:8px 6px;"><input type="checkbox" id="pfi_select_all" /></th>
                        <th style="padding:8px 6px;">ID</th>
                        <th style="padding:8px 6px;">Product Name</th>
                        <th style="padding:8px 6px;">Industry</th>
                        <th style="padding:8px 6px;">Technology</th>
                        <th style="padding:8px 6px;">CAS Number</th>
                    </tr>
                </thead>
                <tbody id="pfi-admin-products-tbody">
                    <?php foreach($products as $p): ?>
                    <tr>
                        <td style="padding:7px 6px;"><input type="checkbox" class="pfi_bulk_checkbox" name="pfi_bulk_ids[]" value="<?php echo $p->id; ?>" /></td>
                        <td style="padding:7px 6px;">#<?php echo $p->id; ?></td>
                        <td style="padding:7px 6px;"><?php echo esc_html($p->product_name); ?></td>
                        <td style="padding:7px 6px;"><?php echo esc_html($p->industry); ?></td>
                        <td style="padding:7px 6px;"><?php echo esc_html($p->technology); ?></td>
                        <td style="padding:7px 6px;"><?php echo esc_html($p->cas_number); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
                </div>
            </form>
            <?php if($total > 5): ?>
            <div style="text-align:center;margin-top:16px;">
                <button id="pfi-admin-load-more" class="button" style="background:#ff4c1e;color:#fff;border:none;padding:8px 28px;border-radius:7px;font-weight:600;">Load More</button>
            </div>
            <?php endif; ?>
        </div>
        <script>
        jQuery(function($){
            var offset = 5;
            // Toggle bulk checkbox selection
            $('#pfi_select_all').on('change', function(){
                $('.pfi_bulk_checkbox').prop('checked', $(this).is(':checked'));
            });
            $('#pfi-admin-load-more').on('click', function(){
                var btn = $(this);
                btn.prop('disabled', true).text('Loading...');
                $.post(ajaxurl, {action:'pfi_admin_load_more', offset:offset}, function(resp){
                    if(resp.success){
                        $('#pfi-admin-products-tbody').append(resp.data);
                        offset += 5;
                        if(!resp.more){ btn.hide(); }
                        else { btn.prop('disabled', false).text('Load More'); }
                    }
                });
            });
        });
        </script>
        <?php
        // Tüm ürünleri temizle işlemi
        if (isset($_POST['pfi_clear_all']) && $_POST['pfi_clear_all'] == '1') {
            global $wpdb;
            $table = $wpdb->prefix . 'pfi_products';
            $wpdb->query("TRUNCATE TABLE $table");
            update_option('pfi_last_upload', '');
            wp_redirect(admin_url('admin.php?page=product-finder-invenire&import=cleared'));
            exit;
        }
    }

    public function handle_excel_upload() {
        if (!current_user_can('manage_options')) wp_die('Yetkiniz yok.');
        if (!isset($_FILES['pfi_excel']) || !isset($_POST['pfi_excel_nonce']) || !wp_verify_nonce($_POST['pfi_excel_nonce'], 'pfi_upload_excel')) {
            wp_die('Invalid request.');
        }
        // Excel dosyasını işle (phpspreadsheet ile)
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = $_FILES['pfi_excel'];
        $uploaded = wp_handle_upload($file, ['test_form' => false]);
        if (isset($uploaded['file'])) {
            $this->import_excel($uploaded['file']);
            wp_redirect(admin_url('admin.php?page=product-finder-invenire&import=success'));
            exit;
        } else {
            wp_die('Upload failed.');
        }
    }

    public function import_excel($file_path) {
        // PHPSpreadsheet gerekecek
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        global $wpdb;
        $table = $wpdb->prefix . 'pfi_products';
        // Başlıkları normalize et
        $normalize = function($str) {
            return strtolower(str_replace([' ', '-'], ['_', ''], $str));
        };
        $header_raw = $rows[0];
        $header = array_map($normalize, $header_raw);
        $field_map = [
            'industry' => null,
            'technology' => null,
            'product_name' => null,
            'chemical_structure' => null,
            'cas_number' => null,
            'applications' => null,
            'product_page_url' => null,
        ];
        // Başlıkları alanlara eşleştir
        foreach ($header as $i => $col) {
            foreach (array_keys($field_map) as $field) {
                if ($col === $field) {
                    $field_map[$field] = $i;
                }
            }
        }
        for ($i = 1; $i < count($rows); $i++) {
            $data = [];
            foreach ($field_map as $field => $colIndex) {
                $data[$field] = ($colIndex !== null && isset($rows[$i][$colIndex])) ? $rows[$i][$colIndex] : '';
            }
            // En az bir alan doluysa ekle
            $all_empty = true;
            foreach ($data as $v) {
                if (trim($v) !== '') { $all_empty = false; break; }
            }
            if (!$all_empty) {
                $wpdb->insert($table, $data);
            }
        }
        update_option('pfi_last_upload', current_time('mysql'));
    }

    public function enqueue_scripts() {
        wp_enqueue_style('pfi-style', plugin_dir_url(__FILE__) . 'style.css');
        wp_enqueue_script('pfi-frontend', plugin_dir_url(__FILE__) . 'pfi-frontend.js', ['jquery'], '1.0', true);
        wp_localize_script('pfi-frontend', 'pfiAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'showFiltersOnSearchClick' => (bool) get_option('pfi_filters_click', 1),
            'cardClickable' => (bool) get_option('pfi_card_clickable', 0),
        ]);
        // FontAwesome icon library
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', [], '6.4.0');
    }

    public function add_theme_css_vars() {
        $theme_color = get_option('pfi_theme_color', '#ff4c1e');
        $theme_mode = get_option('pfi_theme_mode', 'dark');
        $card_style = get_option('pfi_card_style', 'shadowed');
        $card_bg = get_option('pfi_card_bg_color', '#f8f8f8');
        $card_text = get_option('pfi_card_text_color', '#232733');
        $card_border = get_option('pfi_card_border_color', '#e3eaf3');
        $card_padding = get_option('pfi_card_padding', 24);
        $custom_css = get_option('pfi_custom_css', '');
        // Typography setting
        $typography = get_option('pfi_typography_font', 'Montserrat');
        $filter_font_size = get_option('pfi_filter_font_size', 12);
        $filter_border_radius = get_option('pfi_filter_border_radius', 32);
        // Load Google Font
        echo "<link href='https://fonts.googleapis.com/css2?family={$typography}:wght@400;600;700&display=swap' rel='stylesheet' />";
        echo "<style>
        :root { 
            --pfi-main-color: {$theme_color};
            --pfi-bg-dark: #181c23;
            --pfi-bg-light: #f8f8f8;
            --pfi-filter-font-size: {$filter_font_size}px;
            --pfi-filter-btn-radius: {$filter_border_radius}px;
        }
        /* Typography override */
        body, .pfi-root { font-family: '{$typography}', Arial, sans-serif !important; }
        /* Custom card appearance */
        .pfi-results li {
            background: {$card_bg} !important;
            color: {$card_text} !important;
            border-color: {$card_border} !important;
            padding: {$card_padding}px !important;
        }
        /* User custom CSS */
        {$custom_css}
        /* Business theme styles */
        .pfi-theme-business .pfi-hero { background: #bec3c8 !important; color: #2b2b2b !important; padding: 48px 0; margin-bottom:32px; }
        .pfi-theme-business .pfi-search-form { background: #1d1f26 !important; max-width:100% !important; border-radius:0 !important; box-shadow:none !important; }
        .pfi-theme-business .pfi-search-form input[type='text'] { color: #ccc !important; }
        .pfi-theme-business .pfi-search-form button { color: var(--pfi-main-color) !important; }
        body.pfi-theme-dark { 
            background-color: var(--pfi-bg-dark) !important; 
            color: #f3f4f6 !important; 
        }
        body.pfi-theme-light { 
            background-color: var(--pfi-bg-light) !important; 
            color: #232733 !important; 
        }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('pfi-theme-{$theme_mode}', 'pfi-card-{$card_style}');
        });
        </script>";
    }

    public function shortcode($atts) {
        // Prevent frontend usage if not activated
        if (!get_option('pfi_activated')) {
            return '<p>Eklenti henüz aktifleştirilmedi.</p>';
        }
        ob_start();
        // Sonuç sayıları
        global $wpdb;
        $table = $wpdb->prefix . 'pfi_products';
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $search_title = get_option('pfi_search_title', 'What are you looking for?');
        $search_btn = get_option('pfi_search_btn', '🔍');
        $search_placeholder = get_option('pfi_search_placeholder', 'What are you searching for?');
        $theme_mode = get_option('pfi_theme_mode', 'dark');
        $theme_color = get_option('pfi_theme_color', '#ff4c1e');
        $card_style = get_option('pfi_card_style', 'shadowed');
        $per_page = get_option('pfi_results_per_page', 3);
        $page = isset($_GET['pfi_page']) ? max(1, intval($_GET['pfi_page'])) : 1;
        $offset = ($page - 1) * $per_page;
        $filter_col = isset($_GET['pfi_filter']) ? sanitize_text_field($_GET['pfi_filter']) : '';
        ?>
        <div class="pfi-root">
            <div class="pfi-hero">
                <?php if($search_title !== ''): ?>
                <h1><?php echo esc_html($search_title); ?></h1>
                <?php endif; ?>
                <form method="get" class="pfi-search-form">
                    <input type="text" name="pfi_q" placeholder="<?php echo esc_attr($search_placeholder); ?>" value="<?php echo esc_attr($_GET['pfi_q'] ?? ''); ?>" />
                    <button type="submit" aria-label="Search"><i class="fas fa-search fa-lg"></i></button>
                </form>
            </div>
            <div class="pfi-filters-bar">
                <button class="pfi-filter-btn" data-filter="product_name">Product Name</button>
                <button class="pfi-filter-btn" data-filter="industry">Industry</button>
                <button class="pfi-filter-btn" data-filter="technology">Technology</button>
                <button class="pfi-filter-btn" data-filter="chemical_structure">Chemical Structure</button>
                <button class="pfi-filter-btn" data-filter="applications">Applications</button>
            </div>
            <div id="pfi-filter-popup" style="display:none;"></div>
            <?php
            // Show total results and always render product list
            echo '<div class="pfi-all-results">All Results (' . esc_html($total) . ')</div>';
            $this->search_results(sanitize_text_field($_GET['pfi_q'] ?? ''), $offset, $per_page, $page, $filter_col);
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function search_results($q, $offset = 0, $per_page = 3, $page = 1, $filter_col = '') {
        // Determine per-page: initial view uses setting, after search always 5
        $option_per_page = intval(get_option('pfi_results_per_page', $per_page));
        $sort_field = get_option('pfi_default_sort_field', 'product_name');
        $sort_order = get_option('pfi_default_sort_order', 'ASC');
        $allowed_sort = ['industry','technology','product_name','chemical_structure','cas_number','applications','product_page_url'];
        if (!in_array($sort_field, $allowed_sort)) { $sort_field = 'product_name'; }
        $sort_order = (strtoupper($sort_order) === 'DESC') ? 'DESC' : 'ASC';
        global $wpdb;
        $table = $wpdb->prefix . 'pfi_products';
        // Default view: empty search and no filter => last per_page products by id desc
        if ($q === '' && !$filter_col) {
            // Initial view: use configured per-page
            $per_page = $option_per_page;
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
            $results = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset)
            );
        } else {
            // After search or filter: always show 5 results per page
            $per_page = 5;
            $offset = ($page - 1) * $per_page;
            $allowed = ['industry', 'technology', 'chemical_structure', 'applications', 'product_name'];
            if ($filter_col && in_array($filter_col, $allowed)) {
                $where = $wpdb->prepare("TRIM(LOWER(`$filter_col`)) = TRIM(LOWER(%s))", strtolower(trim($q)));
            } else {
                $like = '%' . $wpdb->esc_like($q) . '%';
                $where = $wpdb->prepare(
                    "industry LIKE %s OR technology LIKE %s OR product_name LIKE %s OR chemical_structure LIKE %s OR cas_number LIKE %s OR applications LIKE %s OR product_page_url LIKE %s",
                    $like, $like, $like, $like, $like, $like, $like
                );
            }
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");
            $sql = $wpdb->prepare(
                "SELECT * FROM $table WHERE $where ORDER BY `$sort_field` $sort_order LIMIT %d OFFSET %d",
                $per_page, $offset
            );
            $results = $wpdb->get_results($sql);
        }
        if ($results) {
            echo '<div class="pfi-results"><ul>';
            foreach ($results as $row) {
                echo '<li>';
                if (!empty($row->product_page_url) && filter_var($row->product_page_url, FILTER_VALIDATE_URL)) {
                    echo '<strong><a href="' . esc_url($row->product_page_url) . '" target="_blank">' . esc_html($row->product_name) . '</a></strong><br>';
                } else {
                    echo '<strong>' . esc_html($row->product_name) . '</strong><br>';
                }
                $icon = get_option('pfi_icon_industry','fas fa-industry');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">Industry:</span>' . esc_html($row->industry) . '<br>';
                $icon = get_option('pfi_icon_technology','fas fa-microchip');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">Technology:</span>' . esc_html($row->technology) . '<br>';
                $icon = get_option('pfi_icon_chemical_structure','fas fa-vial');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">Chemical Structure:</span>' . esc_html($row->chemical_structure) . '<br>';
                $icon = get_option('pfi_icon_cas_number','fas fa-barcode');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">CAS Number:</span>' . esc_html($row->cas_number) . '<br>';
                $icon = get_option('pfi_icon_applications','fas fa-cogs');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">Applications:</span>' . esc_html($row->applications) . '<br>';
                echo '</li>';
            }
            echo '</ul></div>';
            // Pagination
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1) {
                echo '<div class="pfi-pagination" style="text-align:center;margin-top:18px;">';
                // build pages with ellipsis
                $pages = [];
                $pages[] = 1;
                if ($page > 4) { $pages[] = '...'; }
                for ($i = max(2, $page - 2); $i <= min($total_pages - 1, $page + 2); $i++) {
                    $pages[] = $i;
                }
                if ($page + 3 < $total_pages) { $pages[] = '...'; }
                if ($total_pages > 1) { $pages[] = $total_pages; }
                foreach ($pages as $p) {
                    if ($p === '...') {
                        echo '<span class="pfi-page-ellipsis">...</span> ';
                    } else {
                        $active = ($p == $page) ? 'style="background:var(--pfi-main-color);color:#fff;border-radius:6px;padding:6px 14px;"' : '';
                        $url = add_query_arg(['pfi_q' => $q, 'pfi_page' => $p, 'pfi_filter' => $filter_col]);
                        echo '<a href="' . esc_url($url) . '" class="pfi-page-link" '.$active.'>'.$p.'</a> ';
                    }
                }
                echo '</div>';
            }
        } else {
            // Don't show no-results on initial load when per_page is 0
            if (!($q === '' && !$filter_col && $option_per_page === 0)) {
                echo '<div class="pfi-no-results">Sonuç bulunamadı.</div>';
            }
        }
    }

    // AJAX: Filtre popup için benzersiz değerler
    public function ajax_get_filter_values() {
        global $wpdb;
        $col = sanitize_text_field($_POST['column'] ?? '');
        $allowed = [
            'industry', 'technology', 'chemical_structure', 'applications', 'product_name'
        ];
        if (!in_array($col, $allowed)) {
            wp_send_json_error('Invalid column');
        }
        $table = $wpdb->prefix . 'pfi_products';
        $values = $wpdb->get_col("SELECT DISTINCT `$col` FROM $table WHERE `$col` != '' ORDER BY `$col` ASC");
        $values = array_filter($values, function($v){ return trim($v) !== ''; });
        wp_send_json_success(array_values($values));
    }

    // AJAX: Filtreli arama
    public function ajax_filter_search() {
        global $wpdb;
        $col = sanitize_text_field($_POST['column'] ?? '');
        $val = sanitize_text_field($_POST['value'] ?? '');
        $q = sanitize_text_field($_POST['q'] ?? '');
        $table = $wpdb->prefix . 'pfi_products';
        $where = "1=1";
        $params = [];
        if ($q) {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where .= " AND (industry LIKE %s OR technology LIKE %s OR product_name LIKE %s OR chemical_structure LIKE %s OR cas_number LIKE %s OR applications LIKE %s OR product_page_url LIKE %s)";
            $params = array_fill(0, 8, $like);
        }
        if ($col && $val) {
            $where .= " AND TRIM(LOWER(`$col`)) = TRIM(LOWER(%s))";
            $params[] = strtolower(trim($val));
        }
        $sql = "SELECT * FROM $table WHERE $where LIMIT 50";
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        ob_start();
        if ($results) {
            echo '<div class="pfi-results"><ul>';
            foreach ($results as $row) {
                echo '<li>';
                if (!empty($row->product_page_url) && filter_var($row->product_page_url, FILTER_VALIDATE_URL)) {
                    echo '<strong><a href="' . esc_url($row->product_page_url) . '" target="_blank">' . esc_html($row->product_name) . '</a></strong><br>';
                } else {
                    echo '<strong>' . esc_html($row->product_name) . '</strong><br>';
                }
                $icon = get_option('pfi_icon_industry','fas fa-industry');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">Industry:</span>' . esc_html($row->industry) . '<br>';
                $icon = get_option('pfi_icon_technology','fas fa-microchip');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">Technology:</span>' . esc_html($row->technology) . '<br>';
                $icon = get_option('pfi_icon_chemical_structure','fas fa-vial');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">Chemical Structure:</span>' . esc_html($row->chemical_structure) . '<br>';
                $icon = get_option('pfi_icon_cas_number','fas fa-barcode');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">CAS Number:</span>' . esc_html($row->cas_number) . '<br>';
                $icon = get_option('pfi_icon_applications','fas fa-cogs');
                echo '<i class="'.esc_attr($icon).'"></i> <span class="pfi-label">Applications:</span>' . esc_html($row->applications) . '<br>';
                echo '</li>';
            }
            echo '</ul></div>';
        } else {
            echo '<div class="pfi-no-results">Sonuç bulunamadı.</div>';
        }
        $html = ob_get_clean();
        wp_send_json_success($html);
    }

    // Admin load more ajax
    public function admin_load_more() {
        global $wpdb;
        $table = $wpdb->prefix . 'pfi_products';
        $offset = intval($_POST['offset'] ?? 0);
        $products = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT 5 OFFSET %d", $offset));
        $html = '';
        foreach($products as $p) {
            $html .= '<tr>';
            // Checkbox for bulk actions
            $html .= '<td><input type="checkbox" class="pfi_bulk_checkbox" name="pfi_bulk_ids[]" value="'. $p->id .'"/></td>';
            $html .= '<td style="padding:7px 6px;">#'. $p->id .'</td>';
            $html .= '<td style="padding:7px 6px;">'. esc_html($p->product_name) .'</td>';
            $html .= '<td style="padding:7px 6px;">'. esc_html($p->industry) .'</td>';
            $html .= '<td style="padding:7px 6px;">'. esc_html($p->technology) .'</td>';
            $html .= '<td style="padding:7px 6px;">'. esc_html($p->cas_number) .'</td>';
            $html .= '</tr>';
        }
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $more = ($offset + 5 < $total);
        wp_send_json(['success'=>true, 'data'=>$html, 'more'=>$more]);
    }

    public function load_textdomain() {
        load_plugin_textdomain('product-finder-invenire', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=product-finder-invenire') . '">' . __('Ayarlar', 'product-finder-invenire') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function register_rest_routes() {
        register_rest_route('pfi/v1', '/search', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_search'],
            'args'     => [
                'query'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'filter' => ['sanitize_callback' => 'sanitize_text_field', 'default' => ''],
                'page'   => ['sanitize_callback' => 'absint', 'default' => 1],
            ],
        ]);
    }

    public function rest_search($request) {
        $params    = $request->get_params();
        $q         = $params['query'];
        $filter    = $params['filter'];
        $page      = max(1, (int) $params['page']);
        $per_page  = get_option('pfi_results_per_page', 3);
        $offset    = ($page - 1) * $per_page;
        global $wpdb;
        $table     = $wpdb->prefix . 'pfi_products';
        $where_clauses = [];
        $like = '%' . $wpdb->esc_like($q) . '%';
        if ($filter && in_array($filter, ['industry', 'technology', 'chemical_structure', 'applications'], true)) {
            $where_clauses[] = $wpdb->prepare("TRIM(LOWER(`$filter`)) = TRIM(LOWER(%s))", $q);
        } else {
            foreach (['industry','technology','product_name','chemical_structure','cas_number','applications','product_page_url'] as $col) {
                $where_clauses[] = $wpdb->prepare("`$col` LIKE %s", $like);
            }
        }
        $where_sql = implode(' OR ', $where_clauses);
        $sql = $wpdb->prepare(
            "SELECT * FROM $table WHERE $where_sql LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        $results = $wpdb->get_results($sql);
        return rest_ensure_response($results);
    }

    public static function uninstall() {
        global $wpdb;
        $table = $wpdb->prefix . 'pfi_products';
        $wpdb->query("DROP TABLE IF EXISTS $table");
        $options = [
            'pfi_last_upload','pfi_search_title','pfi_search_btn','pfi_search_placeholder',
            'pfi_theme_mode','pfi_theme_color','pfi_card_style','pfi_activated',
            'pfi_results_per_page','pfi_default_sort_field','pfi_default_sort_order',
            'pfi_card_bg_color','pfi_card_text_color','pfi_card_border_color','pfi_custom_css'
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }
    }

    /**
     * AJAX handler for search suggestions
     */
    public function ajax_suggest() {
        global $wpdb;
        $term = sanitize_text_field($_POST['term'] ?? '');
        if (strlen($term) < 1) {
            wp_send_json_error();
        }
        $table = $wpdb->prefix . 'pfi_products';
        $like = $wpdb->esc_like($term) . '%';
        $results = $wpdb->get_col(
            $wpdb->prepare("SELECT DISTINCT product_name FROM $table WHERE product_name LIKE %s LIMIT 10", $like)
        );
        wp_send_json_success($results);
    }
}

new ProductFinderInvenire();
register_uninstall_hook(__FILE__, ['ProductFinderInvenire', 'uninstall']); 