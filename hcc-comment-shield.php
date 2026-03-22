<?php
/**
 * Plugin Name: HCC Comment Shield
 * Plugin URI: https://github.com/juliansebastien-rgb
 * Description: Shared anti-spam comment scoring powered by the HCC trust service.
 * Version: 1.2.3
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hcc-comment-shield
 * Update URI: https://github.com/juliansebastien-rgb/HCC-Comment-Shield
 */

if (!defined('ABSPATH')) {
    exit;
}

final class HCC_Comment_Shield {
    private const VERSION = '1.2.3';
    private const TRANSIENT_PREFIX = 'hcc_comment_shield_';
    private const GITHUB_REPOSITORY = 'juliansebastien-rgb/HCC-Comment-Shield';
    private const GITHUB_API_BASE = 'https://api.github.com/repos/juliansebastien-rgb/HCC-Comment-Shield';
    private const GITHUB_REPOSITORY_URL = 'https://github.com/juliansebastien-rgb/HCC-Comment-Shield';
    private const UPDATE_CACHE_TTL = HOUR_IN_SECONDS;
    private const META_FEEDBACK = '_hcc_comment_feedback';
    private const SERVICE_URL = 'https://trust.mapage-wp.online';
    private const WEEKLY_EVENT = 'hcc_comment_shield_weekly_summary';
    private const OPTION_MODE = 'hcc_comment_shield_mode';
    private const OPTION_MODERATE = 'hcc_comment_shield_moderate_medium_risk';
    private const OPTION_SPAM = 'hcc_comment_shield_mark_high_risk_spam';
    private const OPTION_TIMEOUT = 'hcc_comment_shield_timeout';
    private const OPTION_WEEKLY_EMAIL_ENABLED = 'hcc_comment_shield_weekly_email_enabled';
    private const OPTION_WEEKLY_EMAIL_RECIPIENT = 'hcc_comment_shield_weekly_email_recipient';
    private const OPTION_WHITELIST_EMAILS = 'hcc_comment_shield_whitelist_emails';
    private const OPTION_WHITELIST_DOMAINS = 'hcc_comment_shield_whitelist_domains';
    private const OPTION_BLACKLIST_DOMAINS = 'hcc_comment_shield_blacklist_domains';
    private const OPTION_BLACKLIST_KEYWORDS = 'hcc_comment_shield_blacklist_keywords';
    private const META_SCORE = '_hcc_comment_score';
    private const META_ACTION = '_hcc_comment_action';
    private const META_FLAGS = '_hcc_comment_flags';
    private const META_REASONS = '_hcc_comment_reasons';

    /** @var array<string,mixed>|null */
    private ?array $last_decision = null;

