<?php
/**
 * Plugin Name: Plugin Redirect Fixer
 * Plugin URI: https://github.com/Mel10das/PluginRedirect
 * Description: Автоматически находит и заменяет ссылки с 301 редиректом на конечные URL в контенте WordPress
 * Version: 1.0.0
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

        // Находим все ссылки в контенте
        preg_match_all('/<a[^>]+href=["\'](https?:\/\/[^"\']+)["\'][^>]*>/i', $content, $matches);

        if (!empty($matches[1])) {
            $unique_urls = array_unique($matches[1]);

            foreach ($unique_urls as $url) {
                $redirect_info = $this->check_redirect($url);

                if ($redirect_info['has_redirect']) {
                    $links[] = $redirect_info;
                }
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

            // Заменяем URL в атрибутах href
            $content = str_replace(
                'href="' . $original_url . '"',
                'href="' . $final_url . '"',
                $content
            );

            $content = str_replace(
                "href='" . $original_url . "'",
                "href='" . $final_url . "'",
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
     * AJAX: Сканирование контента
     */
    public function ajax_scan_content() {
        check_ajax_referer('prf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Недостаточно прав'));
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post) {
                $redirects = $this->scan_content_for_redirects($post->post_content);
                wp_send_json_success(array(
                    'redirects' => $redirects,
                    'count' => count($redirects)
                ));
            }
        }

        // Сканируем все посты
        $args = array(
            'post_type' => array('post', 'page'),
            'posts_per_page' => -1,
            'post_status' => 'publish'
        );

        $posts = get_posts($args);
        $all_redirects = array();

        foreach ($posts as $post) {
            $redirects = $this->scan_content_for_redirects($post->post_content);
            if (!empty($redirects)) {
                $all_redirects[$post->ID] = array(
                    'title' => $post->post_title,
                    'url' => get_permalink($post->ID),
                    'redirects' => $redirects
                );
            }
        }

        wp_send_json_success(array(
            'posts' => $all_redirects,
            'total_posts' => count($all_redirects),
            'total_redirects' => array_sum(array_map(function($item) {
                return count($item['redirects']);
            }, $all_redirects))
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
                    <p>Сканирует все опубликованные посты и страницы на наличие ссылок с редиректами.</p>

                    <button id="prf-scan-button" class="button button-primary">Начать сканирование</button>

                    <div id="prf-scan-results" style="margin-top: 20px;"></div>
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
            // Переключение вкладок
            $('.prf-tab-button').on('click', function() {
                var tab = $(this).data('tab');

                $('.prf-tab-button').removeClass('active');
                $(this).addClass('active');

                $('.prf-tab-content').removeClass('active').hide();
                $('#prf-tab-' + tab).addClass('active').show();
            });

            // Сканирование контента
            $('#prf-scan-button').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Сканирование...');

                $('#prf-scan-results').html('<div class="notice notice-info"><p>Сканирование контента...</p></div>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'prf_scan_content',
                        nonce: '<?php echo wp_create_nonce('prf_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var html = '<div class="notice notice-success"><p>Сканирование завершено! Найдено постов с редиректами: ' + data.total_posts + ', всего редиректов: ' + data.total_redirects + '</p></div>';

                            if (data.total_posts > 0) {
                                $.each(data.posts, function(postId, postData) {
                                    html += '<div class="prf-post-item">';
                                    html += '<div class="prf-post-title">' + postData.title + '</div>';
                                    html += '<a href="' + postData.url + '" target="_blank">Просмотр</a> | ';
                                    html += '<a href="/wp-admin/post.php?post=' + postId + '&action=edit" target="_blank">Редактировать</a>';
                                    html += '<div style="margin-top: 10px;">';

                                    $.each(postData.redirects, function(i, redirect) {
                                        html += '<div class="prf-redirect-item">';
                                        html += '<strong>Оригинальный URL:</strong> ' + redirect.original_url + '<br>';
                                        html += '<strong>Конечный URL:</strong> ' + redirect.final_url + '<br>';
                                        html += '<strong>Количество редиректов:</strong> ' + redirect.redirect_count;
                                        html += '</div>';
                                    });

                                    html += '</div>';
                                    html += '<button class="button button-primary prf-fix-button" data-post-id="' + postId + '" style="margin-top: 10px;">Исправить редиректы</button>';
                                    html += '</div>';
                                });
                            }

                            $('#prf-scan-results').html(html);
                        } else {
                            $('#prf-scan-results').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }

                        button.prop('disabled', false).text('Начать сканирование');
                    },
                    error: function() {
                        $('#prf-scan-results').html('<div class="notice notice-error"><p>Произошла ошибка при сканировании</p></div>');
                        button.prop('disabled', false).text('Начать сканирование');
                    }
                });
            });

            // Исправление редиректов
            $(document).on('click', '.prf-fix-button', function() {
                var button = $(this);
                var postId = button.data('post-id');
                var postItem = button.closest('.prf-post-item');
                var redirects = [];

                postItem.find('.prf-redirect-item').each(function() {
                    var text = $(this).text();
                    var originalMatch = text.match(/Оригинальный URL:\s*(.+?)(?:\s|$)/);
                    var finalMatch = text.match(/Конечный URL:\s*(.+?)(?:\s|$)/);

                    if (originalMatch && finalMatch) {
                        redirects.push({
                            original_url: originalMatch[1].trim(),
                            final_url: finalMatch[1].trim()
                        });
                    }
                });

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

            // Проверка URL
            $('#prf-check-button').on('click', function() {
                var url = $('#prf-check-url').val();
                var button = $(this);

                if (!url) {
                    alert('Пожалуйста, введите URL');
                    return;
                }

                button.prop('disabled', true).text('Проверка...');
                $('#prf-check-results').html('<div class="notice notice-info"><p>Проверка URL...</p></div>');

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
                                html += '<div class="notice notice-warning"><p>Обнаружен редирект!</p></div>';
                                html += '<div class="prf-redirect-item">';
                                html += '<strong>Оригинальный URL:</strong> ' + data.original_url + '<br>';
                                html += '<strong>Конечный URL:</strong> ' + data.final_url + '<br>';
                                html += '<strong>Количество редиректов:</strong> ' + data.redirect_count + '<br>';

                                if (data.redirect_chain.length > 0) {
                                    html += '<strong>Цепочка редиректов:</strong><br>';
                                    html += '<div class="prf-redirect-chain">';
                                    $.each(data.redirect_chain, function(i, item) {
                                        html += (i + 1) + '. ' + item.url + ' (' + item.status + ') → ' + item.location + '<br>';
                                    });
                                    html += '</div>';
                                }

                                html += '</div>';
                            } else {
                                html += '<div class="notice notice-success"><p>Редиректы не обнаружены</p></div>';
                                html += '<p><strong>URL:</strong> ' + data.original_url + '</p>';
                            }

                            if (data.error) {
                                html += '<div class="notice notice-error"><p>Ошибка: ' + data.error + '</p></div>';
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
