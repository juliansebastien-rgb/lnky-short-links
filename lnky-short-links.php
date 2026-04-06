<?php
/**
 * Plugin Name: Lnky Short Links
 * Plugin URI: https://github.com/juliansebastien-rgb/lnky-short-links
 * Description: Cree des liens courts avec slugs personnalises, destinations externes ou contenus WordPress, et redirections trackees.
 * Version: 0.1.9
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lnky-short-links
 * Update URI: https://github.com/juliansebastien-rgb/lnky-short-links
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lnky_Short_Links {
    private const VERSION = '0.1.9';
    private const OPTION_KEY = 'lnky_short_links_settings';
    private const TRANSIENT_PREFIX = 'lnky_short_links_';
    private const GITHUB_REPOSITORY = 'juliansebastien-rgb/lnky-short-links';
    private const GITHUB_API_BASE = 'https://api.github.com/repos/juliansebastien-rgb/lnky-short-links';
    private const GITHUB_REPOSITORY_URL = 'https://github.com/juliansebastien-rgb/lnky-short-links';
    private const UPDATE_CACHE_TTL = HOUR_IN_SECONDS;
    private const QUERY_VAR = 'lnky_slug';
    private const MENU_SLUG = 'lnky-short-links';
    private const ADD_MENU_SLUG = 'lnky-short-links-add';
    private const STATS_MENU_SLUG = 'lnky-short-links-stats';
    private const SETTINGS_MENU_SLUG = 'lnky-short-links-settings';

    public function boot(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_handle_redirect'], 0);

        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_init', [$this, 'maybe_upgrade_schema']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_lnky_search_content', [$this, 'ajax_search_content']);

        add_action('admin_post_lnky_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_lnky_save_link', [$this, 'handle_save_link']);
        add_action('admin_post_lnky_delete_link', [$this, 'handle_delete_link']);

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_github_update']);
        add_filter('plugins_api', [$this, 'filter_plugin_information'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_github_update_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);
    }

    public function activate(): void {
        $this->create_links_table();

        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, $this->get_default_settings(), '', false);
        }

        $this->register_rewrite_rules();
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }

    public function maybe_upgrade_schema(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->create_links_table();
    }

    public function register_rewrite_rules(): void {
        $settings = $this->get_settings();
        $base_path = trim((string) ($settings['local_base_path'] ?? 'lnky'), '/');

        if ($base_path !== '') {
            add_rewrite_rule(
                '^' . preg_quote($base_path, '/') . '/([^/]+)/?$',
                'index.php?' . self::QUERY_VAR . '=$matches[1]',
                'top'
            );
        }
    }

    public function register_query_vars(array $vars): array {
        $vars[] = self::QUERY_VAR;

        return $vars;
    }

    public function register_admin_pages(): void {
        add_menu_page(
            __('Lnky Links', 'lnky-short-links'),
            __('Lnky Links', 'lnky-short-links'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_links_page'],
            'dashicons-admin-links',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Tous les liens', 'lnky-short-links'),
            __('Tous les liens', 'lnky-short-links'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_links_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Statistiques Lnky', 'lnky-short-links'),
            __('Statistiques', 'lnky-short-links'),
            'manage_options',
            self::STATS_MENU_SLUG,
            [$this, 'render_stats_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Ajouter un lien', 'lnky-short-links'),
            __('Ajouter', 'lnky-short-links'),
            'manage_options',
            self::ADD_MENU_SLUG,
            [$this, 'render_add_link_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Reglages Lnky', 'lnky-short-links'),
            __('Reglages', 'lnky-short-links'),
            'manage_options',
            self::SETTINGS_MENU_SLUG,
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_admin_assets(string $hook_suffix): void {
        $allowed_hooks = [
            'toplevel_page_' . self::MENU_SLUG,
            'lnky-links_page_' . self::ADD_MENU_SLUG,
            'lnky-links_page_' . self::STATS_MENU_SLUG,
            'lnky-links_page_' . self::SETTINGS_MENU_SLUG,
        ];

        if (!in_array($hook_suffix, $allowed_hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'lnky-short-links-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'lnky-short-links-qrcode',
            plugin_dir_url(__FILE__) . 'assets/js/qrcode-lib.js',
            [],
            self::VERSION,
            true
        );

        wp_enqueue_script(
            'lnky-short-links-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['lnky-short-links-qrcode'],
            self::VERSION,
            true
        );

        wp_localize_script(
            'lnky-short-links-admin',
            'LnkyAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('lnky_search_content'),
                'searchMinChars' => 2,
                'messages' => [
                    'searching' => __('Recherche en cours...', 'lnky-short-links'),
                    'empty' => __('Aucun resultat.', 'lnky-short-links'),
                    'pick' => __('Choisir', 'lnky-short-links'),
                    'needsMore' => __('Tape au moins 2 caracteres.', 'lnky-short-links'),
                ],
                'qr' => [
                    'logoUrl' => $this->get_qr_logo_url(),
                ],
            ]
        );
    }

    public function plugin_action_links(array $links): array {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=' . self::SETTINGS_MENU_SLUG)) . '">' . esc_html__('Settings', 'lnky-short-links') . '</a>';
        $add_link = '<a href="' . esc_url(admin_url('admin.php?page=' . self::ADD_MENU_SLUG)) . '">' . esc_html__('Add Link', 'lnky-short-links') . '</a>';

        array_unshift($links, $settings_link, $add_link);

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
            'slug' => 'lnky-short-links',
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
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== 'lnky-short-links') {
            return $result;
        }

        $release = $this->get_github_release_data();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'Lnky Short Links',
            'slug' => 'lnky-short-links',
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
                'description' => 'Plugin WordPress SaaS pour creer des liens courts de marque connectes a l API Lnky.',
                'installation' => 'Installe le zip de release, active le plugin, puis configure le domaine, le sous-domaine et la connexion API dans Lnky Links > Reglages.',
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

        $normalized = trailingslashit($remote_source) . 'lnky-short-links';

        if ($source === $normalized || !is_dir($source)) {
            return $source;
        }

        if (@rename($source, $normalized)) {
            return $normalized;
        }

        return $source;
    }

    public function render_admin_brand(string $title, string $description = ''): void {
        $logo_url = plugin_dir_url(__FILE__) . 'assets/images/logo-lnky-short-links.png';
        ?>
        <div class="lnky-admin__brand">
            <img class="lnky-admin__brand-logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr__('Logo Lnky Short Links', 'lnky-short-links'); ?>">
            <div class="lnky-admin__brand-copy">
                <h1><?php echo esc_html($title); ?></h1>
                <?php if ($description !== '') : ?>
                    <p><?php echo esc_html($description); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_links_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $links = $this->get_links();
        $stats = $this->get_link_stats();
        ?>
        <div class="wrap lnky-admin">
            <?php $this->render_admin_brand(__('Lnky Links', 'lnky-short-links'), __('Gere tes liens courts, tes destinations et tes clics depuis WordPress.', 'lnky-short-links')); ?>
            <?php $this->render_admin_notices(); ?>

            <div class="lnky-admin__hero">
                <div>
                    <h2><?php echo esc_html__('Liens courts', 'lnky-short-links'); ?></h2>
                    <p><?php echo esc_html__('Gere tes slugs, tes destinations et les clics depuis WordPress.', 'lnky-short-links'); ?></p>
                </div>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::ADD_MENU_SLUG)); ?>">
                    <?php echo esc_html__('Ajouter un lien', 'lnky-short-links'); ?>
                </a>
            </div>

            <div class="lnky-admin__stats-grid">
                <div class="lnky-admin__stat-card">
                    <span class="lnky-admin__stat-label"><?php echo esc_html__('Liens', 'lnky-short-links'); ?></span>
                    <strong class="lnky-admin__stat-value"><?php echo esc_html(number_format_i18n($stats['total_links'])); ?></strong>
                </div>
                <div class="lnky-admin__stat-card">
                    <span class="lnky-admin__stat-label"><?php echo esc_html__('Liens actifs', 'lnky-short-links'); ?></span>
                    <strong class="lnky-admin__stat-value"><?php echo esc_html(number_format_i18n($stats['active_links'])); ?></strong>
                </div>
                <div class="lnky-admin__stat-card">
                    <span class="lnky-admin__stat-label"><?php echo esc_html__('Clics totaux', 'lnky-short-links'); ?></span>
                    <strong class="lnky-admin__stat-value"><?php echo esc_html(number_format_i18n($stats['total_clicks'])); ?></strong>
                </div>
                <div class="lnky-admin__stat-card">
                    <span class="lnky-admin__stat-label"><?php echo esc_html__('Moyenne par lien', 'lnky-short-links'); ?></span>
                    <strong class="lnky-admin__stat-value"><?php echo esc_html(number_format_i18n($stats['average_clicks'], 1)); ?></strong>
                </div>
            </div>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Lien court', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Destination', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Type', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Redirection', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Statut', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Clics', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Dernier clic', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('QR Code', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Actions', 'lnky-short-links'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($links)) : ?>
                        <tr>
                            <td colspan="9"><?php echo esc_html__('Aucun lien pour le moment.', 'lnky-short-links'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($links as $link) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($this->build_public_link($link['slug'])); ?></strong><br>
                                    <code><?php echo esc_html__('URL geree par l API Lnky', 'lnky-short-links'); ?></code>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($link['destination_url']); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html($link['destination_label'] ?: $link['destination_url']); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($this->format_target_type($link['target_type'])); ?></td>
                                <td><?php echo esc_html((string) $link['redirect_type']); ?></td>
                                <td><?php echo $link['is_active'] ? esc_html__('Actif', 'lnky-short-links') : esc_html__('Inactif', 'lnky-short-links'); ?></td>
                                <td><?php echo esc_html((string) $link['click_count']); ?></td>
                                <td><?php echo esc_html($this->format_datetime_or_placeholder($link['last_clicked_at'] ?? '')); ?></td>
                                <td>
                                    <?php $public_link_url = $this->build_public_link_url($link['slug']); ?>
                                    <div class="lnky-qr-cell">
                                        <div
                                            class="lnky-qr-cell__image"
                                            data-lnky-qr-canvas
                                            data-lnky-qr-url="<?php echo esc_attr($public_link_url); ?>"
                                            data-lnky-qr-size="72"
                                            aria-label="<?php echo esc_attr(sprintf(__('QR code pour %s', 'lnky-short-links'), $public_link_url)); ?>"
                                        ></div>
                                        <button
                                            type="button"
                                            class="button button-small"
                                            data-lnky-qr-open
                                            data-lnky-qr-url="<?php echo esc_attr($public_link_url); ?>"
                                        >
                                            <?php echo esc_html__('Ouvrir', 'lnky-short-links'); ?>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=' . self::ADD_MENU_SLUG . '&link_id=' . (int) $link['id'])); ?>">
                                        <?php echo esc_html__('Modifier', 'lnky-short-links'); ?>
                                    </a>
                                    <a
                                        class="button button-small"
                                        href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lnky_delete_link&link_id=' . (int) $link['id']), 'lnky_delete_link_' . (int) $link['id'])); ?>"
                                        onclick="return confirm('<?php echo esc_js(__('Supprimer ce lien ?', 'lnky-short-links')); ?>');"
                                    >
                                        <?php echo esc_html__('Supprimer', 'lnky-short-links'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_stats_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stats = $this->get_link_stats();
        $top_links = $this->get_top_links(8);
        $recent_links = $this->get_recent_links(8);
        ?>
        <div class="wrap lnky-admin">
            <?php $this->render_admin_brand(__('Statistiques Lnky', 'lnky-short-links'), __('Suis les performances de base de tes liens courts depuis WordPress.', 'lnky-short-links')); ?>
            <?php $this->render_admin_notices(); ?>

            <div class="lnky-admin__stats-grid">
                <div class="lnky-admin__stat-card">
                    <span class="lnky-admin__stat-label"><?php echo esc_html__('Liens crees', 'lnky-short-links'); ?></span>
                    <strong class="lnky-admin__stat-value"><?php echo esc_html(number_format_i18n($stats['total_links'])); ?></strong>
                </div>
                <div class="lnky-admin__stat-card">
                    <span class="lnky-admin__stat-label"><?php echo esc_html__('Liens actifs', 'lnky-short-links'); ?></span>
                    <strong class="lnky-admin__stat-value"><?php echo esc_html(number_format_i18n($stats['active_links'])); ?></strong>
                </div>
                <div class="lnky-admin__stat-card">
                    <span class="lnky-admin__stat-label"><?php echo esc_html__('Clics totaux', 'lnky-short-links'); ?></span>
                    <strong class="lnky-admin__stat-value"><?php echo esc_html(number_format_i18n($stats['total_clicks'])); ?></strong>
                </div>
                <div class="lnky-admin__stat-card">
                    <span class="lnky-admin__stat-label"><?php echo esc_html__('Meilleur lien', 'lnky-short-links'); ?></span>
                    <strong class="lnky-admin__stat-value lnky-admin__stat-value--small"><?php echo esc_html($stats['best_slug'] ?: __('Aucun', 'lnky-short-links')); ?></strong>
                </div>
            </div>

            <div class="lnky-admin__stats-layout">
                <div class="lnky-admin__card">
                    <h2><?php echo esc_html__('Top liens', 'lnky-short-links'); ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Lien court', 'lnky-short-links'); ?></th>
                                <th><?php echo esc_html__('Clics', 'lnky-short-links'); ?></th>
                                <th><?php echo esc_html__('Dernier clic', 'lnky-short-links'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_links)) : ?>
                                <tr>
                                    <td colspan="3"><?php echo esc_html__('Pas encore de donnees de clics.', 'lnky-short-links'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($top_links as $link) : ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($this->build_public_link($link['slug'])); ?></strong>
                                        </td>
                                        <td><?php echo esc_html(number_format_i18n((int) $link['click_count'])); ?></td>
                                        <td><?php echo esc_html($this->format_datetime_or_placeholder($link['last_clicked_at'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="lnky-admin__card">
                    <h2><?php echo esc_html__('Liens recents', 'lnky-short-links'); ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Lien court', 'lnky-short-links'); ?></th>
                                <th><?php echo esc_html__('Cree le', 'lnky-short-links'); ?></th>
                                <th><?php echo esc_html__('Statut', 'lnky-short-links'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_links)) : ?>
                                <tr>
                                    <td colspan="3"><?php echo esc_html__('Aucun lien pour le moment.', 'lnky-short-links'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($recent_links as $link) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($this->build_public_link($link['slug'])); ?></strong></td>
                                        <td><?php echo esc_html($this->format_datetime_or_placeholder($link['created_at'] ?? '')); ?></td>
                                        <td><?php echo $link['is_active'] ? esc_html__('Actif', 'lnky-short-links') : esc_html__('Inactif', 'lnky-short-links'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_add_link_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $link_id = isset($_GET['link_id']) ? absint($_GET['link_id']) : 0;
        $link = $link_id ? $this->get_link($link_id) : null;
        $defaults = [
            'id' => 0,
            'slug' => '',
            'target_mode' => 'external',
            'target_type' => 'url',
            'destination_url' => '',
            'destination_post_id' => 0,
            'destination_label' => '',
            'redirect_type' => 302,
            'is_active' => 1,
        ];
        $link = wp_parse_args(is_array($link) ? $link : [], $defaults);
        $settings = $this->get_settings();
        $can_use_products = $this->is_woocommerce_active();
        ?>
        <div class="wrap lnky-admin">
            <?php $this->render_admin_brand($link['id'] ? __('Modifier le lien', 'lnky-short-links') : __('Ajouter un lien', 'lnky-short-links'), __('Configure un slug, choisis la destination et regle la redirection.', 'lnky-short-links')); ?>
            <?php $this->render_admin_notices(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lnky-admin__form">
                <input type="hidden" name="action" value="lnky_save_link">
                <input type="hidden" name="link_id" value="<?php echo esc_attr((string) $link['id']); ?>">
                <?php wp_nonce_field('lnky_save_link'); ?>

                <div class="lnky-admin__card">
                    <h2><?php echo esc_html__('Lien court', 'lnky-short-links'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="lnky_slug"><?php echo esc_html__('Slug', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <input type="text" id="lnky_slug" name="slug" class="regular-text" value="<?php echo esc_attr($link['slug']); ?>" placeholder="promo">
                                    <p class="description"><?php echo esc_html__('Laisse vide pour generer automatiquement un slug unique.', 'lnky-short-links'); ?></p>
                                    <p class="description">
                                        <?php echo esc_html__('Apercu public :', 'lnky-short-links'); ?>
                                        <strong
                                            id="lnky_live_public_preview"
                                            data-lnky-preview-domain="<?php echo esc_attr($settings['selected_domain']); ?>"
                                            data-lnky-preview-subdomain="<?php echo esc_attr($settings['workspace_subdomain']); ?>"
                                        ><?php echo esc_html($this->build_public_link($link['slug'] ?: 'slug-auto')); ?></strong>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="lnky_redirect_type"><?php echo esc_html__('Type de redirection', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <select id="lnky_redirect_type" name="redirect_type">
                                        <option value="301" <?php selected((int) $link['redirect_type'], 301); ?>>301</option>
                                        <option value="302" <?php selected((int) $link['redirect_type'], 302); ?>>302</option>
                                        <option value="307" <?php selected((int) $link['redirect_type'], 307); ?>>307</option>
                                    </select>
                                    <div class="lnky-admin__hint-list">
                                        <p><strong><?php echo esc_html__('301', 'lnky-short-links'); ?></strong> <?php echo esc_html__('redirection permanente. A utiliser si le lien court doit toujours pointer vers la meme destination. C est le meilleur choix pour une URL stable.', 'lnky-short-links'); ?></p>
                                        <p><strong><?php echo esc_html__('302', 'lnky-short-links'); ?></strong> <?php echo esc_html__('redirection temporaire. A utiliser si la destination peut changer plus tard. C est le choix le plus souple pour le marketing et les tests.', 'lnky-short-links'); ?></p>
                                        <p><strong><?php echo esc_html__('307', 'lnky-short-links'); ?></strong> <?php echo esc_html__('redirection temporaire stricte. Plus technique, utile si tu veux conserver exactement la methode de requete. En pratique, 302 suffit dans la plupart des cas.', 'lnky-short-links'); ?></p>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Statut', 'lnky-short-links'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="is_active" value="1" <?php checked((int) $link['is_active'], 1); ?>>
                                        <?php echo esc_html__('Lien actif', 'lnky-short-links'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="lnky-admin__card">
                    <h2><?php echo esc_html__('Destination', 'lnky-short-links'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Mode', 'lnky-short-links'); ?></th>
                                <td>
                                    <label class="lnky-admin__radio">
                                        <input type="radio" name="target_mode" value="external" <?php checked($link['target_mode'], 'external'); ?>>
                                        <?php echo esc_html__('URL externe', 'lnky-short-links'); ?>
                                    </label>
                                    <label class="lnky-admin__radio">
                                        <input type="radio" name="target_mode" value="internal" <?php checked($link['target_mode'], 'internal'); ?>>
                                        <?php echo esc_html__('Contenu WordPress', 'lnky-short-links'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="lnky-admin__target-group" data-target-mode="external" <?php if ($link['target_mode'] !== 'external') : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="lnky_destination_url"><?php echo esc_html__('URL cible', 'lnky-short-links'); ?></label></th>
                                    <td>
                                        <input type="url" id="lnky_destination_url" name="destination_url" class="regular-text code" value="<?php echo esc_attr($link['target_mode'] === 'external' ? $link['destination_url'] : ''); ?>" placeholder="https://example.com/offre">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="lnky-admin__target-group" data-target-mode="internal" <?php if ($link['target_mode'] !== 'internal') : ?>hidden<?php endif; ?>>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="lnky_internal_type"><?php echo esc_html__('Type de contenu', 'lnky-short-links'); ?></label></th>
                                    <td>
                                        <select id="lnky_internal_type" name="internal_type">
                                            <option value="page" <?php selected($link['target_type'], 'page'); ?>><?php echo esc_html__('Page', 'lnky-short-links'); ?></option>
                                            <option value="post" <?php selected($link['target_type'], 'post'); ?>><?php echo esc_html__('Article', 'lnky-short-links'); ?></option>
                                            <?php if ($can_use_products) : ?>
                                                <option value="product" <?php selected($link['target_type'], 'product'); ?>><?php echo esc_html__('Produit WooCommerce', 'lnky-short-links'); ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <?php if (!$can_use_products) : ?>
                                            <p class="description"><?php echo esc_html__('WooCommerce n est pas actif, le type Produit est masque.', 'lnky-short-links'); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="lnky_internal_search"><?php echo esc_html__('Recherche', 'lnky-short-links'); ?></label></th>
                                    <td>
                                        <input type="hidden" name="destination_post_id" id="lnky_destination_post_id" value="<?php echo esc_attr((string) (int) $link['destination_post_id']); ?>">
                                        <input type="hidden" name="destination_label" id="lnky_destination_label" value="<?php echo esc_attr($link['destination_label']); ?>">
                                        <input type="hidden" name="destination_url_snapshot" id="lnky_destination_url_snapshot" value="<?php echo esc_attr($link['target_mode'] === 'internal' ? $link['destination_url'] : ''); ?>">
                                        <input type="text" id="lnky_internal_search" class="regular-text" value="<?php echo esc_attr($link['target_mode'] === 'internal' ? $link['destination_label'] : ''); ?>" placeholder="<?php echo esc_attr__('Tape pour rechercher...', 'lnky-short-links'); ?>" autocomplete="off">
                                        <div class="lnky-search-results" id="lnky_search_results"></div>
                                        <p class="description"><?php echo esc_html__('Recherche AJAX dans les pages, articles ou produits selon le type choisi.', 'lnky-short-links'); ?></p>
                                        <div class="lnky-selected-item" id="lnky_selected_item" <?php if ($link['target_mode'] !== 'internal' || empty($link['destination_post_id'])) : ?>hidden<?php endif; ?>>
                                            <strong><?php echo esc_html__('Selection actuelle :', 'lnky-short-links'); ?></strong>
                                            <span data-lnky-selected-label><?php echo esc_html($link['destination_label']); ?></span>
                                            <code data-lnky-selected-url><?php echo esc_html($link['target_mode'] === 'internal' ? $link['destination_url'] : ''); ?></code>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Enregistrer le lien', 'lnky-short-links'); ?></button>
                </p>
            </form>

            <div class="lnky-admin__card">
                <h2><?php echo esc_html__('Configuration actuelle', 'lnky-short-links'); ?></h2>
                <p>
                    <?php echo esc_html__('Domaine choisi :', 'lnky-short-links'); ?>
                    <strong><?php echo esc_html($settings['selected_domain']); ?></strong>
                </p>
                <p>
                    <?php echo esc_html__('Sous-domaine :', 'lnky-short-links'); ?>
                    <strong><?php echo esc_html($settings['workspace_subdomain'] ?: __('aucun', 'lnky-short-links')); ?></strong>
                </p>
                <p>
                    <?php echo esc_html__('Host complet :', 'lnky-short-links'); ?>
                    <code><?php echo esc_html($this->get_public_host()); ?></code>
                </p>
            </div>

            <div class="lnky-admin__card">
                <h2><?php echo esc_html__('QR code du lien', 'lnky-short-links'); ?></h2>
                <p><?php echo esc_html__('Un QR code est genere automatiquement a partir de l URL courte publique du lien.', 'lnky-short-links'); ?></p>
                <?php $preview_public_link_url = $this->build_public_link_url($link['slug'] ?: 'slug-auto'); ?>
                <div class="lnky-qr-preview">
                    <div
                        id="lnky_qr_preview_image"
                        class="lnky-qr-preview__image"
                        data-lnky-qr-canvas
                        data-lnky-qr-url="<?php echo esc_attr($preview_public_link_url); ?>"
                        data-lnky-qr-size="280"
                        aria-label="<?php echo esc_attr__('Apercu du QR code du lien court', 'lnky-short-links'); ?>"
                    ></div>
                    <div class="lnky-qr-preview__meta">
                        <p>
                            <?php echo esc_html__('URL du lien court :', 'lnky-short-links'); ?><br>
                            <code id="lnky_qr_preview_link"><?php echo esc_html($preview_public_link_url); ?></code>
                        </p>
                        <p>
                            <a
                                id="lnky_qr_preview_download"
                                class="button button-secondary"
                                href="#"
                                download="lnky-qrcode.png"
                            >
                                <?php echo esc_html__('Telecharger le QR code', 'lnky-short-links'); ?>
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $suggestion = $this->generate_subdomain_suggestion();
        ?>
        <div class="wrap lnky-admin">
            <?php $this->render_admin_brand(__('Reglages Lnky', 'lnky-short-links'), __('Relie ton site a Lnky et configure ton espace de liens de marque.', 'lnky-short-links')); ?>
            <?php $this->render_admin_notices(); ?>

            <div class="lnky-admin__card">
                <h2><?php echo esc_html__('Identite de l espace de liens', 'lnky-short-links'); ?></h2>
                <p><?php echo esc_html__('Choisis le domaine disponible, puis reserve un sous-domaine court et memorisable.', 'lnky-short-links'); ?></p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lnky-admin__form">
                <input type="hidden" name="action" value="lnky_save_settings">
                <?php wp_nonce_field('lnky_save_settings'); ?>

                <div class="lnky-admin__card">
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="lnky_available_domains"><?php echo esc_html__('Domaines disponibles', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <textarea id="lnky_available_domains" name="available_domains" class="large-text code" rows="5"><?php echo esc_textarea(implode("\n", $settings['available_domains'])); ?></textarea>
                                    <p class="description"><?php echo esc_html__('Un domaine par ligne. Pour le MVP, tu peux n en mettre qu un seul.', 'lnky-short-links'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="lnky_selected_domain"><?php echo esc_html__('Domaine actif', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <select id="lnky_selected_domain" name="selected_domain">
                                        <?php foreach ($settings['available_domains'] as $domain) : ?>
                                            <option value="<?php echo esc_attr($domain); ?>" <?php selected($settings['selected_domain'], $domain); ?>><?php echo esc_html($domain); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="lnky_workspace_subdomain"><?php echo esc_html__('Sous-domaine', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <input type="text" id="lnky_workspace_subdomain" name="workspace_subdomain" class="regular-text" value="<?php echo esc_attr($settings['workspace_subdomain']); ?>" placeholder="<?php echo esc_attr($suggestion); ?>">
                                    <p class="description"><?php echo esc_html__('Recommande : 3 a 5 caracteres, simples a retenir. Autorise lettres, chiffres et tirets.', 'lnky-short-links'); ?></p>
                                    <p class="description">
                                        <?php echo esc_html__('Suggestion :', 'lnky-short-links'); ?>
                                        <strong><?php echo esc_html($suggestion); ?></strong>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="lnky_api_base_url"><?php echo esc_html__('Base URL API', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <input type="url" id="lnky_api_base_url" name="api_base_url" class="regular-text code" value="<?php echo esc_attr($settings['api_base_url']); ?>" placeholder="https://api.lnky.fr">
                                    <p class="description"><?php echo esc_html__('Le plugin se connecte directement a l API Lnky. Aucun token n est demande a l utilisateur.', 'lnky-short-links'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Enregistrer les reglages', 'lnky-short-links'); ?></button>
                </p>
            </form>

            <div class="lnky-admin__card">
                <h2><?php echo esc_html__('Apercu', 'lnky-short-links'); ?></h2>
                <p>
                    <code
                        id="lnky_settings_public_preview"
                        data-lnky-settings-domain="<?php echo esc_attr($settings['selected_domain']); ?>"
                        data-lnky-settings-subdomain="<?php echo esc_attr($settings['workspace_subdomain']); ?>"
                    ><?php echo esc_html($this->get_public_host()); ?>/offre</code>
                </p>
            </div>

            <div class="lnky-admin__card">
                <h2><?php echo esc_html__('Etat SaaS / API', 'lnky-short-links'); ?></h2>
                <p><?php echo esc_html($this->get_api_readiness_message($settings)); ?></p>
                <?php $api_status = $this->fetch_api_status($settings); ?>
                <p>
                    <?php echo esc_html__('Connectivite API :', 'lnky-short-links'); ?>
                    <strong><?php echo esc_html($api_status['health']); ?></strong>
                </p>
                <p>
                    <?php echo esc_html__('Disponibilite du sous-domaine :', 'lnky-short-links'); ?>
                    <strong><?php echo esc_html($api_status['availability']); ?></strong>
                </p>
                <p>
                    <?php echo esc_html__('Workspace distant :', 'lnky-short-links'); ?>
                    <code><?php echo esc_html($settings['remote_workspace_id'] ?: __('non synchronise', 'lnky-short-links')); ?></code>
                </p>
            </div>
        </div>
        <?php
    }

    public function handle_save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Acces refuse.', 'lnky-short-links'));
        }

        check_admin_referer('lnky_save_settings');

        $available_domains_raw = isset($_POST['available_domains']) ? (string) wp_unslash($_POST['available_domains']) : '';
        $available_domains = $this->sanitize_domains_list($available_domains_raw);
        $selected_domain = isset($_POST['selected_domain']) ? strtolower((string) wp_unslash($_POST['selected_domain'])) : '';
        $workspace_subdomain = isset($_POST['workspace_subdomain']) ? $this->sanitize_subdomain((string) wp_unslash($_POST['workspace_subdomain'])) : '';
        $api_base_url = isset($_POST['api_base_url']) ? esc_url_raw((string) wp_unslash($_POST['api_base_url'])) : '';

        if (empty($available_domains)) {
            $available_domains = $this->get_default_settings()['available_domains'];
        }

        if (!in_array($selected_domain, $available_domains, true)) {
            $selected_domain = $available_domains[0];
        }

        update_option(
            self::OPTION_KEY,
            [
                'connection_mode' => 'api',
                'available_domains' => $available_domains,
                'selected_domain' => $selected_domain,
                'workspace_subdomain' => $workspace_subdomain,
                'local_base_path' => $this->get_settings()['local_base_path'] ?? 'lnky',
                'api_base_url' => $api_base_url,
                'api_key' => '',
                'remote_workspace_id' => $this->get_settings()['remote_workspace_id'] ?? '',
            ],
            false
        );

        $notice = 'settings_saved';

        if ($workspace_subdomain !== '' && $api_base_url !== '') {
            $sync_result = $this->sync_workspace_to_api();

            if ($sync_result['ok']) {
                $notice = 'settings_synced';
            } else {
                $notice = 'settings_sync_failed';
            }
        }

        $this->register_rewrite_rules();
        flush_rewrite_rules();

        wp_safe_redirect(admin_url('admin.php?page=' . self::SETTINGS_MENU_SLUG . '&lnky_notice=' . $notice));
        exit;
    }

    public function handle_save_link(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Acces refuse.', 'lnky-short-links'));
        }

        check_admin_referer('lnky_save_link');

        global $wpdb;

        $table = $this->get_links_table_name();
        $link_id = isset($_POST['link_id']) ? absint($_POST['link_id']) : 0;
        $target_mode = isset($_POST['target_mode']) ? sanitize_key((string) wp_unslash($_POST['target_mode'])) : 'external';
        $redirect_type = isset($_POST['redirect_type']) ? (int) $_POST['redirect_type'] : 302;
        $is_active = !empty($_POST['is_active']) ? 1 : 0;
        $slug_input = isset($_POST['slug']) ? (string) wp_unslash($_POST['slug']) : '';
        $slug = $this->sanitize_slug($slug_input);

        if ($slug === '') {
            $slug = $this->generate_unique_slug();
        }

        if (!$this->is_slug_available($slug, $link_id)) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::ADD_MENU_SLUG . '&link_id=' . $link_id . '&lnky_notice=slug_taken'));
            exit;
        }

        $allowed_redirects = [301, 302, 307];
        if (!in_array($redirect_type, $allowed_redirects, true)) {
            $redirect_type = 302;
        }

        $target_type = 'url';
        $destination_url = '';
        $destination_post_id = 0;
        $destination_label = '';

        if ($target_mode === 'internal') {
            $target_type = isset($_POST['internal_type']) ? sanitize_key((string) wp_unslash($_POST['internal_type'])) : 'page';
            $destination_post_id = isset($_POST['destination_post_id']) ? absint($_POST['destination_post_id']) : 0;

            if (!$this->is_allowed_internal_type($target_type) || !$destination_post_id) {
                wp_safe_redirect(admin_url('admin.php?page=' . self::ADD_MENU_SLUG . '&link_id=' . $link_id . '&lnky_notice=missing_target'));
                exit;
            }

            $post = get_post($destination_post_id);

            if (!$post || $post->post_type !== $target_type) {
                wp_safe_redirect(admin_url('admin.php?page=' . self::ADD_MENU_SLUG . '&link_id=' . $link_id . '&lnky_notice=invalid_target'));
                exit;
            }

            $destination_url = (string) get_permalink($destination_post_id);
            $destination_label = get_the_title($destination_post_id) ?: $destination_url;
        } else {
            $target_mode = 'external';
            $destination_url = isset($_POST['destination_url']) ? esc_url_raw((string) wp_unslash($_POST['destination_url'])) : '';

            if ($destination_url === '') {
                wp_safe_redirect(admin_url('admin.php?page=' . self::ADD_MENU_SLUG . '&link_id=' . $link_id . '&lnky_notice=missing_target'));
                exit;
            }

            $destination_label = $destination_url;
        }

        $data = [
            'slug' => $slug,
            'target_mode' => $target_mode,
            'target_type' => $target_type,
            'destination_url' => $destination_url,
            'destination_post_id' => $destination_post_id,
            'destination_label' => $destination_label,
            'redirect_type' => $redirect_type,
            'is_active' => $is_active,
            'updated_at' => current_time('mysql', true),
        ];

        if ($link_id > 0) {
            $wpdb->update($table, $data, ['id' => $link_id]);
            $notice = 'link_updated';
        } else {
            $data['click_count'] = 0;
            $data['created_at'] = current_time('mysql', true);
            $wpdb->insert($table, $data);
            $link_id = (int) $wpdb->insert_id;
            $notice = 'link_created';
        }

        if ($this->is_api_mode_enabled()) {
            $saved_link = $this->get_link($link_id);
            $sync_result = is_array($saved_link) ? $this->sync_link_to_api($saved_link) : ['ok' => false];

            if (!empty($sync_result['ok'])) {
                $notice .= '_synced';
            } else {
                $notice .= '_sync_failed';
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::ADD_MENU_SLUG . '&link_id=' . $link_id . '&lnky_notice=' . $notice));
        exit;
    }

    public function handle_delete_link(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Acces refuse.', 'lnky-short-links'));
        }

        $link_id = isset($_GET['link_id']) ? absint($_GET['link_id']) : 0;
        check_admin_referer('lnky_delete_link_' . $link_id);

        if ($link_id > 0) {
            global $wpdb;
            $wpdb->delete($this->get_links_table_name(), ['id' => $link_id]);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&lnky_notice=link_deleted'));
        exit;
    }

    public function ajax_search_content(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Acces refuse.', 'lnky-short-links')], 403);
        }

        check_ajax_referer('lnky_search_content', 'nonce');

        $search = isset($_GET['search']) ? sanitize_text_field((string) wp_unslash($_GET['search'])) : '';
        $post_type = isset($_GET['post_type']) ? sanitize_key((string) wp_unslash($_GET['post_type'])) : 'page';

        if (!$this->is_allowed_internal_type($post_type)) {
            wp_send_json_success(['items' => []]);
        }

        $query = new WP_Query(
            [
                'post_type' => $post_type,
                'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
                'posts_per_page' => 10,
                's' => $search,
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true,
            ]
        );

        $items = [];

        foreach ($query->posts as $post) {
            $items[] = [
                'id' => (int) $post->ID,
                'title' => get_the_title($post->ID) ?: __('Sans titre', 'lnky-short-links'),
                'url' => (string) get_permalink($post->ID),
                'type' => $post->post_type,
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    public function maybe_handle_redirect(): void {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        $slug = get_query_var(self::QUERY_VAR);

        if (!is_string($slug) || $slug === '') {
            $slug = $this->detect_branded_host_slug();
        }

        if (!is_string($slug) || $slug === '') {
            return;
        }

        $link = $this->get_link_by_slug($slug);

        if (!$link || empty($link['is_active'])) {
            return;
        }

        $destination = $this->resolve_destination_url($link);

        if ($destination === '') {
            return;
        }

        $this->increment_click_count((int) $link['id']);
        wp_redirect($destination, (int) $link['redirect_type']);
        exit;
    }

    private function detect_branded_host_slug(): string {
        $host = $this->current_request_host();
        $public_host = $this->get_public_host();

        if ($host === '' || $public_host === '' || $host !== $public_host) {
            return '';
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');

        if ($path === '' || str_contains($path, '/')) {
            return '';
        }

        return $this->sanitize_slug($path);
    }

    private function current_request_host(): string {
        return isset($_SERVER['HTTP_HOST']) ? strtolower(sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST']))) : '';
    }

    private function resolve_destination_url(array $link): string {
        if (($link['target_mode'] ?? '') === 'internal' && !empty($link['destination_post_id'])) {
            $permalink = get_permalink((int) $link['destination_post_id']);

            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        return is_string($link['destination_url'] ?? '') ? $link['destination_url'] : '';
    }

    private function increment_click_count(int $link_id): void {
        global $wpdb;

        $table = $this->get_links_table_name();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET click_count = click_count + 1, last_clicked_at = %s, updated_at = %s WHERE id = %d",
                current_time('mysql', true),
                current_time('mysql', true),
                $link_id
            )
        );
    }

    private function get_links_table_name(): string {
        global $wpdb;

        return $wpdb->prefix . 'lnky_links';
    }

    private function create_links_table(): void {
        global $wpdb;

        $table = $this->get_links_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(190) NOT NULL,
            target_mode VARCHAR(20) NOT NULL DEFAULT 'external',
            target_type VARCHAR(20) NOT NULL DEFAULT 'url',
            destination_url TEXT NOT NULL,
            destination_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            destination_label TEXT NOT NULL,
            redirect_type SMALLINT UNSIGNED NOT NULL DEFAULT 302,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            click_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_clicked_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private function get_default_settings(): array {
        return [
            'connection_mode' => 'api',
            'available_domains' => ['lnky.fr'],
            'selected_domain' => 'lnky.fr',
            'workspace_subdomain' => '',
            'local_base_path' => 'lnky',
            'api_base_url' => 'https://api.lnky.fr',
            'api_key' => '',
            'remote_workspace_id' => '',
        ];
    }

    private function get_settings(): array {
        $settings = get_option(self::OPTION_KEY, []);

        return wp_parse_args(is_array($settings) ? $settings : [], $this->get_default_settings());
    }

    private function get_api_readiness_message(array $settings): string {
        $api_base_url = (string) ($settings['api_base_url'] ?? '');
        if ($api_base_url === '') {
            return __('La base URL API Lnky n est pas encore renseignee.', 'lnky-short-links');
        }

        return __('Le plugin fonctionne uniquement en mode SaaS et se connecte directement a l API Lnky.', 'lnky-short-links');
    }

    private function is_api_mode_enabled(): bool {
        $settings = $this->get_settings();

        return !empty($settings['api_base_url']);
    }

    private function build_api_url(string $path, array $query = []): string {
        $settings = $this->get_settings();
        $base = rtrim((string) ($settings['api_base_url'] ?? ''), '/');
        $url = $base . $path;

        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        return $url;
    }

    private function api_request(string $method, string $path, array $payload = [], array $query = []): array {
        $settings = $this->get_settings();
        $url = $this->build_api_url($path, $query);
        $args = [
            'method' => strtoupper($method),
            'timeout' => 8,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($settings['api_key'])) {
            $args['headers']['Authorization'] = 'Bearer ' . (string) $settings['api_key'];
        }

        if (!empty($payload)) {
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($code < 200 || $code >= 300) {
            return [
                'ok' => false,
                'message' => $data['detail'] ?? $body ?: __('Erreur API inconnue.', 'lnky-short-links'),
                'status' => $code,
                'data' => $data,
            ];
        }

        return [
            'ok' => true,
            'status' => $code,
            'data' => $data,
        ];
    }

    private function sync_workspace_to_api(): array {
        if (!$this->is_api_mode_enabled()) {
            return ['ok' => false, 'message' => __('Mode API non configure.', 'lnky-short-links')];
        }

        $settings = $this->get_settings();
        $result = $this->api_request(
            'POST',
            '/lnky/v1/workspaces',
            [
                'site_url' => home_url('/'),
                'site_name' => get_bloginfo('name'),
                'domain' => $settings['selected_domain'],
                'subdomain' => $settings['workspace_subdomain'],
            ]
        );

        if (!$result['ok']) {
            return $result;
        }

        $workspace_id = $result['data']['workspace']['id'] ?? '';

        if (is_string($workspace_id) && $workspace_id !== '') {
            $settings['remote_workspace_id'] = $workspace_id;
            update_option(self::OPTION_KEY, $settings, false);
        }

        return $result;
    }

    private function sync_link_to_api(array $link): array {
        if (!$this->is_api_mode_enabled()) {
            return ['ok' => false, 'message' => __('Mode API non configure.', 'lnky-short-links')];
        }

        $settings = $this->get_settings();
        $workspace_id = (string) ($settings['remote_workspace_id'] ?? '');

        if ($workspace_id === '') {
            $workspace_result = $this->sync_workspace_to_api();

            if (!$workspace_result['ok']) {
                return $workspace_result;
            }

            $settings = $this->get_settings();
            $workspace_id = (string) ($settings['remote_workspace_id'] ?? '');
        }

        if ($workspace_id === '') {
            return ['ok' => false, 'message' => __('Workspace distant introuvable.', 'lnky-short-links')];
        }

        return $this->api_request(
            'POST',
            '/lnky/v1/links',
            [
                'workspace_id' => $workspace_id,
                'source_link_id' => (int) $link['id'],
                'slug' => (string) $link['slug'],
                'destination_url' => (string) $this->resolve_destination_url($link),
                'destination_label' => (string) ($link['destination_label'] ?? ''),
                'target_mode' => (string) ($link['target_mode'] ?? 'external'),
                'target_type' => (string) ($link['target_type'] ?? 'url'),
                'redirect_type' => (int) ($link['redirect_type'] ?? 302),
                'is_active' => !empty($link['is_active']),
            ]
        );
    }

    private function fetch_api_status(array $settings): array {
        if (empty($settings['api_base_url'])) {
            return [
                'health' => __('non configuree', 'lnky-short-links'),
                'availability' => __('non verifiee', 'lnky-short-links'),
            ];
        }

        $health = $this->api_request('GET', '/lnky/v1/health');
        $availability = !empty($settings['workspace_subdomain'])
            ? $this->api_request(
                'GET',
                '/lnky/v1/workspaces/availability',
                [],
                [
                    'domain' => $settings['selected_domain'],
                    'subdomain' => $settings['workspace_subdomain'],
                ]
            )
            : ['ok' => false];

        return [
            'health' => !empty($health['ok']) ? __('connectee', 'lnky-short-links') : __('hors ligne', 'lnky-short-links'),
            'availability' => !empty($availability['ok'])
                ? (!empty($availability['data']['available']) ? __('disponible', 'lnky-short-links') : __('reserve', 'lnky-short-links'))
                : __('non verifiee', 'lnky-short-links'),
        ];
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
                    'User-Agent' => 'Lnky Short Links/' . self::VERSION . '; ' . home_url('/'),
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

    private function sanitize_domains_list(string $raw): array {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $domains = [];

        foreach ($lines as $line) {
            $domain = strtolower(trim($line));
            $domain = preg_replace('/^https?:\/\//', '', $domain);
            $domain = trim((string) $domain, "/ \t\n\r\0\x0B");

            if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    private function sanitize_subdomain(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9-]/', '', $value);
        $value = trim((string) $value, '-');

        if ($value === '') {
            return '';
        }

        if (strlen($value) < 3) {
            return substr(str_pad($value, 3, 'x'), 0, 3);
        }

        $value = substr($value, 0, 12);
        $reserved = $this->get_reserved_subdomains();

        if (in_array($value, $reserved, true)) {
            return '';
        }

        return $value;
    }

    private function sanitize_base_path(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9-]/', '', $value);
        $value = trim((string) $value, '-/');

        return $value !== '' ? $value : 'lnky';
    }

    private function sanitize_slug(string $slug): string {
        $slug = sanitize_title($slug);

        return trim((string) $slug, '/');
    }

    private function get_reserved_subdomains(): array {
        return [
            'www',
            'api',
            'app',
            'admin',
            'mail',
            'ftp',
            'blog',
            'dev',
            'test',
            'help',
            'support',
            'cdn',
            'news',
            'go',
            'link',
            'lnky',
        ];
    }

    private function generate_subdomain_suggestion(): string {
        $site_name = (string) get_bloginfo('name');
        $words = preg_split('/[^a-z0-9]+/i', strtolower($site_name)) ?: [];
        $words = array_values(array_filter($words));

        $suggestion = '';

        foreach (array_slice($words, 0, 3) as $word) {
            $suggestion .= substr($word, 0, 1);
        }

        if (strlen($suggestion) < 3 && !empty($words[0])) {
            $suggestion = substr($words[0], 0, 3);
        }

        $suggestion = $this->sanitize_subdomain($suggestion ?: 'lnk');

        return $suggestion ?: 'lnk';
    }

    private function get_public_host(): string {
        $settings = $this->get_settings();
        $domain = (string) $settings['selected_domain'];
        $subdomain = (string) $settings['workspace_subdomain'];

        if ($subdomain !== '') {
            return $subdomain . '.' . $domain;
        }

        return $domain;
    }

    private function build_public_link(string $slug): string {
        $slug = $slug !== '' ? $slug : 'slug-auto';

        return $this->get_public_host() . '/' . ltrim($slug, '/');
    }

    private function build_public_link_url(string $slug): string {
        return 'https://' . $this->build_public_link($slug);
    }

    private function get_qr_logo_url(): string {
        $custom_logo_id = (int) get_theme_mod('custom_logo');

        if ($custom_logo_id > 0) {
            $custom_logo_url = wp_get_attachment_image_url($custom_logo_id, 'thumbnail');

            if (is_string($custom_logo_url) && $custom_logo_url !== '') {
                return $custom_logo_url;
            }
        }

        $site_icon_url = get_site_icon_url(128);

        if (is_string($site_icon_url) && $site_icon_url !== '') {
            return $site_icon_url;
        }

        return plugin_dir_url(__FILE__) . 'assets/images/logo-lnky-short-links.png';
    }

    private function build_local_preview_link(string $slug): string {
        $settings = $this->get_settings();
        $base_path = trim((string) $settings['local_base_path'], '/');
        $slug = $slug !== '' ? ltrim($slug, '/') : 'slug-auto';

        return home_url('/' . $base_path . '/' . $slug . '/');
    }

    private function get_links(): array {
        global $wpdb;

        $table = $this->get_links_table_name();
        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A);

        return is_array($results) ? $results : [];
    }

    private function get_link(int $link_id): ?array {
        global $wpdb;

        $table = $this->get_links_table_name();
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $link_id), ARRAY_A);

        return is_array($result) ? $result : null;
    }

    private function get_link_stats(): array {
        global $wpdb;

        $table = $this->get_links_table_name();
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total_links,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_links,
                COALESCE(SUM(click_count), 0) AS total_clicks,
                MAX(click_count) AS max_clicks
            FROM {$table}",
            ARRAY_A
        );

        $best_slug = (string) $wpdb->get_var("SELECT slug FROM {$table} ORDER BY click_count DESC, created_at DESC LIMIT 1");

        $total_links = isset($row['total_links']) ? (int) $row['total_links'] : 0;
        $active_links = isset($row['active_links']) ? (int) $row['active_links'] : 0;
        $total_clicks = isset($row['total_clicks']) ? (int) $row['total_clicks'] : 0;

        return [
            'total_links' => $total_links,
            'active_links' => $active_links,
            'total_clicks' => $total_clicks,
            'average_clicks' => $total_links > 0 ? $total_clicks / $total_links : 0,
            'best_slug' => $best_slug,
        ];
    }

    private function get_top_links(int $limit = 5): array {
        global $wpdb;

        $table = $this->get_links_table_name();
        $limit = max(1, min(20, $limit));
        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY click_count DESC, created_at DESC LIMIT {$limit}", ARRAY_A);

        return is_array($results) ? $results : [];
    }

    private function get_recent_links(int $limit = 5): array {
        global $wpdb;

        $table = $this->get_links_table_name();
        $limit = max(1, min(20, $limit));
        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT {$limit}", ARRAY_A);

        return is_array($results) ? $results : [];
    }

    private function format_datetime_or_placeholder(string $value): string {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return __('Jamais', 'lnky-short-links');
        }

        $timestamp = mysql2date('U', $value, false);

        if (!$timestamp) {
            return __('Jamais', 'lnky-short-links');
        }

        return wp_date('d/m/Y H:i', $timestamp);
    }

    private function get_link_by_slug(string $slug): ?array {
        global $wpdb;

        $table = $this->get_links_table_name();
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug), ARRAY_A);

        return is_array($result) ? $result : null;
    }

    private function is_slug_available(string $slug, int $except_id = 0): bool {
        global $wpdb;

        $table = $this->get_links_table_name();
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug)
        );

        if ($existing_id === 0) {
            return true;
        }

        return $existing_id === $except_id;
    }

    private function generate_unique_slug(): string {
        do {
            $slug = strtolower(wp_generate_password(6, false, false));
            $slug = preg_replace('/[^a-z0-9]/', '', $slug);
        } while ($slug === '' || !$this->is_slug_available($slug));

        return $slug;
    }

    private function is_allowed_internal_type(string $type): bool {
        $allowed = ['page', 'post'];

        if ($this->is_woocommerce_active()) {
            $allowed[] = 'product';
        }

        return in_array($type, $allowed, true);
    }

    private function is_woocommerce_active(): bool {
        return post_type_exists('product');
    }

    private function format_target_type(string $type): string {
        $labels = [
            'url' => __('URL externe', 'lnky-short-links'),
            'page' => __('Page', 'lnky-short-links'),
            'post' => __('Article', 'lnky-short-links'),
            'product' => __('Produit', 'lnky-short-links'),
        ];

        return $labels[$type] ?? $type;
    }

    private function render_admin_notices(): void {
        if (empty($_GET['lnky_notice'])) {
            return;
        }

        $notice = sanitize_key((string) wp_unslash($_GET['lnky_notice']));
        $messages = [
            'settings_saved' => __('Reglages enregistres.', 'lnky-short-links'),
            'settings_synced' => __('Reglages enregistres et workspace synchronise avec l API Lnky.', 'lnky-short-links'),
            'settings_sync_failed' => __('Reglages enregistres, mais la synchronisation API a echoue.', 'lnky-short-links'),
            'link_created' => __('Lien cree.', 'lnky-short-links'),
            'link_updated' => __('Lien mis a jour.', 'lnky-short-links'),
            'link_created_synced' => __('Lien cree et synchronise avec l API Lnky.', 'lnky-short-links'),
            'link_updated_synced' => __('Lien mis a jour et synchronise avec l API Lnky.', 'lnky-short-links'),
            'link_created_sync_failed' => __('Lien cree dans WordPress, mais la synchronisation API a echoue.', 'lnky-short-links'),
            'link_updated_sync_failed' => __('Lien mis a jour dans WordPress, mais la synchronisation API a echoue.', 'lnky-short-links'),
            'link_deleted' => __('Lien supprime.', 'lnky-short-links'),
            'slug_taken' => __('Ce slug est deja utilise.', 'lnky-short-links'),
            'missing_target' => __('Choisis une destination valide.', 'lnky-short-links'),
            'invalid_target' => __('Le contenu selectionne ne correspond pas au type choisi.', 'lnky-short-links'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($messages[$notice]); ?></p>
        </div>
        <?php
    }
}

$lnky_short_links = new Lnky_Short_Links();
$lnky_short_links->boot();
