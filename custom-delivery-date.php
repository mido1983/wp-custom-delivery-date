<?php

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}

/*
Plugin Name: WebRainbow - Delivery Date
Description: This plugin adds a custom delivery date field to the WooCommerce checkout page.
Version: 2.0
Author: WebRainbow
Text Domain: webrainbow-delivery-date
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

class WebRainbow_Delivery_Date
{
    private static $instance = null;
    private $plugin_path;
    private $plugin_url;
    private $version = '2.0';
    private $cache_group = 'webrainbow_delivery';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);

        // Проверка зависимостей
        add_action('plugins_loaded', array($this, 'check_dependencies'));

        // Инициализация плагина
        add_action('init', array($this, 'init'));
    }

    public function check_dependencies()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>';
                _e('WebRainbow Delivery Date requires WooCommerce to be installed and active.', 'webrainbow-delivery-date');
                echo '</p></div>';
            });
            return;
        }

        if (defined('WC_VERSION') && version_compare(WC_VERSION, '3.0', '<')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>';
                _e('WebRainbow Delivery Date requires WooCommerce 3.0 or higher.', 'webrainbow-delivery-date');
                echo '</p></div>';
            });
            return;
        }

        $this->load_textdomain();
    }

    public function init()
    {
        // Регистрация настроек
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Подключение скриптов и стилей
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Добавление поля даты доставки
        add_action('woocommerce_after_checkout_billing_form', array($this, 'add_delivery_date_field'));
        add_action('woocommerce_checkout_process', array($this, 'validate_delivery_date'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_delivery_date'));

        // Отображение даты доставки в письмах
        add_action('woocommerce_email_order_details', array($this, 'display_delivery_date_in_email'), 10, 4);

        // Мета-бокс для продуктов
        add_action('add_meta_boxes', array($this, 'add_product_meta_box'));
        add_action('save_post', array($this, 'save_product_meta'));

        // AJAX
        add_action('wp_ajax_check_delivery_date', array($this, 'ajax_check_delivery_date'));
        add_action('wp_ajax_nopriv_check_delivery_date', array($this, 'ajax_check_delivery_date'));
    }

    public function load_textdomain()
    {
        load_plugin_textdomain(
            'webrainbow-delivery-date',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }
    private function get_min_delivery_date()
    {
        $settings = $this->get_settings();
        $timezone = new DateTimeZone('Asia/Jerusalem');

        $current_datetime = new DateTime('now', $timezone);
        $cutoff_time = new DateTime('now', $timezone);
        $cutoff_time->setTime(15, 0, 0);

        $min_date = clone $current_datetime;

        // Проверяем, есть ли в корзине товары из категории с ID 271
        if ($this->cart_contains_category(271)) {
            if ($current_datetime < $cutoff_time) {
                // До 15:00 можно заказать на следующий день
                $min_date->modify('+1 day');
            } else {
                // После 15:00 можно заказать через два дня
                $min_date->modify('+2 days');
            }
        } else {
            // Для остальных товаров используем стандартное минимальное количество дней
            $min_days = isset($settings['min_days']) ? $settings['min_days'] : 2;
            $min_date->modify("+{$min_days} days");
        }

        // Устанавливаем время в 0:00 для сравнения только дат
        $min_date->setTime(0, 0, 0);

        return $min_date;
    }

    public function enqueue_frontend_assets()
    {
        if (!is_checkout()) {
            return;
        }

        // Подключение стилей и скриптов
        wp_enqueue_style(
            'webrainbow-delivery-date',
            $this->plugin_url . 'assets/css/frontend.css',
            array(),
            $this->version
        );

        wp_enqueue_style(
            'jquery-ui-style',
            'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.min.css'
        );

        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_script(
            'webrainbow-delivery-date',
            $this->plugin_url . 'assets/js/frontend.js',
            array('jquery', 'jquery-ui-datepicker'),
            $this->version,
            true
        );

        // Получаем дату ограничения доставки
        $delivery_until = $this->get_cart_delivery_until_date();
        $settings = $this->get_settings();

        // Получаем минимальную дату доставки
        $min_date = $this->get_min_delivery_date();

        // Преобразуем минимальную дату в формат 'Y-m-d'
        $min_date_formatted = $min_date->format('Y-m-d');

        // Получаем разрешенные дни доставки из настроек или используем значения по умолчанию
        $allowed_days = isset($settings['default_days']) && !empty($settings['default_days']) ? $settings['default_days'] : array('tuesday', 'wednesday', 'friday');

        // Преобразуем дни недели в числовые значения для JavaScript (0 - воскресенье, 1 - понедельник, ..., 6 - суббота)
        $day_mapping = array(
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        );

        $allowed_days_js = array();
        foreach ($allowed_days as $day) {
            if (isset($day_mapping[$day])) {
                $allowed_days_js[] = $day_mapping[$day];
            }
        }

        // Передаем данные в JavaScript
        wp_localize_script('webrainbow-delivery-date', 'webRainbowDelivery', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('webrainbow-delivery-date'),
            'settings' => array(
                'min_days' => isset($settings['min_days']) ? intval($settings['min_days']) : 2,
                'default_days' => isset($settings['default_days']) ? $settings['default_days'] : array(),
                'excluded_dates' => isset($settings['excluded_dates']) ? $settings['excluded_dates'] : array(),
                'delivery_until' => $delivery_until // Добавляем дату ограничения
            ),
            'min_date' => $min_date_formatted,
            'allowed_days' => $allowed_days_js,
            'i18n' => array(
                'selectDate' => __('Please select a delivery date', 'webrainbow-delivery-date'),
                'invalidDate' => __('Invalid delivery date selected', 'webrainbow-delivery-date')
            )
        ));
    }


    private function get_cart_delivery_until_date()
    {
        if (!is_object(WC()->cart)) {
            return null;
        }

        $cart_items = WC()->cart->get_cart();
        $earliest_until_date = null;
        $current_date = new DateTime();

        foreach ($cart_items as $cart_item) {
            $product_id = $cart_item['product_id'];
            $is_enabled = get_post_meta($product_id, '_webrainbow_delivery_until_enabled', true);

            if ($is_enabled === '1') {
                $delivery_until = get_post_meta($product_id, '_webrainbow_delivery_until', true);

                if (!empty($delivery_until)) {
                    $until_date = new DateTime($delivery_until);

                    // Если это первая дата или она раньше текущей earliest_until_date
                    if ($earliest_until_date === null || $until_date < $earliest_until_date) {
                        $earliest_until_date = $until_date;
                    }
                }
            }
        }

        return $earliest_until_date ? $earliest_until_date->format('Y-m-d') : null;
    }

    public function ajax_check_delivery_date()
    {
        try {
            // Проверяем nonce
            if (!check_ajax_referer('webrainbow-delivery-date', 'security', false)) {
                $this->log('Invalid security token in AJAX request');
                wp_send_json_error(array(
                    'message' => __('Security check failed', 'webrainbow-delivery-date')
                ));
                return;
            }

            // Получаем дату из запроса
            $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

            if (empty($date)) {
                $this->log('Empty date in AJAX request');
                wp_send_json_error(array(
                    'message' => __('Date is required', 'webrainbow-delivery-date')
                ));
                return;
            }

            // Проверяем формат даты
            if (!$this->validate_date($date)) {
                $this->log('Invalid date format in AJAX request: ' . $date);
                wp_send_json_error(array(
                    'message' => __('Invalid date format', 'webrainbow-delivery-date')
                ));
                return;
            }

            // Проверяем доступность даты
            $is_available = $this->is_date_available($date);

            $this->log(sprintf('Date availability check: %s is %s',
                $date,
                $is_available ? 'available' : 'not available'
            ));

            if ($is_available) {
                wp_send_json_success(array(
                    'message' => __('Date is available', 'webrainbow-delivery-date'),
                    'available' => true
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('Selected date is not available', 'webrainbow-delivery-date'),
                    'available' => false
                ));
            }

        } catch (Exception $e) {
            $this->log('AJAX Error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array(
                'message' => __('Error checking date availability', 'webrainbow-delivery-date')
            ));
        }

        wp_die(); // Необходимо для корректного завершения AJAX запроса
    }

    public function add_admin_menu()
    {
        add_options_page(
            __('Delivery Date Settings', 'webrainbow-delivery-date'),
            __('Delivery Date', 'webrainbow-delivery-date'),
            'manage_options',
            'webrainbow-delivery-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('webrainbow_delivery_date_options');
                do_settings_sections('webrainbow_delivery_date_options');
                submit_button(__('Save Changes', 'webrainbow-delivery-date'));
                ?>
            </form>
        </div>
        <?php
    }

    public function render_excluded_dates_field()
    {
        $settings = $this->get_settings();
        $excluded_dates = isset($settings['excluded_dates']) ? $settings['excluded_dates'] : array();
        ?>
        <div class="excluded-dates-container">
            <div id="excluded-dates-list">
                <?php foreach ($excluded_dates as $index => $date) : ?>
                    <div class="excluded-date-row">
                        <input type="text"
                               name="webrainbow_delivery_date_settings[excluded_dates][]"
                               value="<?php echo esc_attr($date); ?>"
                               class="webrainbow-datepicker"
                               readonly="readonly"/>
                        <button type="button" class="button remove-excluded-date">
                            <?php _e('Remove', 'webrainbow-delivery-date'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button add-excluded-date">
                <?php _e('Add Excluded Date', 'webrainbow-delivery-date'); ?>
            </button>
        </div>

        <script type="text/template" id="excluded-date-template">
            <div class="excluded-date-row">
                <input type="text"
                       name="webrainbow_delivery_date_settings[excluded_dates][]"
                       class="webrainbow-datepicker"
                       readonly="readonly"/>
                <button type="button" class="button remove-excluded-date">
                    <?php _e('Remove', 'webrainbow-delivery-date'); ?>
                </button>
            </div>
        </script>
        <?php
    }

    public function render_settings_help_tab()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_webrainbow-delivery-settings') {
            return;
        }

        $screen->add_help_tab(array(
            'id' => 'webrainbow_delivery_help',
            'title' => __('Settings Help', 'webrainbow-delivery-date'),
            'content' => '<p>' . __('Configure the delivery date settings for your store:', 'webrainbow-delivery-date') . '</p>' .
                '<ul>' .
                '<li>' . __('Minimum Days: Set the minimum number of days before delivery is available', 'webrainbow-delivery-date') . '</li>' .
                '<li>' . __('Default Days: Select which days of the week are available for delivery by default', 'webrainbow-delivery-date') . '</li>' .
                '<li>' . __('Category Rules: Set specific delivery days for different product categories', 'webrainbow-delivery-date') . '</li>' .
                '<li>' . __('Excluded Dates: Add specific dates when delivery is not available', 'webrainbow-delivery-date') . '</li>' .
                '</ul>'
        ));
    }

    public function enqueue_admin_assets($hook)
    {
        $allowed_hooks = array(
            'post.php',
            'post-new.php',
            'woocommerce_page_webrainbow-delivery-settings'
        );

        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        wp_enqueue_style(
            'webrainbow-delivery-date-admin',
            $this->plugin_url . 'assets/css/admin.css',
            array(),
            $this->version
        );

        wp_enqueue_script(
            'webrainbow-delivery-date-admin',
            $this->plugin_url . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-datepicker'),
            $this->version,
            true
        );
    }

    public function get_settings()
    {
        $cache_key = 'webrainbow_delivery_settings';
        $settings = wp_cache_get($cache_key, $this->cache_group);

        if (false === $settings) {
            $settings = get_option('webrainbow_delivery_date_settings', array());
            wp_cache_set($cache_key, $settings, $this->cache_group, HOUR_IN_SECONDS);
        }

        return $settings;
    }

    public function register_settings()
    {
        register_setting(
            'webrainbow_delivery_date_options',
            'webrainbow_delivery_date_settings',
            array($this, 'validate_settings')
        );

        add_settings_section(
            'webrainbow_delivery_date_section',
            __('Delivery Date Settings', 'webrainbow-delivery-date'),
            array($this, 'render_settings_section'),
            'webrainbow_delivery_date_options'
        );

        $this->add_settings_fields();
    }

    private function add_settings_fields()
    {
        $fields = array(
            'min_days' => array(
                'title' => __('Minimum Days Until Delivery', 'webrainbow-delivery-date'),
                'callback' => 'render_min_days_field'
            ),
            'default_days' => array(
                'title' => __('Default Delivery Days', 'webrainbow-delivery-date'),
                'callback' => 'render_default_days_field'
            ),
            'category_days' => array(
                'title' => __('Category Specific Delivery Days', 'webrainbow-delivery-date'),
                'callback' => 'render_category_days_field'
            ),
            'excluded_dates' => array(
                'title' => __('Excluded Dates', 'webrainbow-delivery-date'),
                'callback' => 'render_excluded_dates_field'
            )
        );

        foreach ($fields as $id => $field) {
            add_settings_field(
                'webrainbow_delivery_date_' . $id,
                $field['title'],
                array($this, $field['callback']),
                'webrainbow_delivery_date_options',
                'webrainbow_delivery_date_section'
            );
        }
    }

    public function validate_settings($input)
    {
        $valid = array();

        // Минимальное количество дней
        $valid['min_days'] = absint($input['min_days']);

        // Дни доставки по умолчанию
        $valid['default_days'] = array_intersect(
            isset($input['default_days']) ? (array)$input['default_days'] : array(),
            array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday')
        );

        // Дни доставки по категориям
        if (isset($input['category_days']) && is_array($input['category_days'])) {
            foreach ($input['category_days'] as $index => $category_data) {
                if (empty($category_data['category']) || empty($category_data['days'])) {
                    continue;
                }

                $valid['category_days'][] = array(
                    'category' => absint($category_data['category']),
                    'days' => array_intersect(
                        (array)$category_data['days'],
                        array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday')
                    )
                );
            }
        }

        // Исключенные даты
        if (isset($input['excluded_dates']) && is_array($input['excluded_dates'])) {
            foreach ($input['excluded_dates'] as $date) {
                if ($this->validate_date($date)) {
                    $valid['excluded_dates'][] = sanitize_text_field($date);
                }
            }
        }

        return $valid;
    }

    private function validate_date($date)
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    public function add_delivery_date_field($checkout)
    {
//        echo '<div class="delivery-info" style="margin-bottom: 20px;">
//        <p>' . __('Уважаемые клиенты, временно Доставка доступна только во вторник, четверг и пятницу', 'webrainbow-delivery-date') . '</p>
//    </div>';
        woocommerce_form_field('delivery_date', array(
            'type' => 'text',
            'class' => array('form-row-wide'),
            'label' => __('Дата доставки', 'webrainbow-delivery-date') . ' <abbr class="required" title="' . esc_attr__('required', 'woocommerce') . '">*</abbr>',
            'required' => true,
            'custom_attributes' => array(
                'readonly' => 'readonly'
            ),
            'placeholder' => __('Select delivery date', 'webrainbow-delivery-date')
        ));
    }
    private function cart_contains_category($category_id)
    {
        if (!is_object(WC()->cart)) {
            return false;
        }

        $cart_items = WC()->cart->get_cart();

        foreach ($cart_items as $cart_item) {
            $product_id = $cart_item['product_id'];
            $terms = get_the_terms($product_id, 'product_cat');

            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    if ($term->term_id == $category_id) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function validate_delivery_date()
    {
        try {
            if (!isset($_POST['delivery_date'])) {
                throw new Exception(__('Please select a delivery date.', 'webrainbow-delivery-date'));
            }

            $delivery_date = sanitize_text_field($_POST['delivery_date']);

            if (!$this->validate_date($delivery_date)) {
                throw new Exception(__('Invalid delivery date format.', 'webrainbow-delivery-date'));
            }

            if (!$this->is_date_available($delivery_date)) {
                throw new Exception(__('Selected delivery date is not available.', 'webrainbow-delivery-date'));
            }

        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
        }
    }

    private function is_date_available($date)
    {
        $settings = $this->get_settings();
        $timezone = new DateTimeZone('Asia/Jerusalem'); // Временная зона Израиля

        $date_obj = new DateTime($date, $timezone);

        // Проверка исключенных дат
        if (isset($settings['excluded_dates']) && in_array($date, $settings['excluded_dates'])) {
            return false;
        }

        // Получаем текущую дату и время
        $current_datetime = new DateTime('now', $timezone);
        $cutoff_time = new DateTime('now', $timezone);
        $cutoff_time->setTime(15, 0, 0); // Устанавливаем время отсечки на 15:00

        // Инициализируем минимальную дату доставки
        $min_date = clone $current_datetime;

        // Проверяем, есть ли в корзине товары из категории с ID 271
        if ($this->cart_contains_category(271)) {
            // Если текущая дата и время меньше времени отсечки (15:00)
            if ($current_datetime < $cutoff_time) {
                // До 15:00 можно заказать на следующий день
                $min_date->modify('+1 day');
            } else {
                // После 15:00 можно заказать через два дня
                $min_date->modify('+2 days');
            }
        } else {
            // Для остальных товаров используем стандартное минимальное количество дней
            $min_days = isset($settings['min_days']) ? $settings['min_days'] : 2;
            $min_date->modify("+{$min_days} days");
        }

        // Устанавливаем время в 0:00 для сравнения только дат
        $min_date->setTime(0, 0, 0);
        $date_obj->setTime(0, 0, 0);

        if ($date_obj < $min_date) {
            return false;
        }

        // Получаем день недели выбранной даты
        $day_of_week = strtolower($date_obj->format('l'));

        // Проверяем корзину на наличие товаров с особыми правилами доставки
        $cart_items = WC()->cart->get_cart();
        foreach ($cart_items as $cart_item) {
            $product_id = $cart_item['product_id'];
            $is_enabled = get_post_meta($product_id, '_webrainbow_delivery_until_enabled', true);

            if ($is_enabled === '1') {
                // Проверяем дату окончания доставки
                $delivery_until = get_post_meta($product_id, '_webrainbow_delivery_until', true);
                if (!empty($delivery_until) && $date > $delivery_until) {
                    return false;
                }

                // Проверяем разрешенные дни доставки для продукта
                $product_delivery_days = get_post_meta($product_id, '_webrainbow_delivery_days', true);
                if (!empty($product_delivery_days) && !in_array($day_of_week, $product_delivery_days)) {
                    return false;
                }
            }
        }

        // Если нет особых правил, используем стандартные дни доставки
        $allowed_days = array('tuesday', 'wednesday', 'friday');
        return in_array($day_of_week, $allowed_days);
    }



    private function get_cart_categories()
    {
        $categories = array();
        $cart_items = WC()->cart->get_cart();

        foreach ($cart_items as $cart_item) {
            $product_categories = wp_get_post_terms($cart_item['product_id'], 'product_cat', array('fields' => 'ids'));
            $categories = array_merge($categories, $product_categories);
        }

        return array_unique($categories);
    }

    public function save_delivery_date($order_id)
    {
        try {
            if (!isset($_POST['delivery_date'])) {
                throw new Exception('Delivery date not specified');
            }

            $delivery_date = sanitize_text_field($_POST['delivery_date']);
            $order = wc_get_order($order_id);

            if (!$order) {
                throw new Exception('Order not found');
            }

            $order->update_meta_data('_delivery_date', $delivery_date);
            $order->save();

            $this->log(sprintf('Delivery date %s saved for order %d', $delivery_date, $order_id));

        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function display_delivery_date_in_email($order, $sent_to_admin, $plain_text, $email)
    {
        $delivery_date = $order->get_meta('_delivery_date');

        if ($delivery_date) {
            $date_obj = new DateTime($delivery_date);
            $formatted_date = $date_obj->format('d.m.Y');

            if ($plain_text) {
                echo "\n" . __('Дата доставки', 'webrainbow-delivery-date') . ': ' . $formatted_date . "\n";
            } else {
                echo '<p style="color: red; font-size: 1.5rem"><strong>' .
                    __('Дата доставки', 'webrainbow-delivery-date') . ':</strong> ' .
                    esc_html($formatted_date) . '</p>';
            }
        }
    }

    private function log($message, $level = 'info')
    {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $log_file = WP_CONTENT_DIR . '/webrainbow-delivery.log';
            $timestamp = current_time('mysql');
            error_log("[$timestamp] [$level] $message\n", 3, $log_file);
        }
    }

    public function add_product_meta_box()
    {
        add_meta_box(
            'webrainbow_delivery_until',
            __('Delivery Available Until', 'webrainbow-delivery-date'),
            array($this, 'render_product_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    public function render_product_meta_box($post)
    {
        wp_nonce_field('webrainbow_delivery_until_nonce', 'webrainbow_delivery_until_nonce_field');

        $delivery_until = get_post_meta($post->ID, '_webrainbow_delivery_until', true);
        $is_enabled = get_post_meta($post->ID, '_webrainbow_delivery_until_enabled', true);
        $delivery_days = get_post_meta($post->ID, '_webrainbow_delivery_days', true);

        // Если дни доставки не заданы, используем значения по умолчанию
        if (empty($delivery_days)) {
            $delivery_days = array('tuesday', 'wednesday', 'friday');
        }

        ?>
        <div class="webrainbow-delivery-settings">
            <p class="delivery-info">
                <?php _e('По умолчанию доставка доступна по вторникам, четвергам и пятницам', 'webrainbow-delivery-date'); ?>
            </p>

            <p>
                <label>
                    <input type="checkbox"
                           id="webrainbow_delivery_until_enabled"
                           name="webrainbow_delivery_until_enabled"
                           value="1"
                        <?php checked($is_enabled, '1'); ?> />
                    <?php _e('Использовать особые правила доставки', 'webrainbow-delivery-date'); ?>
                </label>
            </p>

            <div class="delivery-settings-content" style="<?php echo $is_enabled ? '' : 'display: none;'; ?>">
                <p>
                    <label for="webrainbow_delivery_until_date">
                        <?php _e('Доступно для доставки до:', 'webrainbow-delivery-date'); ?>
                    </label>
                    <input type="text"
                           id="webrainbow_delivery_until_date"
                           name="webrainbow_delivery_until_date"
                           value="<?php echo esc_attr($delivery_until); ?>"
                           class="webrainbow-datepicker"
                           readonly="readonly"/>
                </p>

                <p class="delivery-days">
                    <label><?php _e('Доступные дни доставки:', 'webrainbow-delivery-date'); ?></label><br>
                    <?php
                    $days = array(
                        'monday' => __('Понедельник', 'webrainbow-delivery-date'),
                        'tuesday' => __('Вторник', 'webrainbow-delivery-date'),
                        'wednesday' => __('Среда', 'webrainbow-delivery-date'),
                        'thursday' => __('Четверг', 'webrainbow-delivery-date'),
                        'friday' => __('Пятница', 'webrainbow-delivery-date'),
                        'saturday' => __('Суббота', 'webrainbow-delivery-date'),
                        'sunday' => __('Воскресенье', 'webrainbow-delivery-date')
                    );

                    foreach ($days as $value => $label) :
                        ?>
                        <label class="day-checkbox">
                            <input type="checkbox"
                                   name="webrainbow_delivery_days[]"
                                   value="<?php echo esc_attr($value); ?>"
                                <?php checked(in_array($value, $delivery_days)); ?> />
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </p>
            </div>
        </div>

        <style>
            .webrainbow-delivery-settings {
                padding: 10px;
            }

            .delivery-info {
                background: #f8f9fa;
                padding: 10px;
                border-left: 4px solid #0073aa;
                margin-bottom: 15px;
            }

            .delivery-days {
                margin-top: 15px;
            }

            .day-checkbox {
                display: block;
                margin: 5px 0;
            }

            .delivery-settings-content {
                margin-top: 15px;
                padding-left: 10px;
                border-left: 1px solid #ddd;
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                $('#webrainbow_delivery_until_enabled').on('change', function () {
                    $('.delivery-settings-content').toggle(this.checked);
                });
            });
        </script>
        <?php
    }

    public function save_product_meta($post_id)
    {
        try {
            if (!isset($_POST['webrainbow_delivery_until_nonce_field']) ||
                !wp_verify_nonce($_POST['webrainbow_delivery_until_nonce_field'], 'webrainbow_delivery_until_nonce')) {
                return;
            }

            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            // Сохраняем флаг включения особых правил
            $is_enabled = isset($_POST['webrainbow_delivery_until_enabled']) ? '1' : '0';
            update_post_meta($post_id, '_webrainbow_delivery_until_enabled', $is_enabled);

            if ($is_enabled) {
                // Сохраняем дату окончания доставки
                if (isset($_POST['webrainbow_delivery_until_date'])) {
                    $delivery_until = sanitize_text_field($_POST['webrainbow_delivery_until_date']);
                    update_post_meta($post_id, '_webrainbow_delivery_until', $delivery_until);
                }

                // Сохраняем выбранные дни доставки
                $delivery_days = isset($_POST['webrainbow_delivery_days']) ?
                    array_map('sanitize_text_field', $_POST['webrainbow_delivery_days']) :
                    array();

                // Проверяем, что выбран хотя бы один день
                if (empty($delivery_days)) {
                    $delivery_days = array('tuesday', 'wednesday', 'friday');
                }

                update_post_meta($post_id, '_webrainbow_delivery_days', $delivery_days);
            }

        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function render_settings_section()
    {
        echo '<p>' . __('Configure delivery date settings for your store.', 'webrainbow-delivery-date') . '</p>';
    }

    public function render_min_days_field()
    {
        $settings = $this->get_settings();
        $min_days = isset($settings['min_days']) ? $settings['min_days'] : 2;
        ?>
        <input type="number"
               name="webrainbow_delivery_date_settings[min_days]"
               value="<?php echo esc_attr($min_days); ?>"
               min="0"
               class="small-text"/>
        <p class="description">
            <?php _e('Minimum number of days before delivery is available', 'webrainbow-delivery-date'); ?>
        </p>
        <?php
    }

    public function render_default_days_field()
    {
        $settings = $this->get_settings();
        $default_days = isset($settings['default_days']) ? $settings['default_days'] : array();

        $days = array(
            'monday' => __('Monday', 'webrainbow-delivery-date'),
            'tuesday' => __('Tuesday', 'webrainbow-delivery-date'),
            'wednesday' => __('Wednesday', 'webrainbow-delivery-date'),
            'thursday' => __('Thursday', 'webrainbow-delivery-date'),
            'friday' => __('Friday', 'webrainbow-delivery-date'),
            'saturday' => __('Saturday', 'webrainbow-delivery-date'),
            'sunday' => __('Sunday', 'webrainbow-delivery-date')
        );

        foreach ($days as $value => $label) {
            ?>
            <label>
                <input type="checkbox"
                       name="webrainbow_delivery_date_settings[default_days][]"
                       value="<?php echo esc_attr($value); ?>"
                    <?php checked(in_array($value, $default_days)); ?> />
                <?php echo esc_html($label); ?>
            </label><br>
            <?php
        }
    }

    public function render_category_days_field()
    {
        $settings = $this->get_settings();
        $category_days = isset($settings['category_days']) ? $settings['category_days'] : array();

        // Render category days interface
        $this->render_category_days_interface($category_days);
    }

    private function render_category_days_interface($category_days)
    {
        // Template for category days interface
        ?>
        <div id="category-days-container">
            <?php
            if (!empty($category_days)) {
                foreach ($category_days as $index => $data) {
                    $this->render_category_days_row($index, $data);
                }
            }
            ?>
        </div>
        <button type="button" class="button add-category-days">
            <?php _e('Add Category Rule', 'webrainbow-delivery-date'); ?>
        </button>

        <script type="text/template" id="category-days-row-template">
            <?php $this->render_category_days_row('{{index}}', array()); ?>
        </script>
        <?php
    }

    private function render_category_days_row($index, $data)
    {
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));

        $days = array(
            'monday' => __('Monday', 'webrainbow-delivery-date'),
            'tuesday' => __('Tuesday', 'webrainbow-delivery-date'),
            'wednesday' => __('Wednesday', 'webrainbow-delivery-date'),
            'thursday' => __('Thursday', 'webrainbow-delivery-date'),
            'friday' => __('Friday', 'webrainbow-delivery-date'),
            'saturday' => __('Saturday', 'webrainbow-delivery-date'),
            'sunday' => __('Sunday', 'webrainbow-delivery-date')
        );
        ?>
        <div class="category-days-row">
            <select name="webrainbow_delivery_date_settings[category_days][<?php echo $index; ?>][category]">
                <option value=""><?php _e('Select Category', 'webrainbow-delivery-date'); ?></option>
                <?php foreach ($categories as $category) : ?>
                    <option value="<?php echo esc_attr($category->term_id); ?>"
                        <?php selected(isset($data['category']) ? $data['category'] : '', $category->term_id); ?>>
                        <?php echo esc_html($category->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="category-days-checkboxes">
                <?php foreach ($days as $value => $label) : ?>
                    <label>
                        <input type="checkbox"
                               name="webrainbow_delivery_date_settings[category_days][<?php echo $index; ?>][days][]"
                               value="<?php echo esc_attr($value); ?>"
                            <?php checked(isset($data['days']) && in_array($value, $data['days'])); ?> />
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="button" class="button remove-category-days">
                <?php _e('Remove', 'webrainbow-delivery-date'); ?>
            </button>
        </div>
        <?php
    }
}


// Initialize the plugin
function WebRainbow_Delivery_Date()
{
    return WebRainbow_Delivery_Date::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'WebRainbow_Delivery_Date');
add_action('wp_enqueue_scripts', 'webrainbow_enqueue_custom_scripts');
function webrainbow_enqueue_custom_scripts()
{
    if (is_checkout()) {
        wp_enqueue_script(
            'webrainbow-delivery-custom',
            plugin_dir_url(__FILE__) . 'js/custom-delivery-date.js',
            array('jquery'),
            '1.0',
            true
        );

        // Передаем данные в JS
        wp_localize_script('webrainbow-delivery-custom', 'webrainbowDelivery', array(
            'cities' => array(
                'CentralIsraelLocations' => [
                    "Тель-Авив", "Рамат-Ган", "Гиватайм", "Бат-Ям", "Холон", "Бней-Брак",
                    "Герцлия", "Кфар-Саба", "Реховот", "Петах-Тиква", "Ришон-ле-Цион",
                    "Ход-ха-Шарон", "Рамле", "Лод", "Явне", "Ор-Йехуда", "Гиват-Шмуэль",
                    "Кфар-Шмарьягу", "Сдерот", "Нес-Циона", "Тель-Монд", "Макабим-Реут",
                    "Эвен-Ехуда", "Кохав-Яир", "Азур", "Гани-Тиква", "Кирьят-Оно",
                    "Савайон", "Шохам", "Ариэль", "Элькана", "Орнит", "Шаарей-Тиква",
                    "Рош-ха-Аин", "Алей Захав", "Брухин", "Кфар-Касем", "Кфар-Сава",
                    "Бейт-Ариф", "Нофей-Прат", "Тсур-Яигал", "Тсур-Натан", "Яркон",
                    "Геулей-Тиква", "Нехалим", "Кфар-Сиркин", "Сегула", "Магшимим",
                    "Бней-Атарот", "Мисгав-Дов", "Тирума", "Нахшоним", "Эльдад",
                    "Ганей-Арик", "Хагор", "Рехасим", "Нир-Цви", "Хацор-Ашдод",
                    "Хацор-Ашкелон", "Мазкерет-Батия", "Гедера", "Явнил", "Бейт-Даган",
                    "Афек", "Тель-Цур", "Кохав-Михаэль", "Кфар-Авив", "Эйн-Веред"
                ]
            ),
            'warning' => __('Доставка в среду возможна только в центральных районах Израиля!', 'webrainbow-delivery-date'),
        ));
    }
}
