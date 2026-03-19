<?php
/**
 * Plugin Name: HCC Comment Shield
 * Plugin URI: https://github.com/juliansebastien-rgb
 * Description: Shared anti-spam comment scoring powered by the HCC trust service.
 * Version: 1.0.0
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
    private const VERSION = '1.0.0';
    private const SERVICE_URL = 'https://trust.mapage-wp.online';
    private const OPTION_MODE = 'hcc_comment_shield_mode';
    private const OPTION_MODERATE = 'hcc_comment_shield_moderate_medium_risk';
    private const OPTION_SPAM = 'hcc_comment_shield_mark_high_risk_spam';
    private const OPTION_TIMEOUT = 'hcc_comment_shield_timeout';
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
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);

        add_filter('preprocess_comment', [$this, 'analyze_comment'], 20);
        add_filter('pre_comment_approved', [$this, 'filter_comment_approval'], 20, 2);
        add_action('comment_post', [$this, 'persist_comment_meta'], 20, 3);
        add_action('transition_comment_status', [$this, 'handle_comment_status_transition'], 20, 3);
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

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $site_url = home_url('/');
        $mode = $this->get_mode();

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
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) $comment->comment_date); ?></td>
                                <td><?php echo esc_html((string) $comment->comment_author); ?></td>
                                <td><?php echo esc_html((string) $comment->comment_author_email); ?></td>
                                <td><?php echo esc_html(get_the_title((int) $comment->comment_post_ID)); ?></td>
                                <td><?php echo esc_html((string) $score); ?></td>
                                <td><?php echo esc_html((string) $action); ?></td>
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
