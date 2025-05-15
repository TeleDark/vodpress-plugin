<?php

/**
 * Plugin Name: VODPress
 * Description: Convert and manage your videos with HLS streaming support
 * Version: 1.1.0
 * Author: Morteza Mohammadnezhad
 * License: GPL v2 or later
 * Text Domain: vodpress
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

class VODPress
{
    private $api_client;
    private static $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_plugin();
        $this->init_hooks();
    }

    private function init_plugin(): void
    {
        // Get API key from wp-config.php instead of options table
        $api_key = defined('VODPRESS_API_KEY') ? VODPRESS_API_KEY : null;
        $server_url = get_option('vodpress_server_url');

        if ($api_key && $server_url) {
            $this->api_client = new VODPressAPIClient($api_key, $server_url);
        }
    }

    private function init_hooks(): void
    {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        add_action('rest_api_init', function () {
            register_rest_route('vodpress/v1', '/callback', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_conversion_callback'],
                'permission_callback' => '__return_true'
            ]);
        });

        add_action('wp_ajax_vodpress_submit_video', [$this, 'ajax_submit_video']);
        add_action('wp_ajax_vodpress_get_videos_status', [$this, 'ajax_get_videos_status']);
        add_action('wp_ajax_vodpress_retry_video', [$this, 'ajax_retry_video']);
        add_action('wp_ajax_vodpress_delete_video', [$this, 'ajax_delete_video']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('vodpress', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function register_settings(): void
    {
        // API key is now managed in wp-config.php
        register_setting('vodpress_settings', 'vodpress_server_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('vodpress_settings', 'vodpress_public_url_base', ['sanitize_callback' => 'esc_url_raw']);
    }

    public function add_admin_menu(): void
    {
        add_menu_page(
            __('VODPress', 'vodpress'),
            __('VODPress', 'vodpress'),
            'manage_options',
            'vodpress',
            [$this, 'admin_page'],
            'dashicons-video-alt3'
        );

        add_submenu_page(
            'vodpress',
            __('Settings', 'vodpress'),
            __('Settings', 'vodpress'),
            'manage_options',
            'vodpress-settings',
            [$this, 'settings_page']
        );
    }

    public function enqueue_scripts($hook): void
    {
        if ($hook !== 'toplevel_page_vodpress') {
            return;
        }

        $css_version = filemtime(plugin_dir_path(__FILE__) . 'assets/css/style.css');
        $css_player = filemtime(plugin_dir_path(__FILE__) . 'assets/css/player.css');

        $js_version = filemtime(plugin_dir_path(__FILE__) . 'assets/js/script.js');

        wp_enqueue_style('vodpress-styles', plugins_url('assets/css/style.css', __FILE__), [], $css_version);
        wp_enqueue_script('vodpress-script', plugins_url('assets/js/script.js', __FILE__), ['jquery'], $js_version, true);

        // add vidstack player
        wp_enqueue_style('vidstack-player-css', plugins_url('assets/css/player.css', __FILE__), [], $css_player);
        wp_enqueue_style('vidstack-plyr-css', 'https://cdn.vidstack.io/plyr.css');
        wp_enqueue_script('vidstack-player-js', 'https://cdn.vidstack.io/player.js', [], null, true);

        wp_localize_script('vodpress-script', 'vodpress', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vodpress_nonce'),
            'i18n' => [
                'submitError' => __('Failed to submit video', 'vodpress'),
                'submitSuccess' => __('Video submitted successfully!', 'vodpress'),
                'queuedAt' => __('Video was added to the queue at position #', 'vodpress'),
            ],
            'pluginUrl' => plugins_url('', __FILE__),
        ]);
    }

    public function admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'vodpress'));
        }
?>
        <div class="wrap vodpress-container">
            <h1><?php _e('VODPress', 'vodpress'); ?></h1>

            <?php if (!defined('VODPRESS_API_KEY')): ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php _e('API Key Missing', 'vodpress'); ?>:</strong>
                        <?php _e('Please define your API key in wp-config.php by adding this line:', 'vodpress'); ?>
                        <br>
                        <code>define('VODPRESS_API_KEY', 'your-api-key-here');</code>
                        <br>
                        <?php _e('Video conversion will not work until the API key is properly configured.', 'vodpress'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="vodpress-form-section">
                <h2><?php _e('Submit Video', 'vodpress'); ?></h2>
                <form id="vodpress-submit-form" <?php echo !defined('VODPRESS_API_KEY') ? 'disabled' : ''; ?>>
                    <table class="form-table">
                        <tr>
                            <th><label for="video_title"><?php _e('Video Title', 'vodpress'); ?></label></th>
                            <td>
                                <input type="text" name="video_title" id="video_title" class="regular-text" required <?php echo !defined('VODPRESS_API_KEY') ? 'disabled' : ''; ?>>
                                <p class="description"><?php _e('Enter a title for this video', 'vodpress'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="video_url"><?php _e('Video URL', 'vodpress'); ?></label></th>
                            <td>
                                <input type="url" name="video_url" id="video_url" class="regular-text" required <?php echo !defined('VODPRESS_API_KEY') ? 'disabled' : ''; ?>>
                                <p class="description"><?php _e('Enter the URL of the video you want to convert', 'vodpress'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="<?php _e('Submit Video', 'vodpress'); ?>" <?php echo !defined('VODPRESS_API_KEY') ? 'disabled' : ''; ?>>
                    </p>
                </form>
                <div id="vodpress-submit-status"></div>
            </div>
            <?php $this->display_videos_table(); ?>

            <div id="vodpress-video-modal" class="vodpress-modal">
                <div class="vodpress-modal-content">
                    <span class="vodpress-close">&times;</span>
                    <h2 id="vodpress-video-title"></h2>
                    <div id="vodpress-video-player"></div>
                </div>
            </div>
        </div>
    <?php
    }

    public function settings_page(): void
    {
    ?>
        <div class="wrap">
            <h1><?php _e('VODPress Settings', 'vodpress'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('vodpress_settings');
                do_settings_sections('vodpress_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th><label><?php _e('API Key', 'vodpress'); ?></label></th>
                        <td>
                            <?php if (defined('VODPRESS_API_KEY')): ?>
                                <p><span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                    <?php _e('API key is defined in wp-config.php', 'vodpress'); ?></p>
                            <?php else: ?>
                                <p><span class="dashicons dashicons-warning" style="color: red;"></span>
                                    <?php _e('API key is not defined. Add the following line to your wp-config.php file:', 'vodpress'); ?></p>
                                <code>define('VODPRESS_API_KEY', 'your-api-key-here');</code>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="vodpress_server_url"><?php _e('Server URL', 'vodpress'); ?></label></th>
                        <td>
                            <input type="url" name="vodpress_server_url" id="vodpress_server_url"
                                value="<?php echo esc_attr(get_option('vodpress_server_url')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="vodpress_public_url_base"><?php _e('Custom S3 domain', 'vodpress'); ?></label></th>
                        <td>
                            <input type="url" name="vodpress_public_url_base" id="vodpress_public_url_base"
                                value="<?php echo esc_attr(get_option('vodpress_public_url_base')); ?>" class="regular-text">
                            <p class="description"><?php _e('(e.g., https://r2.public.com)', 'vodpress'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
    <?php
    }

    private function display_videos_table(): void
    {
    ?>
        <div class="vodpress-videos-section">
            <h2><?php _e('Recent Conversions', 'vodpress'); ?></h2>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <input type="text" id="vodpress-search" placeholder="<?php _e('Search by title...', 'vodpress'); ?>" class="regular-text">
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="v-id-column"><?php _e('ID', 'vodpress'); ?></th>
                        <th class="v-title-column"><?php _e('Title', 'vodpress'); ?></th>
                        <th><?php _e('Video URL', 'vodpress'); ?></th>
                        <th class="status-column"><?php _e('Status', 'vodpress'); ?></th>
                        <th class="duration-column"><?php _e('Duration', 'vodpress'); ?></th>
                        <th class="date-column"><?php _e('Created At', 'vodpress'); ?></th>
                        <th class="date-column"><?php _e('Last Update', 'vodpress'); ?></th>
                        <th class="conversion-colum"><?php _e('Conversion URL', 'vodpress'); ?></th>
                        <th class="original_url"><?php _e('Original Video URL', 'vodpress'); ?></th>
                        <th class="v-action-column"><?php _e('Actions', 'vodpress'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
<?php
    }

    private function get_status_label(string $status): string
    {
        $labels = [
            'pending' => __('Pending', 'vodpress'),
            'queued' => __('In Queue', 'vodpress'),
            'downloading' => __('Downloading', 'vodpress'),
            'uploading-original' => __('Uploading Original Video', 'vodpress'),
            'converting' => __('Converting to HLS', 'vodpress'),
            'uploading' => __('Uploading to S3', 'vodpress'),
            'completed' => __('Completed', 'vodpress'),
            'failed' => __('Failed', 'vodpress')
        ];
        return $labels[$status] ?? $status;
    }

    private function format_date(string $date): string
    {
        $dt = new DateTime($date);
        return $dt->format('Y/m/d H:i');
    }

    private function format_duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '-';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        } else {
            return sprintf('%02d:%02d', $minutes, $secs);
        }
    }

    public function ajax_submit_video(): void
    {
        check_ajax_referer('vodpress_nonce', 'nonce');

        // Check if API key is defined
        if (!defined('VODPRESS_API_KEY')) {
            wp_send_json_error(['message' => __('API key is not defined in wp-config.php', 'vodpress')]);
            return;
        }

        // Check if server URL is configured
        $server_url = get_option('vodpress_server_url');
        if (empty($server_url)) {
            wp_send_json_error(['message' => __('Server URL is not configured', 'vodpress')]);
            return;
        }

        $video_url = esc_url_raw($_POST['video_url'] ?? '');
        $video_title = sanitize_text_field($_POST['video_title'] ?? '');

        if (empty($video_url)) {
            wp_send_json_error(['message' => __('Video URL is required', 'vodpress')]);
            return;
        }

        if (empty($video_title)) {
            wp_send_json_error(['message' => __('Video title is required', 'vodpress')]);
            return;
        }

        $result = $this->send_video($video_url, $video_title);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success(['message' => __('Video submitted successfully!', 'vodpress')]);
    }

    /**
     * @param string $video_url
     * @param string $video_title
     * @return WP_Error|object
     */
    private function send_video(string $video_url, string $video_title = '')
    {
        if (!$this->api_client) {
            // Reinitialize API client if not available
            if (defined('VODPRESS_API_KEY') && get_option('vodpress_server_url')) {
                $this->api_client = new VODPressAPIClient(VODPRESS_API_KEY, get_option('vodpress_server_url'));
            } else {
                return new WP_Error('configuration_error', __('Plugin not properly configured. Please check API key and server URL.', 'vodpress'));
            }
        }

        $validation_result = $this->validate_video_url($video_url);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vodpress_videos';

        // Generate UUID for this video
        $uuid = wp_generate_uuid4();

        $wpdb->insert($table_name, [
            'uuid' => $uuid,
            'video_url' => $video_url,
            'title' => $video_title,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        $video_id = $wpdb->insert_id;
        if (!$video_id) {
            return new WP_Error('db_error', __('Failed to create video record', 'vodpress'));
        }

        $result = $this->api_client->send_video_request($video_url, $uuid);
        if (is_wp_error($result)) {
            $wpdb->update($table_name, ['status' => 'failed', 'error_message' => $result->get_error_message(), 'updated_at' => current_time('mysql')], ['id' => $video_id]);
            return $result;
        }

        // Update record based on API response
        $update_data = ['updated_at' => current_time('mysql')];

        // Check if this video is being processed immediately or added to queue
        if (!empty($result->currently_processing) && $result->currently_processing == $uuid) {
            $update_data['status'] = 'downloading';
        } elseif (isset($result->queue_position)) {
            $update_data['status'] = 'queued';
        }

        $wpdb->update($table_name, $update_data, ['id' => $video_id]);

        return $result;
    }

    private function validate_video_url(string $url): WP_Error|bool 
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('Invalid video URL format', 'vodpress'));
        }

        $response = wp_remote_head($url, ['timeout' => 10, 'sslverify' => true]);
        if (is_wp_error($response)) {
            return new WP_Error('url_not_accessible', __('Video URL is not accessible', 'vodpress'));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if (!in_array($response_code, [200, 206, 301, 302, 303])) {
            return new WP_Error('url_not_accessible', sprintf(__('Video URL returned HTTP error: %d', 'vodpress'), $response_code));
        }

        return true;
    }

    public function ajax_get_videos_status(): void
    {
        check_ajax_referer('vodpress_nonce', 'nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . 'vodpress_videos';

        // Check if we have a search query
        $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        if (!empty($search_term)) {
            $videos = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE title LIKE %s ORDER BY created_at DESC",
                '%' . $wpdb->esc_like($search_term) . '%'
            ));
        } else {
            $videos = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        }

        $video_data = [];
        foreach ($videos as $video) {
            $video_data[] = [
                'id' => $video->id,
                'title' => $video->title,
                'video_url' => $video->video_url,
                'status' => $video->status,
                'status_label' => $this->get_status_label($video->status),
                'duration_formatted' => $this->format_duration($video->duration),
                'created_at' => $this->format_date($video->created_at),
                'updated_at' => $this->format_date($video->updated_at),
                'conversion_url' => $video->conversion_url,
                'original_url' => $video->original_url,
                'error_message' => $video->error_message
            ];
        }

        wp_send_json_success($video_data);
    }

    public function ajax_retry_video(): void
    {
        check_ajax_referer('vodpress_nonce', 'nonce');

        $video_id = intval($_POST['video_id'] ?? 0);
        if (!$video_id) {
            wp_send_json_error(['message' => __('Invalid video ID', 'vodpress')]);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vodpress_videos';
        $video = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $video_id));

        if (!$video) {
            wp_send_json_error(['message' => __('Video not found', 'vodpress')]);
        }

        // For both "pending" and "failed" statuses, use the existing record
        $result = $this->retry_existing_video($video);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Video retry submitted successfully!', 'vodpress')]);
    }

    /**
     * Retry an existing video without creating a new record
     * 
     * @param object $video The video object from the database
     * @return WP_Error|object
     */
    private function retry_existing_video($video)
    {
        if (!$this->api_client) {
            // Reinitialize API client if not available
            if (defined('VODPRESS_API_KEY') && get_option('vodpress_server_url')) {
                $this->api_client = new VODPressAPIClient(VODPRESS_API_KEY, get_option('vodpress_server_url'));
            } else {
                return new WP_Error('configuration_error', __('Plugin not properly configured. Please check API key and server URL.', 'vodpress'));
            }
        }

        // Update the status and updated_at timestamp
        global $wpdb;
        $table_name = $wpdb->prefix . 'vodpress_videos';
        $wpdb->update(
            $table_name,
            [
                'status' => 'pending', // reset status to pending
                'error_message' => null, // clear error message
                'updated_at' => current_time('mysql')
            ],
            ['id' => $video->id]
        );

        // Send the request with the UUID
        $result = $this->api_client->send_video_request($video->video_url, $video->uuid);
        if (is_wp_error($result)) {
            $wpdb->update(
                $table_name,
                [
                    'status' => 'failed',
                    'error_message' => $result->get_error_message(),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $video->id]
            );
            return $result;
        }

        return $result;
    }

    public function ajax_delete_video(): void
    {
        check_ajax_referer('vodpress_nonce', 'nonce');

        $video_id = intval($_POST['video_id'] ?? 0);
        if (!$video_id) {
            wp_send_json_error(['message' => __('Invalid video ID', 'vodpress')]);
        }

        // Get video information before deletion
        global $wpdb;
        $table_name = $wpdb->prefix . 'vodpress_videos';
        $video = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $video_id));

        if (!$video) {
            wp_send_json_error(['message' => __('Video not found', 'vodpress')]);
        }

        if (!$this->api_client) {
            if (defined('VODPRESS_API_KEY') && get_option('vodpress_server_url')) {
                $this->api_client = new VODPressAPIClient(VODPRESS_API_KEY, get_option('vodpress_server_url'));
            } else {
                wp_send_json_error(['message' => __('Plugin not properly configured', 'vodpress')]);
                return;
            }
        }

        $processing_error = false;

        // Always try to remove from queue first (unless it's completed or already failed)
        if ($video->status !== 'completed' && $video->status !== 'failed') {
            $remove_result = $this->api_client->remove_from_queue($video->uuid);

            // Check if the video is currently processing
            if (is_wp_error($remove_result) && $remove_result->get_error_code() === 'video_processing') {
                wp_send_json_error(['message' => $remove_result->get_error_message()]);
                return;
            }
        }

        // If video is completed, also try to delete from S3
        if ($video->status === 'completed' && $video->conversion_url) {
            $delete_result = $this->api_client->delete_video_from_s3($video->uuid);

            if (is_wp_error($delete_result)) {
                // If video is being processed, don't delete from database
                if ($delete_result->get_error_code() === 'video_processing') {
                    wp_send_json_error(['message' => $delete_result->get_error_message()]);
                    return;
                }
                // Otherwise note the error but continue with database deletion
                $processing_error = true;
            }
        }

        // Delete from database
        $result = $wpdb->delete($table_name, ['id' => $video_id]);

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to delete video from database', 'vodpress')]);
        }

        if ($processing_error) {
            wp_send_json_success(['message' => __('Video removed from database but there was an error deleting converted files', 'vodpress')]);
        } else {
            wp_send_json_success(['message' => __('Video deleted successfully', 'vodpress')]);
        }
    }


    public function handle_conversion_callback(WP_REST_Request $request): array|WP_Error
    {
        $provided_hash = $request->get_header('X-API-Key-Hash');
        $stored_api_key = defined('VODPRESS_API_KEY') ? VODPRESS_API_KEY : '';
        $expected_hash = hash('sha256', $stored_api_key);

        if (!$provided_hash || !hash_equals($expected_hash, $provided_hash)) {
            return new WP_Error('unauthorized', 'Invalid API key hash', ['status' => 401]);
        }

        $params = $request->get_json_params();
        $video_uuid = $params['video_uuid'] ?? null;
        $status = $params['status'] ?? null;
        $conversion_url = $params['conversion_url'] ?? null;
        $original_url = $params['original_url'] ?? null;
        $duration = isset($params['duration']) ? intval($params['duration']) : null;

        if (!$video_uuid || !$status) {
            return new WP_Error('invalid_params', __('Invalid parameters', 'vodpress'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vodpress_videos';
        $update_data = [
            'status' => $status,
            'updated_at' => current_time('mysql')
        ];

        if ($conversion_url && is_string($conversion_url)) {
            $public_url_base = get_option('vodpress_public_url_base');
            if ($public_url_base) {
                $path = parse_url($conversion_url, PHP_URL_PATH);
                if ($path !== false) {
                    $update_data['conversion_url'] = rtrim($public_url_base, '/') . $path;
                } else {
                    $update_data['conversion_url'] = $conversion_url;
                }
            } else {
                $update_data['conversion_url'] = $conversion_url;
            }
        }

        if ($original_url && is_string($original_url)) {
            $public_url_base = get_option('vodpress_public_url_base');
            if ($public_url_base) {
                $path = parse_url($original_url, PHP_URL_PATH);
                if ($path !== false) {
                    $update_data['original_url'] = rtrim($public_url_base, '/') . $path;
                } else {
                    $update_data['original_url'] = $original_url;
                }
            } else {
                $update_data['original_url'] = $original_url;
            }
        }

        if ($status === 'failed' && isset($params['error'])) {
            $update_data['error_message'] = sanitize_text_field($params['error']);
        }

        // Update duration if provided
        if ($duration !== null) {
            $update_data['duration'] = $duration;
        }

        $wpdb->update($table_name, $update_data, ['uuid' => $video_uuid]);
        return ['success' => true];
    }
}

class VODPressAPIClient
{
    private $api_key;
    private $server_url;
    private $timeout = 30;
    private $max_retries = 2;

    public function __construct(string $api_key, string $server_url)
    {
        $this->api_key = $api_key;
        $this->server_url = $server_url;
    }

    private function generate_api_key_hash(string $api_key): string
    {
        return hash('sha256', $api_key);
    }

    /**
     * @return WP_Error|object
     */
    public function send_video_request(string $video_url, string $uuid)
    {
        $attempt = 0;
        while ($attempt < $this->max_retries) {
            try {
                $request_data = [
                    'video_url' => $video_url,
                    'video_uuid' => $uuid,
                    'callback_url' => get_rest_url(null, 'vodpress/v1/callback'),
                    'site_url' => get_site_url(),
                    'public_url_base' => get_option('vodpress_public_url_base'),
                    'upload_original' => true
                ];

                $response = wp_remote_post($this->server_url . '/api/convert', [
                    'headers' => [
                        'X-API-Key-Hash' => $this->generate_api_key_hash($this->api_key),
                        'Content-Type' => 'application/json',
                    ],
                    'body' => wp_json_encode($request_data),
                    'timeout' => $this->timeout,
                ]);

                if (is_wp_error($response)) {
                    throw new Exception($response->get_error_message());
                }

                $response_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body);

                if ($response_code !== 200) {
                    throw new Exception("Server returned status code: $response_code");
                }

                return $data;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $this->max_retries) {
                    return new WP_Error('api_error', $e->getMessage());
                }
                sleep(2 * $attempt);
            }
        }
    }

    /**
     * Remove a video from the processing queue
     * @param string $uuid The UUID of the video to remove from queue
     * @return WP_Error|object
     */
    public function remove_from_queue(string $uuid)
    {
        try {
            $request_data = [
                'video_uuid' => $uuid
            ];

            $response = wp_remote_post($this->server_url . '/api/remove-from-queue', [
                'headers' => [
                    'X-API-Key-Hash' => $this->generate_api_key_hash($this->api_key),
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($request_data),
                'timeout' => $this->timeout,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            // If endpoint doesn't exist yet, don't consider it an error
            if ($response_code === 404) {
                return (object) ['success' => false, 'error' => 'Queue management not supported by server'];
            }

            // If video is being processed, return special error
            if ($response_code === 409 && isset($data->is_processing) && $data->is_processing) {
                return new WP_Error('video_processing', __('Video is currently being processed and cannot be removed from queue', 'vodpress'), [
                    'is_processing' => true
                ]);
            }

            if ($response_code !== 200) {
                return new WP_Error('api_error', "Server returned status code: $response_code");
            }

            return $data;
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }

    /**
     * Get queue status from conversion server
     * @return WP_Error|object
     */
    public function get_queue_status()
    {
        try {
            $response = wp_remote_get($this->server_url . '/api/queue-status', [
                'headers' => [
                    'X-API-Key-Hash' => $this->generate_api_key_hash($this->api_key),
                    'Content-Type' => 'application/json',
                ],
                'timeout' => $this->timeout,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            if ($response_code !== 200) {
                return new WP_Error('api_error', "Server returned status code: $response_code");
            }

            return $data;
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage());
        }
    }

    /**
     * Send request to delete video from S3
     * @param string $uuid The UUID of the video to delete
     * @return WP_Error|object
     */
    public function delete_video_from_s3(string $uuid)
    {
        $attempt = 0;
        while ($attempt < $this->max_retries) {
            try {
                $request_data = [
                    'video_uuid' => $uuid
                ];

                $response = wp_remote_post($this->server_url . '/api/delete', [
                    'headers' => [
                        'X-API-Key-Hash' => $this->generate_api_key_hash($this->api_key),
                        'Content-Type' => 'application/json',
                    ],
                    'body' => wp_json_encode($request_data),
                    'timeout' => $this->timeout,
                ]);

                if (is_wp_error($response)) {
                    throw new Exception($response->get_error_message());
                }

                $response_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body);

                // If video is being processed, return special error
                if ($response_code === 409 && isset($data->is_processing) && $data->is_processing) {
                    return new WP_Error('video_processing', __('Video is currently being processed and cannot be deleted', 'vodpress'));
                }

                if ($response_code !== 200) {
                    throw new Exception("Server returned status code: $response_code");
                }

                return $data;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $this->max_retries) {
                    return new WP_Error('api_error', $e->getMessage());
                }
                sleep(2 * $attempt);
            }
        }
    }
}

register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table_name = $wpdb->prefix . 'vodpress_videos';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        uuid varchar(36) DEFAULT NULL,
        video_url text NOT NULL,
        title varchar(255) DEFAULT '',
        status varchar(50) NOT NULL DEFAULT 'pending',
        conversion_url text,
        original_url text,
        error_message text,
        duration int DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uuid (uuid)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    
    // Check if uuid column exists, if not add it to existing table
    $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_name}' AND COLUMN_NAME = 'uuid'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN uuid varchar(36) DEFAULT NULL, ADD UNIQUE KEY uuid (uuid)");
    }
    
    // Generate UUIDs for existing records that don't have one
    $videos = $wpdb->get_results("SELECT id FROM {$table_name} WHERE uuid IS NULL OR uuid = ''");
    foreach ($videos as $video) {
        $uuid = wp_generate_uuid4();
        $wpdb->update($table_name, ['uuid' => $uuid], ['id' => $video->id]);
    }
});

add_action('plugins_loaded', function () {
    VODPress::get_instance();
});

add_action('admin_notices', function () {
    if ($notice = get_transient('vodpress_admin_notice')) {
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), wp_kses_post($notice['message']));
        delete_transient('vodpress_admin_notice');
    }
});