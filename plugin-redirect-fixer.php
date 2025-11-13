<?php
/**
 * Plugin Name: Plugin Redirect Fixer
 * Plugin URI: https://github.com/Mel10das/PluginRedirect
 * Description: Автоматически находит и заменяет ссылки с 301 редиректом на конечные URL. Пакетное сканирование для сайтов с тысячами страниц. Массовое исправление одним кликом.
 * Version: 1.2.0
 * Author: Mel10das
 * Author URI: https://github.com/Mel10das
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: plugin-redirect-fixer
 * Domain Path: /languages
 */

// Запретить прямой доступ к файлу
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Основной класс плагина
 */
class Plugin_Redirect_Fixer {

    private static $instance = null;
    private $option_name = 'prf_settings';

    /**
     * Получить экземпляр класса (Singleton)
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Конструктор
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Инициализация хуков WordPress
     */
    private function init_hooks() {
        // Добавляем меню в админке
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Регистрируем настройки
        add_action('admin_init', array($this, 'register_settings'));

        // Добавляем ссылку на настройки в список плагинов
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        // AJAX обработчики
        add_action('wp_ajax_prf_scan_content', array($this, 'ajax_scan_content'));
        add_action('wp_ajax_prf_fix_redirects', array($this, 'ajax_fix_redirects'));
        add_action('wp_ajax_prf_check_url', array($this, 'ajax_check_url'));
        add_action('wp_ajax_prf_export_results', array($this, 'ajax_export_results'));
        add_action('wp_ajax_prf_scan_single_page', array($this, 'ajax_scan_single_page'));
        add_action('wp_ajax_prf_get_posts_count', array($this, 'ajax_get_posts_count'));
        add_action('wp_ajax_prf_fix_all_redirects', array($this, 'ajax_fix_all_redirects'));
    }

    /**
     * Проверяет URL на редиректы и возвращает конечный URL
     *
     * @param string $url URL для проверки
     * @param int $max_redirects Максимальное количество редиректов
     * @return array Массив с информацией о редиректе
     */
    public function check_redirect($url, $max_redirects = 5) {
        $result = array(
            'original_url' => $url,
            'final_url' => $url,
            'redirect_count' => 0,
            'redirect_chain' => array(),
            'has_redirect' => false,
            'error' => null
        );

        $current_url = $url;
        $redirect_count = 0;

        while ($redirect_count < $max_redirects) {
            $response = wp_remote_head($current_url, array(
                'timeout' => 10,
                'redirection' => 0, // Отключаем автоматическое следование за редиректами
                'sslverify' => false
            ));

            if (is_wp_error($response)) {
                $result['error'] = $response->get_error_message();
                break;
            }

            $status_code = wp_remote_retrieve_response_code($response);

            // Проверяем, является ли это редиректом
            if (in_array($status_code, array(301, 302, 303, 307, 308))) {
                $location = wp_remote_retrieve_header($response, 'location');

                if (empty($location)) {
                    break;
                }

                // Если location относительный, преобразуем его в абсолютный
                if (strpos($location, 'http') !== 0) {
                    $parsed_url = parse_url($current_url);
                    $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                    $location = $base_url . $location;
                }

                $result['redirect_chain'][] = array(
                    'url' => $current_url,
                    'status' => $status_code,
                    'location' => $location
                );

                $current_url = $location;
                $redirect_count++;
                $result['has_redirect'] = true;
            } else {
                // Достигли конечного URL
                break;
            }
        }

        $result['final_url'] = $current_url;
        $result['redirect_count'] = $redirect_count;

        return $result;
    }

