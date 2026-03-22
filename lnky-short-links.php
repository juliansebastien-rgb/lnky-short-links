<?php
/**
 * Plugin Name: Lnky Short Links
 * Plugin URI: https://example.com/lnky-short-links
 * Description: Cree des liens courts avec slugs personnalises, destinations externes ou contenus WordPress, et redirections trackees.
 * Version: 0.1.1
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lnky-short-links
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lnky_Short_Links {
    private const VERSION = '0.1.1';
    private const OPTION_KEY = 'lnky_short_links_settings';
    private const QUERY_VAR = 'lnky_slug';
    private const MENU_SLUG = 'lnky-short-links';
    private const ADD_MENU_SLUG = 'lnky-short-links-add';
    private const SETTINGS_MENU_SLUG = 'lnky-short-links-settings';

    public function boot(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_handle_redirect'], 0);

        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_lnky_search_content', [$this, 'ajax_search_content']);

        add_action('admin_post_lnky_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_lnky_save_link', [$this, 'handle_save_link']);
        add_action('admin_post_lnky_delete_link', [$this, 'handle_delete_link']);

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
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
            'lnky-short-links-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            [],
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
            ]
        );
    }

    public function plugin_action_links(array $links): array {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=' . self::SETTINGS_MENU_SLUG)) . '">' . esc_html__('Settings', 'lnky-short-links') . '</a>';
        $add_link = '<a href="' . esc_url(admin_url('admin.php?page=' . self::ADD_MENU_SLUG)) . '">' . esc_html__('Add Link', 'lnky-short-links') . '</a>';

        array_unshift($links, $settings_link, $add_link);

        return $links;
    }

    public function render_links_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $links = $this->get_links();
        ?>
        <div class="wrap lnky-admin">
            <h1><?php echo esc_html__('Lnky Links', 'lnky-short-links'); ?></h1>
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

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Lien court', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Destination', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Type', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Redirection', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Statut', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Clics', 'lnky-short-links'); ?></th>
                        <th><?php echo esc_html__('Actions', 'lnky-short-links'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($links)) : ?>
                        <tr>
                            <td colspan="7"><?php echo esc_html__('Aucun lien pour le moment.', 'lnky-short-links'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($links as $link) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($this->build_public_link($link['slug'])); ?></strong><br>
                                    <code><?php echo esc_html($this->build_local_preview_link($link['slug'])); ?></code>
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
            <h1><?php echo $link['id'] ? esc_html__('Modifier le lien', 'lnky-short-links') : esc_html__('Ajouter un lien', 'lnky-short-links'); ?></h1>
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
                                    <p class="description">
                                        <?php echo esc_html__('Apercu local de test :', 'lnky-short-links'); ?>
                                        <code
                                            id="lnky_live_local_preview"
                                            data-lnky-preview-base-path="<?php echo esc_attr($settings['local_base_path']); ?>"
                                            data-lnky-preview-home="<?php echo esc_attr(home_url('/')); ?>"
                                        ><?php echo esc_html($this->build_local_preview_link($link['slug'] ?: 'slug-auto')); ?></code>
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
                    <?php echo esc_html__('Mode :', 'lnky-short-links'); ?>
                    <strong><?php echo esc_html($settings['connection_mode'] === 'api' ? __('SaaS / API', 'lnky-short-links') : __('Autonome WordPress', 'lnky-short-links')); ?></strong>
                </p>
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
            <h1><?php echo esc_html__('Reglages Lnky', 'lnky-short-links'); ?></h1>
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
                                <th scope="row"><label for="lnky_connection_mode"><?php echo esc_html__('Mode de fonctionnement', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <select id="lnky_connection_mode" name="connection_mode">
                                        <option value="standalone" <?php selected($settings['connection_mode'], 'standalone'); ?>><?php echo esc_html__('Autonome WordPress', 'lnky-short-links'); ?></option>
                                        <option value="api" <?php selected($settings['connection_mode'], 'api'); ?>><?php echo esc_html__('SaaS / API centrale', 'lnky-short-links'); ?></option>
                                    </select>
                                    <p class="description"><?php echo esc_html__('Le mode autonome gere les redirections localement. Le mode API prepare la connexion future a ton service central Lnky.', 'lnky-short-links'); ?></p>
                                </td>
                            </tr>
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
                                <th scope="row"><label for="lnky_local_base_path"><?php echo esc_html__('Chemin local de test', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <input type="text" id="lnky_local_base_path" name="local_base_path" class="regular-text" value="<?php echo esc_attr($settings['local_base_path']); ?>" placeholder="lnky">
                                    <p class="description"><?php echo esc_html__('Permet de tester les redirections sur le site WordPress actuel, par exemple /lnky/promo.', 'lnky-short-links'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="lnky_api_base_url"><?php echo esc_html__('Base URL API', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <input type="url" id="lnky_api_base_url" name="api_base_url" class="regular-text code" value="<?php echo esc_attr($settings['api_base_url']); ?>" placeholder="https://api.lnky.fr">
                                    <p class="description"><?php echo esc_html__('Prevu pour la future plateforme centrale qui gerera les sous-domaines dynamiques et la synchronisation des liens.', 'lnky-short-links'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="lnky_api_key"><?php echo esc_html__('Cle API', 'lnky-short-links'); ?></label></th>
                                <td>
                                    <input type="text" id="lnky_api_key" name="api_key" class="regular-text code" value="<?php echo esc_attr($settings['api_key']); ?>" placeholder="lnky_live_xxx">
                                    <p class="description"><?php echo esc_html__('Champ deja pret pour l authentification future du plugin vers ton service Lnky.', 'lnky-short-links'); ?></p>
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
                <p>
                    <code
                        id="lnky_settings_local_preview"
                        data-lnky-settings-base-path="<?php echo esc_attr($settings['local_base_path']); ?>"
                        data-lnky-settings-home="<?php echo esc_attr(home_url('/')); ?>"
                    ><?php echo esc_html($this->build_local_preview_link('offre')); ?></code>
                </p>
            </div>

            <div class="lnky-admin__card">
                <h2><?php echo esc_html__('Etat SaaS / API', 'lnky-short-links'); ?></h2>
                <?php if ($settings['connection_mode'] === 'api') : ?>
                    <p><?php echo esc_html($this->get_api_readiness_message($settings)); ?></p>
                <?php else : ?>
                    <p><?php echo esc_html__('Le plugin tourne actuellement en mode autonome. La configuration API peut rester vide tant que la plateforme centrale Lnky n est pas en place.', 'lnky-short-links'); ?></p>
                <?php endif; ?>
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
        $connection_mode = isset($_POST['connection_mode']) ? sanitize_key((string) wp_unslash($_POST['connection_mode'])) : 'standalone';
        $selected_domain = isset($_POST['selected_domain']) ? strtolower((string) wp_unslash($_POST['selected_domain'])) : '';
        $workspace_subdomain = isset($_POST['workspace_subdomain']) ? $this->sanitize_subdomain((string) wp_unslash($_POST['workspace_subdomain'])) : '';
        $local_base_path = isset($_POST['local_base_path']) ? $this->sanitize_base_path((string) wp_unslash($_POST['local_base_path'])) : 'lnky';
        $api_base_url = isset($_POST['api_base_url']) ? esc_url_raw((string) wp_unslash($_POST['api_base_url'])) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field((string) wp_unslash($_POST['api_key'])) : '';

        if (!in_array($connection_mode, ['standalone', 'api'], true)) {
            $connection_mode = 'standalone';
        }

        if (empty($available_domains)) {
            $available_domains = $this->get_default_settings()['available_domains'];
        }

        if (!in_array($selected_domain, $available_domains, true)) {
            $selected_domain = $available_domains[0];
        }

        update_option(
            self::OPTION_KEY,
            [
                'connection_mode' => $connection_mode,
                'available_domains' => $available_domains,
                'selected_domain' => $selected_domain,
                'workspace_subdomain' => $workspace_subdomain,
                'local_base_path' => $local_base_path,
                'api_base_url' => $api_base_url,
                'api_key' => $api_key,
            ],
            false
        );

        $this->register_rewrite_rules();
        flush_rewrite_rules();

        wp_safe_redirect(admin_url('admin.php?page=' . self::SETTINGS_MENU_SLUG . '&lnky_notice=settings_saved'));
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
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET click_count = click_count + 1 WHERE id = %d", $link_id));
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
            'connection_mode' => 'standalone',
            'available_domains' => ['lnky.fr'],
            'selected_domain' => 'lnky.fr',
            'workspace_subdomain' => '',
            'local_base_path' => 'lnky',
            'api_base_url' => 'https://api.lnky.fr',
            'api_key' => '',
        ];
    }

    private function get_settings(): array {
        $settings = get_option(self::OPTION_KEY, []);

        return wp_parse_args(is_array($settings) ? $settings : [], $this->get_default_settings());
    }

    private function get_api_readiness_message(array $settings): string {
        $api_base_url = (string) ($settings['api_base_url'] ?? '');
        $api_key = (string) ($settings['api_key'] ?? '');

        if ($api_base_url === '' && $api_key === '') {
            return __('Mode API active, mais la base URL et la cle API ne sont pas encore renseignees.', 'lnky-short-links');
        }

        if ($api_base_url === '') {
            return __('Mode API active, mais la base URL API manque encore.', 'lnky-short-links');
        }

        if ($api_key === '') {
            return __('Mode API active, mais la cle API manque encore.', 'lnky-short-links');
        }

        return __('La configuration API est renseignee. Il restera a brancher les endpoints de ton service central Lnky pour la reservation de sous-domaines et la synchronisation des liens.', 'lnky-short-links');
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
            'link_created' => __('Lien cree.', 'lnky-short-links'),
            'link_updated' => __('Lien mis a jour.', 'lnky-short-links'),
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