    public function boot(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'ensure_weekly_event']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
        add_filter('cron_schedules', [$this, 'register_cron_schedule']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_github_update']);
        add_filter('plugins_api', [$this, 'filter_plugin_information'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_github_update_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);

        add_filter('preprocess_comment', [$this, 'analyze_comment'], 20);
        add_filter('pre_comment_approved', [$this, 'filter_comment_approval'], 20, 2);
        add_action('comment_post', [$this, 'persist_comment_meta'], 20, 3);
        add_action('transition_comment_status', [$this, 'handle_comment_status_transition'], 20, 3);
        add_action(self::WEEKLY_EVENT, [$this, 'send_weekly_summary_email']);
    }

    public function activate(): void {
        if (!wp_next_scheduled(self::WEEKLY_EVENT)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', self::WEEKLY_EVENT);
        }
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook(self::WEEKLY_EVENT);
    }

    public function ensure_weekly_event(): void {
        if (!wp_next_scheduled(self::WEEKLY_EVENT)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', self::WEEKLY_EVENT);
        }
    }

    public function register_settings(): void {
        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_MODE,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_mode'],
                'default' => 'balanced',
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_MODERATE,
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox'],
                'default' => true,
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_SPAM,
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox'],
                'default' => true,
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_TIMEOUT,
            [
                'type' => 'integer',
                'sanitize_callback' => [$this, 'sanitize_timeout'],
                'default' => 10,
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_WEEKLY_EMAIL_ENABLED,
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox'],
                'default' => true,
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_WEEKLY_EMAIL_RECIPIENT,
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
                'default' => get_option('admin_email', ''),
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_WHITELIST_EMAILS,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_multiline_text'],
                'default' => '',
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_WHITELIST_DOMAINS,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_multiline_text'],
                'default' => '',
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_BLACKLIST_DOMAINS,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_multiline_text'],
                'default' => '',
            ]
        );

        register_setting(
            'hcc_comment_shield_settings',
            self::OPTION_BLACKLIST_KEYWORDS,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_multiline_text'],
                'default' => '',
            ]
        );
    }

    public function register_settings_page(): void {
        add_options_page(
            'HCC Comment Shield',
            'HCC Comment Shield',
            'manage_options',
            'hcc-comment-shield',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'options-general.php',
            'HCC Comment Shield Logs',
            'HCC Comment Shield Logs',
            'manage_options',
            'hcc-comment-shield-logs',
            [$this, 'render_logs_page']
        );
    }

    public function sanitize_checkbox($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return !empty($value);
    }

    public function sanitize_timeout($value): int {
        $timeout = (int) $value;
        if ($timeout < 3) {
            return 3;
        }
        if ($timeout > 30) {
            return 30;
        }
        return $timeout;
    }

    public function sanitize_mode($value): string {
        $value = is_string($value) ? strtolower(trim($value)) : 'balanced';
        return in_array($value, ['tolerant', 'balanced', 'strict'], true) ? $value : 'balanced';
    }

    public function sanitize_multiline_text($value): string {
        if (!is_string($value)) {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $clean = [];

        foreach ($lines as $line) {
            $line = trim(wp_strip_all_tags($line));
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return implode("\n", array_values(array_unique($clean)));
    }

    public function plugin_action_links(array $links): array {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=hcc-comment-shield')) . '">Settings</a>');
        return $links;
    }

    public function inject_github_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release_data();
        if (!$release || empty($release['version'])) {
            return $transient;
        }

        if (version_compare(self::VERSION, $release['version'], '>=')) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        $update = (object) [
            'slug' => 'hcc-comment-shield',
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'tested' => '6.9',
            'requires_php' => '7.4',
            'compatibility' => new stdClass(),
        ];

        $transient->response[$plugin_file] = $update;

        return $transient;
    }

    public function filter_plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== 'hcc-comment-shield') {
            return $result;
        }

        $release = $this->get_github_release_data();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'HCC Comment Shield',
            'slug' => 'hcc-comment-shield',
            'version' => $release['version'],
            'author' => '<a href="https://github.com/juliansebastien-rgb">Le Labo d&#039;Azertaf</a>',
            'author_profile' => 'https://github.com/juliansebastien-rgb',
            'homepage' => self::GITHUB_REPOSITORY_URL,
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => '6.9',
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Standalone WordPress comment anti-spam powered by the HCC trust service.',
                'installation' => 'Install the plugin, activate it, then configure the protection mode, local rules, dashboard widget usage and weekly summary email in Settings > HCC Comment Shield.',
                'changelog' => sprintf("= %s =\n* GitHub release package.\n", $release['version']),
            ],
            'banners' => [],
            'icons' => [],
        ];
    }

    public function clear_update_cache($upgrader, array $hook_extra): void {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = $hook_extra['plugins'] ?? [];

        if (in_array(plugin_basename(__FILE__), $plugins, true)) {
            delete_transient(self::TRANSIENT_PREFIX . 'github_release');
        }
    }

    public function normalize_github_update_source(string $source, string $remote_source, $upgrader, array $hook_extra): string {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return $source;
        }

        $plugins = $hook_extra['plugins'] ?? [];
        if (!in_array(plugin_basename(__FILE__), $plugins, true)) {
            return $source;
        }

        $normalized = trailingslashit($remote_source) . 'hcc-comment-shield';

        if ($source === $normalized || !is_dir($source)) {
            return $source;
        }

        if (@rename($source, $normalized)) {
            return $normalized;
        }

        return $source;
    }

    private function get_github_release_data(): ?array {
        $cache_key = self::TRANSIENT_PREFIX . 'github_release';
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $release = $this->request_github_release('/releases/latest');

        if (!$release) {
            $tag = $this->request_github_release('/tags');
            if (!$tag || empty($tag[0]['name'])) {
                return null;
            }

            $first_tag = $tag[0];
            $release = [
                'tag_name' => $first_tag['name'],
                'zipball_url' => self::GITHUB_API_BASE . '/zipball/' . rawurlencode($first_tag['name']),
                'html_url' => self::GITHUB_REPOSITORY_URL . '/releases/tag/' . rawurlencode($first_tag['name']),
                'published_at' => gmdate('Y-m-d H:i:s'),
                'body' => '',
            ];
        }

        if (empty($release['tag_name'])) {
            return null;
        }

        $package = '';
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $name = isset($asset['name']) ? (string) $asset['name'] : '';
                $download = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

                if ($name !== '' && substr($name, -4) === '.zip' && $download !== '') {
                    $package = $download;
                    break;
                }
            }
        }

        if ($package === '' && !empty($release['zipball_url'])) {
            $package = (string) $release['zipball_url'];
        }

        if ($package === '') {
            return null;
        }

        $data = [
            'version' => ltrim((string) $release['tag_name'], 'v'),
            'package' => $package,
            'url' => !empty($release['html_url']) ? (string) $release['html_url'] : self::GITHUB_REPOSITORY_URL,
            'published_at' => !empty($release['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $release['published_at'])) : gmdate('Y-m-d H:i:s'),
            'body' => !empty($release['body']) ? (string) $release['body'] : '',
        ];

        set_transient($cache_key, $data, self::UPDATE_CACHE_TTL);

        return $data;
    }

    private function request_github_release(string $path) {
        $response = wp_remote_get(
            self::GITHUB_API_BASE . $path,
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'HCC Comment Shield/' . self::VERSION . '; ' . home_url('/'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }

    public function register_cron_schedule(array $schedules): array {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display' => 'Once Weekly',
            ];
        }

        return $schedules;
    }

    public function register_dashboard_widget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'hcc_comment_shield_ai_tips',
            'HCC AI Tips',
            [$this, 'render_dashboard_widget']
        );
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $site_url = home_url('/');
        $mode = $this->get_mode();
        $logo_url = plugin_dir_url(__FILE__) . 'assets/images/hcc-comment-shield-logo.png';

        ?>
        <div class="wrap">
            <p style="margin:16px 0 12px;">
                <img src="<?php echo esc_url($logo_url); ?>" alt="HCC Comment Shield" style="width:120px;height:auto;display:block;" />
            </p>
            <h1>HCC Comment Shield</h1>
            <p>Shared anti-spam comment scoring powered by the HCC trust service.</p>
            <form method="post" action="options.php">
                <?php settings_fields('hcc_comment_shield_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Trust service URL</th>
                        <td>
                            <input type="text" class="regular-text" value="<?php echo esc_attr(self::SERVICE_URL); ?>" readonly disabled />
                            <p class="description">Managed by the plugin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Detected site URL</th>
                        <td>
                            <input type="text" class="regular-text" value="<?php echo esc_attr($site_url); ?>" readonly disabled />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Protection mode</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_MODE); ?>">
                                <option value="tolerant" <?php selected($mode, 'tolerant'); ?>>Tolerant</option>
                                <option value="balanced" <?php selected($mode, 'balanced'); ?>>Balanced</option>
                                <option value="strict" <?php selected($mode, 'strict'); ?>>Strict</option>
                            </select>
                            <p class="description">Balanced is recommended. Strict blocks and moderates more aggressively.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Moderate medium-risk comments</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_MODERATE); ?>" value="1" <?php checked($this->should_moderate_medium_risk()); ?> />
                                Send medium-risk comments to moderation.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Mark high-risk comments as spam</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_SPAM); ?>" value="1" <?php checked($this->should_mark_high_risk_spam()); ?> />
                                Mark high-risk comments as spam automatically.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Request timeout</th>
                        <td>
                            <input type="number" min="3" max="30" name="<?php echo esc_attr(self::OPTION_TIMEOUT); ?>" value="<?php echo esc_attr((string) $this->get_timeout()); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Weekly summary email</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_WEEKLY_EMAIL_ENABLED); ?>" value="1" <?php checked($this->is_weekly_email_enabled()); ?> />
                                Send a weekly HCC anti-spam summary email.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Weekly summary recipient</th>
                        <td>
                            <input type="email" class="regular-text" name="<?php echo esc_attr(self::OPTION_WEEKLY_EMAIL_RECIPIENT); ?>" value="<?php echo esc_attr($this->get_weekly_email_recipient()); ?>" />
                            <p class="description">Default: WordPress admin email.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Whitelist emails</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPTION_WHITELIST_EMAILS); ?>" rows="5" cols="50" class="large-text code"><?php echo esc_textarea($this->get_option_text(self::OPTION_WHITELIST_EMAILS)); ?></textarea>
                            <p class="description">One email per line. Matching emails are always allowed.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Whitelist domains</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPTION_WHITELIST_DOMAINS); ?>" rows="5" cols="50" class="large-text code"><?php echo esc_textarea($this->get_option_text(self::OPTION_WHITELIST_DOMAINS)); ?></textarea>
                            <p class="description">One domain per line, for example <code>example.com</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Blacklist domains</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPTION_BLACKLIST_DOMAINS); ?>" rows="5" cols="50" class="large-text code"><?php echo esc_textarea($this->get_option_text(self::OPTION_BLACKLIST_DOMAINS)); ?></textarea>
                            <p class="description">One domain per line. Matching domains are treated as spam.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Blacklist keywords</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPTION_BLACKLIST_KEYWORDS); ?>" rows="5" cols="50" class="large-text code"><?php echo esc_textarea($this->get_option_text(self::OPTION_BLACKLIST_KEYWORDS)); ?></textarea>
                            <p class="description">One keyword per line. Matching comments are treated as spam.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>
        </div>
        <?php
    }

    public function render_logs_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $comments = get_comments([
            'number' => 100,
            'status' => 'all',
            'orderby' => 'comment_ID',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => self::META_ACTION,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);
        ?>
        <div class="wrap">
            <h1>HCC Comment Shield Logs</h1>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Author</th>
                        <th>Email</th>
                        <th>Post</th>
                        <th>Score</th>
                        <th>Action</th>
                        <th>Flags</th>
                        <th>Reasons</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($comments)) : ?>
                        <tr><td colspan="8">No comment scoring logs yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ($comments as $comment) : ?>
                            <?php
                            $score = get_comment_meta($comment->comment_ID, self::META_SCORE, true);
                            $action = get_comment_meta($comment->comment_ID, self::META_ACTION, true);
                            $flags = get_comment_meta($comment->comment_ID, self::META_FLAGS, true);
                            $reasons = get_comment_meta($comment->comment_ID, self::META_REASONS, true);
                            $feedback = get_comment_meta($comment->comment_ID, self::META_FEEDBACK, true);
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $comment->comment_date); ?></td>
                                <td><?php echo esc_html((string) $comment->comment_author); ?></td>
                                <td><?php echo esc_html((string) $comment->comment_author_email); ?></td>
                                <td><?php echo esc_html(get_the_title((int) $comment->comment_post_ID)); ?></td>
                                <td><?php echo esc_html((string) $score); ?></td>
                                <td><?php echo esc_html((string) $action); ?><?php if ($feedback !== '') : ?><br><small>feedback: <?php echo esc_html((string) $feedback); ?></small><?php endif; ?></td>
                                <td><code><?php echo esc_html((string) $flags); ?></code></td>
                                <td><code><?php echo esc_html((string) $reasons); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_dashboard_widget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stats = $this->build_recent_stats(7, 50);
        $tips = $this->build_ai_tips($stats);
        $top_domain = array_key_first($stats['problem_domains']);
        $top_flag = array_key_first($stats['problem_flags']);

        echo '<p>Recent anti-spam snapshot for this site.</p>';
        echo '<ul>';
        echo '<li><strong>Allow:</strong> ' . esc_html((string) $stats['allow']) . '</li>';
        echo '<li><strong>Moderate:</strong> ' . esc_html((string) $stats['moderate']) . '</li>';
        echo '<li><strong>Spam:</strong> ' . esc_html((string) $stats['spam']) . '</li>';
        echo '<li><strong>Admin spam confirmations:</strong> ' . esc_html((string) $stats['feedback_spam']) . '</li>';
        echo '</ul>';

        echo '<p><strong>AI tips</strong></p><ul>';
        foreach ($tips as $tip) {
            echo '<li>' . esc_html($tip) . '</li>';
        }
        echo '</ul>';

        if ($top_domain) {
            echo '<p><strong>Top spam domain:</strong> <code>' . esc_html($top_domain) . '</code></p>';
        }

        if ($top_flag) {
            echo '<p><strong>Top detected pattern:</strong> <code>' . esc_html($top_flag) . '</code></p>';
        }

        echo '<p><a href="' . esc_url(admin_url('options-general.php?page=hcc-comment-shield-logs')) . '">Open HCC Comment Shield Logs</a></p>';
    }

    /**
     * @param array<string,mixed> $commentdata
     * @return array<string,mixed>
     */
    public function analyze_comment(array $commentdata): array {
        $this->last_decision = null;

        if (is_admin() && !wp_doing_ajax()) {
            return $commentdata;
        }

        if (empty($commentdata['comment_post_ID']) || !empty($commentdata['comment_type'])) {
            return $commentdata;
        }

        $payload = [
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'context' => 'comment',
            'post_url' => !empty($commentdata['comment_post_ID']) ? get_permalink((int) $commentdata['comment_post_ID']) : '',
            'post_title' => !empty($commentdata['comment_post_ID']) ? get_the_title((int) $commentdata['comment_post_ID']) : '',
            'author_name' => isset($commentdata['comment_author']) ? (string) $commentdata['comment_author'] : '',
            'user_email' => isset($commentdata['comment_author_email']) ? (string) $commentdata['comment_author_email'] : '',
            'author_url' => isset($commentdata['comment_author_url']) ? (string) $commentdata['comment_author_url'] : '',
            'comment_content' => isset($commentdata['comment_content']) ? (string) $commentdata['comment_content'] : '',
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'language' => function_exists('determine_locale') ? determine_locale() : get_locale(),
        ];

        $email = strtolower(trim((string) $payload['user_email']));
        $email_domain = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : '';
        $keyword_match = $this->find_blacklist_keyword((string) $payload['comment_content']);

        if ($email !== '' && in_array($email, $this->get_whitelist_emails(), true)) {
            $this->last_decision = [
                'ok' => true,
                'trust_score' => 100,
                'recommended_action' => 'allow',
                'flags' => ['whitelist_email'],
                'reasons' => ['Author email is whitelisted locally.'],
            ];
            return $commentdata;
        }

        if ($email_domain !== '' && in_array($email_domain, $this->get_whitelist_domains(), true)) {
            $this->last_decision = [
                'ok' => true,
                'trust_score' => 100,
                'recommended_action' => 'allow',
                'flags' => ['whitelist_domain'],
                'reasons' => ['Author email domain is whitelisted locally.'],
            ];
            return $commentdata;
        }

        if ($email_domain !== '' && in_array($email_domain, $this->get_blacklist_domains(), true)) {
            $this->last_decision = [
                'ok' => true,
                'trust_score' => 0,
                'recommended_action' => 'spam',
                'flags' => ['blacklist_domain'],
                'reasons' => ['Author email domain is blacklisted locally.'],
            ];
            return $commentdata;
        }

        if ($keyword_match !== '') {
            $this->last_decision = [
                'ok' => true,
                'trust_score' => 0,
                'recommended_action' => 'spam',
                'flags' => ['blacklist_keyword'],
                'reasons' => ['Comment contains a locally blacklisted keyword: ' . $keyword_match],
            ];
            return $commentdata;
        }

        $response = wp_remote_post(
            trailingslashit(self::SERVICE_URL) . 'v1/comment-score',
            [
                'timeout' => $this->get_timeout(),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return $commentdata;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['ok'])) {
            return $commentdata;
        }

        $this->last_decision = $this->apply_mode_to_decision($data);

        return $commentdata;
    }

    /**
     * @param string|int $approved
     * @param array<string,mixed> $commentdata
     * @return string|int
     */
    public function filter_comment_approval($approved, array $commentdata) {
        if (!$this->last_decision || empty($this->last_decision['recommended_action'])) {
            return $approved;
        }

        $action = (string) $this->last_decision['recommended_action'];

        if ($action === 'spam' && $this->should_mark_high_risk_spam()) {
            return 'spam';
        }

        if ($action === 'moderate' && $this->should_moderate_medium_risk()) {
            return 0;
        }

        return $approved;
    }

    public function persist_comment_meta(int $comment_id, $comment_approved, array $commentdata): void {
        if (!$this->last_decision) {
            return;
        }

        add_comment_meta($comment_id, self::META_SCORE, (int) ($this->last_decision['trust_score'] ?? 0), true);
        add_comment_meta($comment_id, self::META_ACTION, (string) ($this->last_decision['recommended_action'] ?? ''), true);
        add_comment_meta($comment_id, self::META_FLAGS, wp_json_encode($this->last_decision['flags'] ?? []), true);
        add_comment_meta($comment_id, self::META_REASONS, wp_json_encode($this->last_decision['reasons'] ?? []), true);
    }

    public function handle_comment_status_transition(string $new_status, string $old_status, WP_Comment $comment): void {
        if ($new_status === $old_status) {
            return;
        }

        $feedback = '';

        if ($new_status === 'spam') {
            $feedback = 'spam_confirmed';
        } elseif ($new_status === 'approved' && in_array($old_status, ['hold', '0', 'unapproved'], true)) {
            $feedback = 'approved';
        } elseif ($new_status === 'approved' && $old_status === 'spam') {
            $feedback = 'false_positive';
        }

        if ($feedback === '') {
            return;
        }

        update_comment_meta($comment->comment_ID, self::META_FEEDBACK, $feedback);

        $payload = [
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'comment_id' => (int) $comment->comment_ID,
            'post_id' => (int) $comment->comment_post_ID,
            'post_title' => get_the_title((int) $comment->comment_post_ID),
            'user_email' => (string) $comment->comment_author_email,
            'author_url' => (string) $comment->comment_author_url,
            'comment_content' => (string) $comment->comment_content,
            'ip' => (string) $comment->comment_author_IP,
            'user_agent' => (string) $comment->comment_agent,
            'feedback' => $feedback,
        ];

        wp_remote_post(
            trailingslashit(self::SERVICE_URL) . 'v1/comment-feedback',
            [
                'timeout' => $this->get_timeout(),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );
    }

    public function send_weekly_summary_email(): void {
        if (!$this->is_weekly_email_enabled()) {
            return;
        }

        $recipient = $this->get_weekly_email_recipient();
        if ($recipient === '') {
            return;
        }

        $stats = $this->build_recent_stats(7, 250);
        $tips = $this->build_ai_tips($stats);
        $subject = sprintf('HCC Weekly Anti-Spam Summary - %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));

        $html = '<html><body style="font-family:Arial,sans-serif;background:#f5f7fb;color:#0f172a;padding:24px;">';
        $html .= '<div style="max-width:760px;margin:0 auto;background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:24px;">';
        $html .= '<h1 style="margin-top:0;">HCC Weekly Anti-Spam Summary</h1>';
        $html .= '<p style="color:#475569;">Site: <strong>' . esc_html(home_url('/')) . '</strong><br>Period: last 7 days</p>';
        $html .= '<table style="width:100%;border-collapse:collapse;margin:18px 0;"><tr>';
        $html .= '<td style="padding:12px;border:1px solid #e5e7eb;"><strong>Allow</strong><br>' . esc_html((string) $stats['allow']) . '</td>';
        $html .= '<td style="padding:12px;border:1px solid #e5e7eb;"><strong>Moderate</strong><br>' . esc_html((string) $stats['moderate']) . '</td>';
        $html .= '<td style="padding:12px;border:1px solid #e5e7eb;"><strong>Spam</strong><br>' . esc_html((string) $stats['spam']) . '</td>';
        $html .= '<td style="padding:12px;border:1px solid #e5e7eb;"><strong>Confirmed spam</strong><br>' . esc_html((string) $stats['feedback_spam']) . '</td>';
        $html .= '</tr></table>';

        $html .= '<h2 style="font-size:18px;">HCC AI Tips</h2><ul>';
        foreach ($tips as $tip) {
            $html .= '<li>' . esc_html($tip) . '</li>';
        }
        $html .= '</ul>';

        if (!empty($stats['problem_domains'])) {
            $html .= '<h2 style="font-size:18px;">Top spam domains</h2><ul>';
            foreach (array_slice($stats['problem_domains'], 0, 5, true) as $domain => $count) {
                $html .= '<li><code>' . esc_html($domain) . '</code> - ' . esc_html((string) $count) . ' confirmations</li>';
            }
            $html .= '</ul>';
        }

        if (!empty($stats['problem_flags'])) {
            $html .= '<h2 style="font-size:18px;">Top patterns</h2><ul>';
            foreach (array_slice($stats['problem_flags'], 0, 5, true) as $flag => $count) {
                $html .= '<li><code>' . esc_html($flag) . '</code> - ' . esc_html((string) $count) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<p style="margin-top:24px;"><a href="' . esc_url(admin_url('options-general.php?page=hcc-comment-shield-logs')) . '" style="display:inline-block;background:#0f172a;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;">Open HCC Comment Shield Logs</a></p>';
        $html .= '</div></body></html>';

        wp_mail(
            $recipient,
            $subject,
            $html,
            ['Content-Type: text/html; charset=UTF-8']
        );
    }

    /**
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    private function apply_mode_to_decision(array $decision): array {
        $score = isset($decision['trust_score']) ? (int) $decision['trust_score'] : 0;
        $mode = $this->get_mode();

        if ($mode === 'strict') {
            if ($score < 45) {
                $decision['recommended_action'] = 'spam';
            } elseif ($score < 70) {
                $decision['recommended_action'] = 'moderate';
            } else {
                $decision['recommended_action'] = 'allow';
            }
            $decision['flags'][] = 'strict_mode';
        } elseif ($mode === 'tolerant') {
            if ($score < 25) {
                $decision['recommended_action'] = 'spam';
            } elseif ($score < 50) {
                $decision['recommended_action'] = 'moderate';
            } else {
                $decision['recommended_action'] = 'allow';
            }
            $decision['flags'][] = 'tolerant_mode';
        }

        return $decision;
    }

    private function get_mode(): string {
        $value = get_option(self::OPTION_MODE, 'balanced');
        return is_string($value) ? $value : 'balanced';
    }

    private function is_weekly_email_enabled(): bool {
        return !empty(get_option(self::OPTION_WEEKLY_EMAIL_ENABLED, true));
    }

    private function get_weekly_email_recipient(): string {
        $value = get_option(self::OPTION_WEEKLY_EMAIL_RECIPIENT, get_option('admin_email', ''));
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return string[]
     */
    private function get_whitelist_emails(): array {
        return $this->split_option_lines(self::OPTION_WHITELIST_EMAILS);
    }

    /**
     * @return string[]
     */
    private function get_whitelist_domains(): array {
        return $this->split_option_lines(self::OPTION_WHITELIST_DOMAINS);
    }

    /**
     * @return string[]
     */
    private function get_blacklist_domains(): array {
        return $this->split_option_lines(self::OPTION_BLACKLIST_DOMAINS);
    }

    /**
     * @return string[]
     */
    private function get_blacklist_keywords(): array {
        return $this->split_option_lines(self::OPTION_BLACKLIST_KEYWORDS);
    }

    private function get_option_text(string $option): string {
        $value = get_option($option, '');
        return is_string($value) ? $value : '';
    }

    /**
     * @return string[]
     */
    private function split_option_lines(string $option): array {
        $value = strtolower($this->get_option_text($option));
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, static fn($line) => $line !== '');
        return array_values(array_unique($lines));
    }

    private function find_blacklist_keyword(string $comment_content): string {
        $haystack = strtolower($comment_content);
        foreach ($this->get_blacklist_keywords() as $keyword) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return $keyword;
            }
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function build_recent_stats(int $days = 7, int $limit = 50): array {
        $comments = get_comments([
            'number' => $limit,
            'status' => 'all',
            'orderby' => 'comment_ID',
            'order' => 'DESC',
            'date_query' => [
                [
                    'after' => gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS)),
                ],
            ],
            'meta_query' => [
                [
                    'key' => self::META_ACTION,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $stats = [
            'spam' => 0,
            'moderate' => 0,
            'allow' => 0,
            'feedback_spam' => 0,
            'problem_domains' => [],
            'problem_flags' => [],
        ];

        foreach ($comments as $comment) {
            $action = (string) get_comment_meta($comment->comment_ID, self::META_ACTION, true);
            $feedback = (string) get_comment_meta($comment->comment_ID, self::META_FEEDBACK, true);
            $flags_json = (string) get_comment_meta($comment->comment_ID, self::META_FLAGS, true);
            $email = strtolower((string) $comment->comment_author_email);
            $domain = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : '';

            if (isset($stats[$action])) {
                $stats[$action]++;
            }

            if ($feedback === 'spam_confirmed') {
                $stats['feedback_spam']++;
                if ($domain !== '') {
                    $stats['problem_domains'][$domain] = ($stats['problem_domains'][$domain] ?? 0) + 1;
                }
            }

            $flags = json_decode($flags_json, true);
            if (is_array($flags)) {
                foreach ($flags as $flag) {
                    if (!is_string($flag) || $flag === '') {
                        continue;
                    }
                    $stats['problem_flags'][$flag] = ($stats['problem_flags'][$flag] ?? 0) + 1;
                }
            }
        }

        arsort($stats['problem_domains']);
        arsort($stats['problem_flags']);

        return $stats;
    }

    /**
     * @param array<string,mixed> $stats
     * @return string[]
     */
    private function build_ai_tips(array $stats): array {
        $tips = [];

        if (($stats['moderate'] ?? 0) >= 8) {
            $tips[] = 'Many comments are landing in moderation. If that creates too much manual work, try Strict mode for a week.';
        }

        if (($stats['spam'] ?? 0) >= 10) {
            $tips[] = 'Spam pressure is elevated this week. Keep an eye on your top domains and consider tightening local blacklists.';
        }

        if (($stats['feedback_spam'] ?? 0) >= 5) {
            $tips[] = 'You confirmed several spam comments recently. HCC is now learning those patterns across your sites.';
        }

        $top_domain = array_key_first($stats['problem_domains'] ?? []);
        if ($top_domain) {
            $tips[] = 'The domain ' . $top_domain . ' keeps appearing in confirmed spam. Consider adding it to the local blacklist.';
        }

        $top_flag = array_key_first($stats['problem_flags'] ?? []);
        if ($top_flag === 'has_link' || $top_flag === 'many_links' || $top_flag === 'high_link_density') {
            $tips[] = 'Links are still the main spam signal. Keep automatic spam marking enabled for high-risk comments.';
        } elseif ($top_flag === 'confirmed_spam_pattern' || $top_flag === 'reused_comment_text') {
            $tips[] = 'Repeated comment texts are being detected. Shared learning across sites is actively helping here.';
        } elseif ($top_flag === 'confirmed_spam_email_domain' || $top_flag === 'confirmed_spam_ip' || $top_flag === 'confirmed_spam_author_domain') {
            $tips[] = 'Sender reputation is now part of the model. Repeat offender emails, IPs and URLs should be blocked faster over time.';
        } elseif ($top_flag === 'spam_keywords' || $top_flag === 'spam_keyword') {
            $tips[] = 'Recurring spam keywords are showing up. Add the most obvious terms to the local blacklist for immediate blocking.';
        } elseif ($top_flag === 'confirmed_spam_email' || $top_flag === 'seen_in_spam_ip' || $top_flag === 'seen_in_spam_email_domain') {
            $tips[] = 'The shared reputation layer is catching repeat senders. The more feedback you confirm, the faster those senders are downgraded.';
        }

        if (empty($tips)) {
            $tips[] = 'Nothing abnormal stands out right now. Balanced mode remains the safest default.';
        }

        return $tips;
    }

    private function should_moderate_medium_risk(): bool {
        return !empty(get_option(self::OPTION_MODERATE, true));
    }

    private function should_mark_high_risk_spam(): bool {
        return !empty(get_option(self::OPTION_SPAM, true));
    }

    private function get_timeout(): int {
        return (int) get_option(self::OPTION_TIMEOUT, 10);
    }
}

(new HCC_Comment_Shield())->boot();