    /**
     * Сканирует контент на наличие ссылок с редиректами
     *
     * @param string $content Контент для сканирования
     * @return array Массив найденных ссылок с редиректами
     */
    public function scan_content_for_redirects($content) {
        $links = array();
        $all_urls = array();

        // 1. Находим ссылки в HTML тегах <a href="">
        preg_match_all('/<a[^>]+href=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i', $content, $matches);
        if (!empty($matches[1])) {
            $all_urls = array_merge($all_urls, $matches[1]);
        }

        // 2. Находим голые URL в тексте
        preg_match_all('/(?<!href=["\'])(?<!src=["\'])(https?:\/\/[^\s<>"{}|\\^\[\]`]+)/i', $content, $matches);
        if (!empty($matches[1])) {
            $all_urls = array_merge($all_urls, $matches[1]);
        }

        // 3. Находим URL в одинарных кавычках (например в атрибутах)
        preg_match_all('/href=[\'](https?:\/\/[^\']+)[\']|src=[\'](https?:\/\/[^\']+)[\']|url\([\'"]?(https?:\/\/[^\'")\s]+)[\'"]?\)/i', $content, $matches);
        foreach ($matches as $match_group) {
            if (!empty($match_group)) {
                foreach ($match_group as $url) {
                    if ($url && strpos($url, 'http') === 0) {
                        $all_urls[] = $url;
                    }
                }
            }
        }

        // Убираем дубликаты
        $unique_urls = array_unique($all_urls);

        // Проверяем каждый URL на редиректы
        foreach ($unique_urls as $url) {
            // Очищаем URL от trailing символов
            $url = rtrim($url, '.,;:!?)');

            if (empty($url)) {
                continue;
            }

            $redirect_info = $this->check_redirect($url);

            if ($redirect_info['has_redirect']) {
                $links[] = $redirect_info;
            }
        }

        return $links;
    }

    /**
     * Заменяет ссылки с редиректами на конечные URL
     *
     * @param string $content Контент для обработки
     * @param array $redirects Массив редиректов для замены
     * @return string Обновленный контент
     */
    public function fix_redirects_in_content($content, $redirects) {
        foreach ($redirects as $redirect) {
            $original_url = $redirect['original_url'];
            $final_url = $redirect['final_url'];

            // Экранируем специальные символы для регулярного выражения
            $escaped_url = preg_quote($original_url, '/');

            // Заменяем URL везде в контенте
            // 1. В атрибутах href с двойными кавычками
            $content = preg_replace(
                '/href="' . $escaped_url . '"/i',
                'href="' . $final_url . '"',
                $content
            );

            // 2. В атрибутах href с одинарными кавычками
            $content = preg_replace(
                "/href='" . $escaped_url . "'/i",
                "href='" . $final_url . "'",
                $content
            );

            // 3. В голом тексте (не в кавычках)
            $content = preg_replace(
                '/(?<!["\'=])' . $escaped_url . '(?!["\'=])/i',
                $final_url,
                $content
            );

            // 4. В других атрибутах (src, data-url и т.д.)
            $content = preg_replace(
                '/(src|data-url|data-href)="' . $escaped_url . '"/i',
                '$1="' . $final_url . '"',
                $content
            );

            $content = preg_replace(
                "/(src|data-url|data-href)='" . $escaped_url . "'/i",
                "$1='" . $final_url . "'",
                $content
            );
        }

        return $content;
    }

