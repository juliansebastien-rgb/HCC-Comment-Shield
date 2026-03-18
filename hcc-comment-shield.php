<?php
/**
 * Plugin Name: HCC Comment Shield
 * Plugin URI: https://github.com/juliansebastien-rgb
 * Description: Shared anti-spam comment scoring powered by the HCC trust service.
 * Version: 0.1.0
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hcc-comment-shield
 */

if (!defined('ABSPATH')) {
    exit;
}

final class HCC_Comment_Shield {
    private const VERSION = '0.1.0';
    private const SERVICE_URL = 'https://trust.mapage-wp.online';
    private const OPTION_MODERATE = 'hcc_comment_shield_moderate_medium_risk';
    private const OPTION_SPAM = 'hcc_comment_shield_mark_high_risk_spam';
    private const OPTION_TIMEOUT = 'hcc_comment_shield_timeout';
    private const META_SCORE = '_hcc_comment_score';
    private const META_ACTION = '_hcc_comment_action';
    private const META_FLAGS = '_hcc_comment_flags';

    /** @var array<string,mixed>|null */
    private ?array $last_decision = null;

    public function boot(): void {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);

        add_filter('preprocess_comment', [$this, 'analyze_comment'], 20);
        add_filter('pre_comment_approved', [$this, 'filter_comment_approval'], 20, 2);
        add_action('comment_post', [$this, 'persist_comment_meta'], 20, 3);
    }

    public function register_settings(): void {
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
    }

    public function register_settings_page(): void {
        add_options_page(
            'HCC Comment Shield',
            'HCC Comment Shield',
            'manage_options',
            'hcc-comment-shield',
            [$this, 'render_settings_page']
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

    public function plugin_action_links(array $links): array {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=hcc-comment-shield')) . '">Settings</a>');
        return $links;
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
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
                </table>
                <?php submit_button('Save settings'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $commentdata
     * @return array<string,mixed>
     */
    public function analyze_comment(array $commentdata): array {
        $this->last_decision = null;

        if (is_admin()) {
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

        $this->last_decision = $data;

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