    /**
     * Добавляет меню в админ панель
     */
    public function add_admin_menu() {
        add_management_page(
            'Redirect Fixer',
            'Redirect Fixer',
            'manage_options',
            'plugin-redirect-fixer',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Регистрирует настройки плагина
     */
    public function register_settings() {
        register_setting('prf_settings_group', $this->option_name);
    }

    /**
     * Добавляет ссылку на настройки в список плагинов
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="tools.php?page=plugin-redirect-fixer">Настройки</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * AJAX: Сканирование контента (с пакетной обработкой)
     */
    public function ajax_scan_content() {
        check_ajax_referer('prf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
        }

        // Параметры пакетной обработки
        $batch_size = 20; // Количество постов за раз
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        // Сканируем посты пакетами
        $args = array(
            'post_type' => array('post', 'page'),
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'post_status' => 'publish',
            'orderby' => 'ID',
            'order' => 'ASC'
        );

        $posts = get_posts($args);
        $batch_redirects = array();

        foreach ($posts as $post) {
            $redirects = $this->scan_content_for_redirects($post->post_content);
            if (!empty($redirects)) {
                $batch_redirects[$post->ID] = array(
                    'title' => $post->post_title,
                    'url' => get_permalink($post->ID),
                    'redirects' => $redirects
                );
            }
        }

        // Подсчитываем общее количество редиректов в этом пакете
        $batch_redirect_count = 0;
        foreach ($batch_redirects as $post_data) {
            $batch_redirect_count += count($post_data['redirects']);
        }

        wp_send_json_success(array(
            'posts' => $batch_redirects,
            'batch_size' => count($posts),
            'has_more' => count($posts) === $batch_size,
            'next_offset' => $offset + $batch_size,
            'batch_posts_with_redirects' => count($batch_redirects),
            'batch_redirect_count' => $batch_redirect_count
        ));
    }

    /**
     * AJAX: Получение общего количества постов
     */
    public function ajax_get_posts_count() {
        check_ajax_referer('prf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
        }

        $count_query = new WP_Query(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));

        wp_send_json_success(array(
            'total_posts' => $count_query->found_posts
        ));
    }

    /**
     * AJAX: Исправление редиректов
     */
    public function ajax_fix_redirects() {
        check_ajax_referer('prf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $redirects = isset($_POST['redirects']) ? json_decode(stripslashes($_POST['redirects']), true) : array();

        if ($post_id > 0 && !empty($redirects)) {
            $post = get_post($post_id);
            if ($post) {
                $updated_content = $this->fix_redirects_in_content($post->post_content, $redirects);

                $result = wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $updated_content
                ));

                if ($result) {
                    wp_send_json_success(array(
                        'message' => 'Редиректы успешно исправлены',
                        'fixed_count' => count($redirects)
                    ));
                } else {
                    wp_send_json_error(array('message' => 'Ошибка при обновлении поста'));
                }
            }
        }

        wp_send_json_error(array('message' => 'Неверные параметры'));
    }

    /**
     * AJAX: Проверка одного URL
     */
    public function ajax_check_url() {
        check_ajax_referer('prf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (!empty($url)) {
            $redirect_info = $this->check_redirect($url);
            wp_send_json_success($redirect_info);
        }

        wp_send_json_error(array('message' => 'URL не указан'));
    }

    /**
     * AJAX: Массовое исправление редиректов
     */
    public function ajax_fix_all_redirects() {
        check_ajax_referer('prf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
        }

        $post_ids = isset($_POST['post_ids']) ? json_decode(stripslashes($_POST['post_ids']), true) : array();
        $all_redirects = isset($_POST['all_redirects']) ? json_decode(stripslashes($_POST['all_redirects']), true) : array();

        if (empty($post_ids) || empty($all_redirects)) {
            wp_send_json_error(array('message' => 'Нет данных для исправления'));
        }

        $fixed_count = 0;
        $failed_posts = array();

        foreach ($post_ids as $post_id) {
            if (!isset($all_redirects[$post_id])) {
                continue;
            }

            $post = get_post($post_id);
            if (!$post) {
                $failed_posts[] = $post_id;
                continue;
            }

            $redirects = $all_redirects[$post_id]['redirects'];
            $updated_content = $this->fix_redirects_in_content($post->post_content, $redirects);

            $result = wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $updated_content
            ));

            if ($result) {
                $fixed_count++;
            } else {
                $failed_posts[] = $post_id;
            }
        }

        wp_send_json_success(array(
            'fixed_posts' => $fixed_count,
            'failed_posts' => $failed_posts,
            'message' => sprintf('Исправлено %d из %d постов', $fixed_count, count($post_ids))
        ));
    }

    /**
     * AJAX: Экспорт результатов сканирования
     */
    public function ajax_export_results() {
        check_ajax_referer('prf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
        }

        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'csv';
        $data = isset($_POST['data']) ? json_decode(stripslashes($_POST['data']), true) : array();

        if (empty($data)) {
            wp_send_json_error(array('message' => 'Нет данных для экспорта'));
        }

        if ($format === 'csv') {
            $csv_output = "Post ID,Post Title,Post URL,Original URL,Final URL,Redirect Count\n";

            foreach ($data as $post_id => $post_data) {
                foreach ($post_data['redirects'] as $redirect) {
                    $csv_output .= sprintf(
                        '"%s","%s","%s","%s","%s","%s"' . "\n",
                        $post_id,
                        str_replace('"', '""', $post_data['title']),
                        $post_data['url'],
                        $redirect['original_url'],
                        $redirect['final_url'],
                        $redirect['redirect_count']
                    );
                }
            }

            wp_send_json_success(array(
                'format' => 'csv',
                'content' => $csv_output,
                'filename' => 'redirect-report-' . date('Y-m-d') . '.csv'
            ));
        } else {
            // JSON формат
            $json_output = array(
                'export_date' => date('Y-m-d H:i:s'),
                'total_posts' => count($data),
                'posts' => $data
            );

            wp_send_json_success(array(
                'format' => 'json',
                'content' => json_encode($json_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'filename' => 'redirect-report-' . date('Y-m-d') . '.json'
            ));
        }
    }

    /**
     * AJAX: Сканирование одной страницы по URL
     */
    public function ajax_scan_single_page() {
        check_ajax_referer('prf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
        }

        $page_url = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';

        if (empty($page_url)) {
            wp_send_json_error(array('message' => 'URL страницы не указан'));
        }

        // Получаем ID поста по URL
        $post_id = url_to_postid($page_url);

        if (!$post_id) {
            wp_send_json_error(array('message' => 'Страница не найдена'));
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => 'Пост не найден'));
        }

        $redirects = $this->scan_content_for_redirects($post->post_content);

        wp_send_json_success(array(
            'post_id' => $post_id,
            'title' => $post->post_title,
            'url' => get_permalink($post_id),
            'redirects' => $redirects,
            'count' => count($redirects)
        ));
    }

    /**
     * Отображает страницу админки
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Plugin Redirect Fixer</h1>
            <p>Автоматически находит и заменяет ссылки с 301 редиректом на конечные URL</p>

            <div class="prf-admin-container">
                <div class="prf-tabs">
                    <button class="prf-tab-button active" data-tab="scanner">Сканер контента</button>
                    <button class="prf-tab-button" data-tab="checker">Проверка URL</button>
                    <button class="prf-tab-button" data-tab="settings">Настройки</button>
                </div>

                <!-- Вкладка: Сканер -->
                <div id="prf-tab-scanner" class="prf-tab-content active">
                    <h2>Сканирование контента</h2>

                    <div class="prf-scan-section">
                        <h3>Сканирование всего сайта</h3>
                        <p>Сканирует все опубликованные посты и страницы на наличие ссылок с редиректами.</p>
                        <button id="prf-scan-button" class="button button-primary">Начать полное сканирование</button>
                    </div>

                    <div class="prf-scan-section" style="margin-top: 30px;">
                        <h3>Сканирование конкретной страницы</h3>
                        <p>Введите URL страницы для проверки на редиректы.</p>
                        <input type="url" id="prf-page-url" class="regular-text" placeholder="https://ladyelena.ru/your-page/">
                        <button id="prf-scan-page-button" class="button button-primary">Сканировать страницу</button>
                    </div>

                    <div id="prf-scan-progress" style="margin-top: 20px; display: none;">
                        <div style="background: #f0f0f0; border-radius: 5px; height: 30px; overflow: hidden;">
                            <div id="prf-progress-bar" style="background: linear-gradient(90deg, #4caf50, #8bc34a); height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;"></div>
                        </div>
                        <p id="prf-progress-text" style="margin-top: 10px; text-align: center;"></p>
                    </div>

                    <div id="prf-scan-results" style="margin-top: 20px;"></div>

                    <div id="prf-export-buttons" style="margin-top: 15px; display: none;">
                        <h3>Экспорт и массовые операции</h3>
                        <button id="prf-export-csv" class="button">📥 Экспорт в CSV</button>
                        <button id="prf-export-json" class="button">📥 Экспорт в JSON</button>
                        <button id="prf-fix-all" class="button button-primary" style="margin-left: 10px;">🔧 Исправить все редиректы</button>
                    </div>
                </div>

                <!-- Вкладка: Проверка URL -->
                <div id="prf-tab-checker" class="prf-tab-content" style="display: none;">
                    <h2>Проверка URL</h2>
                    <p>Проверьте конкретный URL на наличие редиректов.</p>

                    <input type="url" id="prf-check-url" class="regular-text" placeholder="https://example.com">
                    <button id="prf-check-button" class="button button-primary">Проверить</button>

                    <div id="prf-check-results" style="margin-top: 20px;"></div>
                </div>

                <!-- Вкладка: Настройки -->
                <div id="prf-tab-settings" class="prf-tab-content" style="display: none;">
                    <h2>Настройки</h2>
                    <p>Настройки для плагина (в разработке)</p>
                </div>
            </div>
        </div>

        <style>
            .prf-admin-container {
                background: #fff;
                padding: 20px;
                margin-top: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }

            .prf-tabs {
                border-bottom: 1px solid #ccd0d4;
                margin-bottom: 20px;
            }

            .prf-tab-button {
                background: none;
                border: none;
                padding: 10px 20px;
                cursor: pointer;
                border-bottom: 2px solid transparent;
                margin-right: 10px;
            }

            .prf-tab-button.active {
                border-bottom-color: #2271b1;
                color: #2271b1;
                font-weight: 600;
            }

            .prf-tab-content {
                display: none;
            }

            .prf-tab-content.active {
                display: block;
            }

            .prf-redirect-item {
                background: #f9f9f9;
                padding: 15px;
                margin: 10px 0;
                border-left: 4px solid #d63638;
            }

            .prf-post-item {
                background: #fff;
                border: 1px solid #c3c4c7;
                padding: 15px;
                margin: 10px 0;
            }

            .prf-post-title {
                font-weight: 600;
                font-size: 16px;
                margin-bottom: 10px;
            }

            .prf-redirect-chain {
                font-size: 12px;
                color: #666;
                margin-top: 5px;
                padding-left: 20px;
            }

            .prf-loading {
                display: inline-block;
                margin-left: 10px;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Глобальная переменная для хранения результатов сканирования
            var scanResults = {};

            // Переключение вкладок
            $('.prf-tab-button').on('click', function() {
                var tab = $(this).data('tab');

                $('.prf-tab-button').removeClass('active');
                $(this).addClass('active');

                $('.prf-tab-content').removeClass('active').hide();
                $('#prf-tab-' + tab).addClass('active').show();
            });

            // Функция для отображения результатов
            function displayResults(data, isFullScan) {
                var html = '';
                scanResults = data.posts || {};

                if (isFullScan) {
                    html = '<div class="notice notice-success"><p>✅ Сканирование завершено! Найдено постов с редиректами: ' + data.total_posts + ', всего редиректов: ' + data.total_redirects + '</p></div>';
                } else {
                    html = '<div class="notice notice-success"><p>✅ Сканирование страницы завершено! Найдено редиректов: ' + data.count + '</p></div>';
                    // Формируем данные для одной страницы
                    if (data.count > 0) {
                        scanResults = {};
                        scanResults[data.post_id] = {
                            title: data.title,
                            url: data.url,
                            redirects: data.redirects
                        };
                    }
                }

                if (Object.keys(scanResults).length > 0) {
                    $.each(scanResults, function(postId, postData) {
                        html += '<div class="prf-post-item">';
                        html += '<div class="prf-post-title">📄 ' + postData.title + '</div>';
                        html += '<div style="margin-bottom: 10px;">';
                        html += '<a href="' + postData.url + '" target="_blank">🔗 Просмотр</a> | ';
                        html += '<a href="/wp-admin/post.php?post=' + postId + '&action=edit" target="_blank">✏️ Редактировать</a>';
                        html += '</div>';
                        html += '<div style="margin-top: 10px;">';

                        $.each(postData.redirects, function(i, redirect) {
                            html += '<div class="prf-redirect-item">';
                            html += '<strong>🔴 Оригинальный URL:</strong> <code>' + redirect.original_url + '</code><br>';
                            html += '<strong>🟢 Конечный URL:</strong> <code>' + redirect.final_url + '</code><br>';
                            html += '<strong>🔄 Количество редиректов:</strong> ' + redirect.redirect_count;
                            html += '</div>';
                        });

                        html += '</div>';
                        html += '<button class="button button-primary prf-fix-button" data-post-id="' + postId + '" style="margin-top: 10px;">🔧 Исправить редиректы</button>';
                        html += '</div>';
                    });

                    // Показываем кнопки экспорта
                    $('#prf-export-buttons').show();
                } else {
                    html += '<div class="notice notice-info"><p>Редиректы не найдены</p></div>';
                    $('#prf-export-buttons').hide();
                }

                $('#prf-scan-results').html(html);
            }

            // Сканирование всего сайта (пакетно)
            $('#prf-scan-button').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('⏳ Сканирование...');

                // Скрываем результаты и показываем прогресс
                $('#prf-scan-results').html('');
                $('#prf-export-buttons').hide();
                $('#prf-scan-progress').show();
                $('#prf-progress-bar').css('width', '0%').text('0%');

                // Очищаем предыдущие результаты
                scanResults = {};

                // Сначала получаем количество постов
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'prf_get_posts_count',
                        nonce: '<?php echo wp_create_nonce('prf_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var totalPosts = response.data.total_posts;
                            var offset = 0;
                            var totalPostsWithRedirects = 0;
                            var totalRedirectsCount = 0;

                            // Запускаем пакетное сканирование
                            function scanBatch() {
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'prf_scan_content',
                                        nonce: '<?php echo wp_create_nonce('prf_nonce'); ?>',
                                        offset: offset
                                    },
                                    success: function(batchResponse) {
                                        if (batchResponse.success) {
                                            var data = batchResponse.data;

                                            // Добавляем результаты этого пакета
                                            $.each(data.posts, function(postId, postData) {
                                                scanResults[postId] = postData;
                                            });

                                            totalPostsWithRedirects += data.batch_posts_with_redirects;
                                            totalRedirectsCount += data.batch_redirect_count;

                                            // Обновляем прогресс
                                            var processed = offset + data.batch_size;
                                            var progress = Math.min(100, Math.round((processed / totalPosts) * 100));
                                            $('#prf-progress-bar').css('width', progress + '%').text(progress + '%');
                                            $('#prf-progress-text').text('Обработано: ' + processed + ' из ' + totalPosts + ' постов | Найдено редиректов: ' + totalRedirectsCount);

                                            // Если есть еще посты, продолжаем
                                            if (data.has_more) {
                                                offset = data.next_offset;
                                                setTimeout(scanBatch, 100); // Небольшая пауза между запросами
                                            } else {
                                                // Сканирование завершено
                                                $('#prf-scan-progress').hide();

                                                displayResults({
                                                    posts: scanResults,
                                                    total_posts: totalPostsWithRedirects,
                                                    total_redirects: totalRedirectsCount
                                                }, true);

                                                button.prop('disabled', false).text('Начать полное сканирование');
                                            }
                                        } else {
                                            $('#prf-scan-progress').hide();
                                            $('#prf-scan-results').html('<div class="notice notice-error"><p>Ошибка: ' + batchResponse.data.message + '</p></div>');
                                            button.prop('disabled', false).text('Начать полное сканирование');
                                        }
                                    },
                                    error: function() {
                                        $('#prf-scan-progress').hide();
                                        $('#prf-scan-results').html('<div class="notice notice-error"><p>Произошла ошибка при сканировании пакета</p></div>');
                                        button.prop('disabled', false).text('Начать полное сканирование');
                                    }
                                });
                            }

                            scanBatch();
                        } else {
                            $('#prf-scan-progress').hide();
                            $('#prf-scan-results').html('<div class="notice notice-error"><p>Ошибка получения количества постов</p></div>');
                            button.prop('disabled', false).text('Начать полное сканирование');
                        }
                    },
                    error: function() {
                        $('#prf-scan-progress').hide();
                        $('#prf-scan-results').html('<div class="notice notice-error"><p>Произошла ошибка при подсчете постов</p></div>');
                        button.prop('disabled', false).text('Начать полное сканирование');
                    }
                });
            });

            // Сканирование одной страницы
            $('#prf-scan-page-button').on('click', function() {
                var button = $(this);
                var pageUrl = $('#prf-page-url').val();

                if (!pageUrl) {
                    alert('Пожалуйста, введите URL страницы');
                    return;
                }

                button.prop('disabled', true).text('Сканирование...');
                $('#prf-scan-results').html('<div class="notice notice-info"><p>⏳ Сканирование страницы...</p></div>');
                $('#prf-export-buttons').hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'prf_scan_single_page',
                        nonce: '<?php echo wp_create_nonce('prf_nonce'); ?>',
                        page_url: pageUrl
                    },
                    success: function(response) {
                        if (response.success) {
                            displayResults(response.data, false);
                        } else {
                            $('#prf-scan-results').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }
                        button.prop('disabled', false).text('Сканировать страницу');
                    },
                    error: function() {
                        $('#prf-scan-results').html('<div class="notice notice-error"><p>Произошла ошибка при сканировании</p></div>');
                        button.prop('disabled', false).text('Сканировать страницу');
                    }
                });
            });

            // Исправление редиректов
            $(document).on('click', '.prf-fix-button', function() {
                var button = $(this);
                var postId = button.data('post-id');

                // Получаем редиректы из сохраненных данных
                if (!scanResults[postId] || !scanResults[postId].redirects) {
                    alert('Ошибка: данные о редиректах не найдены');
                    return;
                }

                var redirects = scanResults[postId].redirects;

                if (redirects.length === 0) {
                    alert('Нет редиректов для исправления');
                    return;
                }

                if (!confirm('Вы уверены, что хотите заменить ' + redirects.length + ' ссылок?')) {
                    return;
                }

                button.prop('disabled', true).text('Исправление...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'prf_fix_redirects',
                        nonce: '<?php echo wp_create_nonce('prf_nonce'); ?>',
                        post_id: postId,
                        redirects: JSON.stringify(redirects)
                    },
                    success: function(response) {
                        if (response.success) {
                            postItem.css('background-color', '#d4edda');
                            button.text('Исправлено!').css('background-color', '#28a745');

                            setTimeout(function() {
                                postItem.fadeOut();
                            }, 2000);
                        } else {
                            alert('Ошибка: ' + response.data.message);
                            button.prop('disabled', false).text('Исправить редиректы');
                        }
                    },
                    error: function() {
                        alert('Произошла ошибка при исправлении редиректов');
                        button.prop('disabled', false).text('Исправить редиректы');
                    }
                });
            });

            // Экспорт в CSV
            $('#prf-export-csv').on('click', function() {
                exportResults('csv');
            });

            // Экспорт в JSON
            $('#prf-export-json').on('click', function() {
                exportResults('json');
            });

            // Функция экспорта результатов
            function exportResults(format) {
                if (Object.keys(scanResults).length === 0) {
                    alert('Нет данных для экспорта');
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'prf_export_results',
                        nonce: '<?php echo wp_create_nonce('prf_nonce'); ?>',
                        format: format,
                        data: JSON.stringify(scanResults)
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;

                            // Создаем и скачиваем файл
                            var blob = new Blob([data.content], {
                                type: format === 'csv' ? 'text/csv;charset=utf-8;' : 'application/json;charset=utf-8;'
                            });

                            var link = document.createElement('a');
                            var url = URL.createObjectURL(blob);

                            link.setAttribute('href', url);
                            link.setAttribute('download', data.filename);
                            link.style.visibility = 'hidden';

                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);

                            alert('✅ Файл ' + data.filename + ' успешно загружен!');
                        } else {
                            alert('Ошибка экспорта: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('Произошла ошибка при экспорте данных');
                    }
                });
            }

            // Массовое исправление редиректов
            $('#prf-fix-all').on('click', function() {
                if (Object.keys(scanResults).length === 0) {
                    alert('Нет данных для исправления');
                    return;
                }

                var postIds = Object.keys(scanResults);
                var totalRedirects = 0;
                $.each(scanResults, function(postId, postData) {
                    totalRedirects += postData.redirects.length;
                });

                if (!confirm('Вы уверены, что хотите исправить ' + totalRedirects + ' редиректов на ' + postIds.length + ' страницах?\n\nЭто действие изменит контент постов!')) {
                    return;
                }

                var button = $(this);
                button.prop('disabled', true).text('⏳ Исправление...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'prf_fix_all_redirects',
                        nonce: '<?php echo wp_create_nonce('prf_nonce'); ?>',
                        post_ids: JSON.stringify(postIds),
                        all_redirects: JSON.stringify(scanResults)
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            alert('✅ ' + data.message + '\n\nУспешно: ' + data.fixed_posts + '\nОшибок: ' + data.failed_posts.length);

                            // Убираем исправленные посты из результатов
                            $.each(postIds, function(i, postId) {
                                $('.prf-fix-button[data-post-id="' + postId + '"]').closest('.prf-post-item').fadeOut();
                            });

                            // Обновляем scanResults
                            scanResults = {};

                            setTimeout(function() {
                                button.prop('disabled', false).text('🔧 Исправить все редиректы');
                                $('#prf-export-buttons').hide();
                                $('#prf-scan-results').html('<div class="notice notice-success"><p>✅ Все редиректы исправлены!</p></div>');
                            }, 1000);
                        } else {
                            alert('Ошибка: ' + response.data.message);
                            button.prop('disabled', false).text('🔧 Исправить все редиректы');
                        }
                    },
                    error: function() {
                        alert('Произошла ошибка при массовом исправлении');
                        button.prop('disabled', false).text('🔧 Исправить все редиректы');
                    }
                });
            });

            // Проверка URL
            $('#prf-check-button').on('click', function() {
                var url = $('#prf-check-url').val();
                var button = $(this);

                if (!url) {
                    alert('Пожалуйста, введите URL');
                    return;
                }

                button.prop('disabled', true).text('Проверка...');
                $('#prf-check-results').html('<div class="notice notice-info"><p>⏳ Проверка URL...</p></div>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'prf_check_url',
                        nonce: '<?php echo wp_create_nonce('prf_nonce'); ?>',
                        url: url
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var html = '';

                            if (data.has_redirect) {
                                html += '<div class="notice notice-warning"><p>⚠️ Обнаружен редирект!</p></div>';
                                html += '<div class="prf-redirect-item">';
                                html += '<strong>🔴 Оригинальный URL:</strong> <code>' + data.original_url + '</code><br>';
                                html += '<strong>🟢 Конечный URL:</strong> <code>' + data.final_url + '</code><br>';
                                html += '<strong>🔄 Количество редиректов:</strong> ' + data.redirect_count + '<br>';

                                if (data.redirect_chain.length > 0) {
                                    html += '<strong>📋 Цепочка редиректов:</strong><br>';
                                    html += '<div class="prf-redirect-chain">';
                                    $.each(data.redirect_chain, function(i, item) {
                                        html += (i + 1) + '. <code>' + item.url + '</code> (HTTP ' + item.status + ') → <code>' + item.location + '</code><br>';
                                    });
                                    html += '</div>';
                                }

                                html += '</div>';
                            } else {
                                html += '<div class="notice notice-success"><p>✅ Редиректы не обнаружены</p></div>';
                                html += '<p><strong>URL:</strong> <code>' + data.original_url + '</code></p>';
                            }

                            if (data.error) {
                                html += '<div class="notice notice-error"><p>❌ Ошибка: ' + data.error + '</p></div>';
                            }

                            $('#prf-check-results').html(html);
                        } else {
                            $('#prf-check-results').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }

                        button.prop('disabled', false).text('Проверить');
                    },
                    error: function() {
                        $('#prf-check-results').html('<div class="notice notice-error"><p>Произошла ошибка при проверке URL</p></div>');
                        button.prop('disabled', false).text('Проверить');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

// Инициализация плагина
function plugin_redirect_fixer_init() {
    return Plugin_Redirect_Fixer::get_instance();
}

// Запускаем плагин
add_action('plugins_loaded', 'plugin_redirect_fixer_init');
