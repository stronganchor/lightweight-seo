<?php
/**
 * Plugin Name: Strong Anchor Lightweight SEO
 * Plugin URI: https://github.com/stronganchor/lightweight-seo
 * Description: Lightweight SEO tools for LocalBusiness/Organization JSON-LD and simple search snippet editing.
 * Version: 1.0.2
 * Update URI: https://github.com/stronganchor/lightweight-seo
 * Author: Strong Anchor Tech
 * Author URI: https://stronganchortech.com
 * License: GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SAT_LIGHTWEIGHT_SEO_VERSION', '1.0.2' );
define( 'SAT_LIGHTWEIGHT_SEO_FILE', __FILE__ );
define( 'SAT_LIGHTWEIGHT_SEO_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Get the update branch for the bundled update checker.
 *
 * @return string
 */
function sat_lwseo_get_update_branch() {
    $branch = 'main';

    if ( defined( 'SAT_LIGHTWEIGHT_SEO_UPDATE_BRANCH' ) && is_string( SAT_LIGHTWEIGHT_SEO_UPDATE_BRANCH ) ) {
        $override = trim( SAT_LIGHTWEIGHT_SEO_UPDATE_BRANCH );
        if ( '' !== $override ) {
            $branch = $override;
        }
    }

    return (string) apply_filters( 'sat_lwseo_update_branch', $branch );
}

/**
 * Load the bundled GitHub update checker when available.
 *
 * @return void
 */
function sat_lwseo_bootstrap_update_checker() {
    $checker_file = SAT_LIGHTWEIGHT_SEO_DIR . 'plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $checker_file ) ) {
        return;
    }

    require_once $checker_file;

    if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        return;
    }

    $repo_url = (string) apply_filters(
        'sat_lwseo_update_repository',
        'https://github.com/stronganchor/lightweight-seo'
    );
    $slug     = dirname( plugin_basename( SAT_LIGHTWEIGHT_SEO_FILE ) );

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        $repo_url,
        SAT_LIGHTWEIGHT_SEO_FILE,
        $slug
    );

    $update_checker->setBranch( sat_lwseo_get_update_branch() );

    foreach ( array( 'SAT_LIGHTWEIGHT_SEO_GITHUB_TOKEN', 'STRONGANCHOR_GITHUB_TOKEN', 'ANCHOR_GITHUB_TOKEN' ) as $constant_name ) {
        if ( ! defined( $constant_name ) || ! is_string( constant( $constant_name ) ) ) {
            continue;
        }

        $token = trim( (string) constant( $constant_name ) );
        if ( '' !== $token ) {
            $update_checker->setAuthentication( $token );
            break;
        }
    }
}

sat_lwseo_bootstrap_update_checker();

if ( ! class_exists( 'SAT_Lightweight_SEO' ) ) {
    class SAT_Lightweight_SEO {
        const OPTION_KEY      = 'sat_lwseo_settings';
        const OPTION_GROUP    = 'sat_lwseo_group';
        const MENU_SLUG       = 'sat-lightweight-seo';
        const META_TITLE_KEY  = '_sat_lwseo_meta_title';
        const META_DESC_KEY   = '_sat_lwseo_meta_desc';
        const AJAX_NONCE_KEY  = 'sat_lwseo_scan_nonce';

        /** @var self|null */
        private static $instance = null;

        /**
         * Get singleton.
         *
         * @return self
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Constructor.
         */
        private function __construct() {
            add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
            add_action( 'wp_ajax_sat_lwseo_scan_site', array( $this, 'ajax_scan_site' ) );

            add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
            add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );

            add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ) );
            add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
            add_action( 'wp_head', array( $this, 'output_json_ld' ), 5 );
        }

        /**
         * Default option values.
         *
         * @return array<string,mixed>
         */
        public function get_defaults() {
            return array(
                'enabled'           => 1,
                'schema_type'       => 'ProfessionalService',
                'business_name'     => '',
                'alternate_name'    => '',
                'description'       => '',
                'url'               => home_url( '/' ),
                'logo'              => '',
                'telephone'         => '',
                'email'             => '',
                'street_address'    => '',
                'address_locality'  => '',
                'address_region'    => '',
                'postal_code'       => '',
                'address_country'   => '',
                'area_served'       => '',
                'same_as'           => '',
                'price_range'       => '',
                'founder'           => '',
            );
        }

        /**
         * Get saved settings merged with defaults.
         *
         * @return array<string,mixed>
         */
        public function get_settings() {
            $saved = get_option( self::OPTION_KEY, array() );
            if ( ! is_array( $saved ) ) {
                $saved = array();
            }

            return wp_parse_args( $saved, $this->get_defaults() );
        }

        /**
         * Register plugin settings.
         */
        public function register_settings() {
            register_setting(
                self::OPTION_GROUP,
                self::OPTION_KEY,
                array(
                    'type'              => 'array',
                    'sanitize_callback' => array( $this, 'sanitize_settings' ),
                    'default'           => $this->get_defaults(),
                )
            );
        }

        /**
         * Sanitize settings.
         *
         * @param mixed $input Raw input.
         * @return array<string,mixed>
         */
        public function sanitize_settings( $input ) {
            $defaults = $this->get_defaults();
            $input    = is_array( $input ) ? $input : array();
            $output   = array();

            $output['enabled']          = empty( $input['enabled'] ) ? 0 : 1;
            $output['schema_type']      = $this->sanitize_schema_type( isset( $input['schema_type'] ) ? $input['schema_type'] : $defaults['schema_type'] );
            $output['business_name']    = sanitize_text_field( isset( $input['business_name'] ) ? $input['business_name'] : '' );
            $output['alternate_name']   = sanitize_text_field( isset( $input['alternate_name'] ) ? $input['alternate_name'] : '' );
            $output['description']      = sanitize_textarea_field( isset( $input['description'] ) ? $input['description'] : '' );
            $output['url']              = esc_url_raw( isset( $input['url'] ) ? $input['url'] : home_url( '/' ) );
            $output['logo']             = esc_url_raw( isset( $input['logo'] ) ? $input['logo'] : '' );
            $output['telephone']        = sanitize_text_field( isset( $input['telephone'] ) ? $input['telephone'] : '' );
            $output['email']            = sanitize_email( isset( $input['email'] ) ? $input['email'] : '' );
            $output['street_address']   = sanitize_text_field( isset( $input['street_address'] ) ? $input['street_address'] : '' );
            $output['address_locality'] = sanitize_text_field( isset( $input['address_locality'] ) ? $input['address_locality'] : '' );
            $output['address_region']   = sanitize_text_field( isset( $input['address_region'] ) ? $input['address_region'] : '' );
            $output['postal_code']      = sanitize_text_field( isset( $input['postal_code'] ) ? $input['postal_code'] : '' );
            $output['address_country']  = sanitize_text_field( isset( $input['address_country'] ) ? $input['address_country'] : '' );
            $output['area_served']      = $this->sanitize_multiline_text( isset( $input['area_served'] ) ? $input['area_served'] : '' );
            $output['same_as']          = $this->sanitize_multiline_urls( isset( $input['same_as'] ) ? $input['same_as'] : '' );
            $output['price_range']      = sanitize_text_field( isset( $input['price_range'] ) ? $input['price_range'] : '' );
            $output['founder']          = sanitize_text_field( isset( $input['founder'] ) ? $input['founder'] : '' );

            return $output;
        }

        /**
         * Register admin menu.
         */
        public function register_admin_menu() {
            add_options_page(
                __( 'Lightweight SEO', 'sat-lwseo' ),
                __( 'Lightweight SEO', 'sat-lwseo' ),
                'manage_options',
                self::MENU_SLUG,
                array( $this, 'render_settings_page' )
            );
        }

        /**
         * Enqueue admin assets.
         *
         * @param string $hook Current admin hook.
         */
        public function admin_assets( $hook ) {
            if ( 'settings_page_' . self::MENU_SLUG === $hook ) {
                wp_enqueue_script( 'jquery' );
            }
        }

        /**
         * Render settings page.
         */
        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $settings = $this->get_settings();
            ?>
            <div class="wrap sat-lwseo-wrap">
                <h1><?php echo esc_html__( 'Lightweight SEO', 'sat-lwseo' ); ?></h1>
                <p><?php echo esc_html__( 'Manage lightweight LocalBusiness / Organization JSON-LD and simple SEO snippet settings.', 'sat-lwseo' ); ?></p>

                <div class="sat-lwseo-panel">
                    <h2><?php echo esc_html__( 'JSON-LD Schema Settings', 'sat-lwseo' ); ?></h2>
                    <p><?php echo esc_html__( 'Use the scan button to pull suggested values from the homepage and contact-style pages. Suggestions are only saved after you click Save Changes.', 'sat-lwseo' ); ?></p>

                    <p>
                        <button type="button" class="button button-secondary" id="sat-lwseo-scan-button">
                            <?php echo esc_html__( 'Scan Site for Suggestions', 'sat-lwseo' ); ?>
                        </button>
                        <span id="sat-lwseo-scan-status" style="margin-left:10px;"></span>
                    </p>
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields( self::OPTION_GROUP ); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Enable JSON-LD output', 'sat-lwseo' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                                        <?php echo esc_html__( 'Output schema on the homepage.', 'sat-lwseo' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <?php $this->render_text_field( 'schema_type', 'Schema type', $settings['schema_type'], 'Organization, LocalBusiness, or ProfessionalService. Default is ProfessionalService.' ); ?>
                            <?php $this->render_text_field( 'business_name', 'Business name', $settings['business_name'] ); ?>
                            <?php $this->render_text_field( 'alternate_name', 'Alternate name', $settings['alternate_name'] ); ?>
                            <?php $this->render_textarea_field( 'description', 'Description', $settings['description'], 4 ); ?>
                            <?php $this->render_text_field( 'url', 'Canonical URL', $settings['url'] ); ?>
                            <?php $this->render_text_field( 'logo', 'Logo URL', $settings['logo'] ); ?>
                            <?php $this->render_text_field( 'telephone', 'Telephone', $settings['telephone'] ); ?>
                            <?php $this->render_text_field( 'email', 'Email', $settings['email'] ); ?>
                            <?php $this->render_text_field( 'street_address', 'Street address', $settings['street_address'] ); ?>
                            <?php $this->render_text_field( 'address_locality', 'City / locality', $settings['address_locality'] ); ?>
                            <?php $this->render_text_field( 'address_region', 'State / region', $settings['address_region'] ); ?>
                            <?php $this->render_text_field( 'postal_code', 'Postal code', $settings['postal_code'] ); ?>
                            <?php $this->render_text_field( 'address_country', 'Country code or name', $settings['address_country'], 'Example: US' ); ?>
                            <?php $this->render_textarea_field( 'area_served', 'Areas served', $settings['area_served'], 5, 'One per line.' ); ?>
                            <?php $this->render_textarea_field( 'same_as', 'Social / profile URLs', $settings['same_as'], 5, 'One URL per line.' ); ?>
                            <?php $this->render_text_field( 'price_range', 'Price range', $settings['price_range'], 'Optional. Example: $$' ); ?>
                            <?php $this->render_text_field( 'founder', 'Founder', $settings['founder'] ); ?>
                        </tbody>
                    </table>

                    <?php submit_button( __( 'Save Changes', 'sat-lwseo' ) ); ?>
                </form>
            </div>

            <style>
                .sat-lwseo-wrap .sat-lwseo-panel {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    padding: 16px;
                    margin: 18px 0;
                }
                .sat-lwseo-wrap .sat-lwseo-input,
                .sat-lwseo-wrap textarea.sat-lwseo-input {
                    width: 100%;
                    max-width: 720px;
                }
                .sat-lwseo-scan-good { color: #008a20; }
                .sat-lwseo-scan-warn { color: #996800; }
                .sat-lwseo-help { color: #50575e; margin-top: 6px; }
            </style>

            <script>
            jQuery(function($) {
                const button = $('#sat-lwseo-scan-button');
                const status = $('#sat-lwseo-scan-status');

                button.on('click', function() {
                    status.removeClass('sat-lwseo-scan-good sat-lwseo-scan-warn').text('Scanning...');
                    button.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'sat_lwseo_scan_site',
                        nonce: '<?php echo esc_js( wp_create_nonce( self::AJAX_NONCE_KEY ) ); ?>'
                    }).done(function(response) {
                        if (!response || !response.success || !response.data || !response.data.suggestions) {
                            status.addClass('sat-lwseo-scan-warn').text('Scan did not return usable suggestions.');
                            return;
                        }

                        const data = response.data.suggestions;
                        Object.keys(data).forEach(function(key) {
                            const field = $('[name="<?php echo esc_js( self::OPTION_KEY ); ?>[' + key + ']"]');
                            if (field.length && data[key]) {
                                field.val(data[key]);
                            }
                        });

                        status.addClass('sat-lwseo-scan-good').text('Suggestions loaded into the form. Review and click Save Changes to confirm.');
                    }).fail(function() {
                        status.addClass('sat-lwseo-scan-warn').text('Scan failed.');
                    }).always(function() {
                        button.prop('disabled', false);
                    });
                });
            });
            </script>
            <?php
        }

        /**
         * Render a text field row.
         *
         * @param string $key Option key.
         * @param string $label Label.
         * @param string $value Value.
         * @param string $help Help text.
         */
        private function render_text_field( $key, $label, $value, $help = '' ) {
            ?>
            <tr>
                <th scope="row"><label for="sat-lwseo-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input
                        type="text"
                        class="regular-text sat-lwseo-input"
                        id="sat-lwseo-<?php echo esc_attr( $key ); ?>"
                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]"
                        value="<?php echo esc_attr( $value ); ?>"
                    >
                    <?php if ( $help ) : ?>
                        <p class="description sat-lwseo-help"><?php echo esc_html( $help ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }

        /**
         * Render a textarea field row.
         *
         * @param string $key Option key.
         * @param string $label Label.
         * @param string $value Value.
         * @param int    $rows Rows.
         * @param string $help Help text.
         */
        private function render_textarea_field( $key, $label, $value, $rows = 4, $help = '' ) {
            ?>
            <tr>
                <th scope="row"><label for="sat-lwseo-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <textarea
                        class="large-text sat-lwseo-input"
                        id="sat-lwseo-<?php echo esc_attr( $key ); ?>"
                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]"
                        rows="<?php echo esc_attr( $rows ); ?>"
                    ><?php echo esc_textarea( $value ); ?></textarea>
                    <?php if ( $help ) : ?>
                        <p class="description sat-lwseo-help"><?php echo esc_html( $help ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }

        /**
         * AJAX site scan.
         */
        public function ajax_scan_site() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
            }

            check_ajax_referer( self::AJAX_NONCE_KEY, 'nonce' );

            $suggestions = $this->scan_site_for_suggestions();

            wp_send_json_success(
                array(
                    'suggestions' => $suggestions,
                )
            );
        }

        /**
         * Scan the site and return suggested settings.
         *
         * @return array<string,string>
         */
        private function scan_site_for_suggestions() {
            $defaults = $this->get_defaults();
            $sources  = array();
            $emails   = array();
            $home_url = home_url( '/' );

            $sources[] = array(
                'business_name'   => get_bloginfo( 'name' ),
                'description'     => get_bloginfo( 'description' ),
                'url'             => $home_url,
                'logo'            => $this->get_site_logo_url(),
                'schema_type'     => 'ProfessionalService',
                'address_country' => $this->guess_country_from_locale(),
            );

            $home_analysis = $this->analyze_rendered_url( $home_url );
            if ( ! empty( $home_analysis ) ) {
                $sources[] = $home_analysis;
                $emails[]  = array(
                    'url'   => $home_url,
                    'email' => isset( $home_analysis['email'] ) ? $home_analysis['email'] : '',
                );
            }

            foreach ( $this->find_priority_pages() as $page_url ) {
                $page_analysis = $this->analyze_rendered_url( $page_url );
                if ( ! empty( $page_analysis ) ) {
                    $sources[] = $page_analysis;
                    $emails[]  = array(
                        'url'   => $page_url,
                        'email' => isset( $page_analysis['email'] ) ? $page_analysis['email'] : '',
                    );
                }
            }

            $merged = $defaults;
            foreach ( $sources as $source ) {
                foreach ( $source as $key => $value ) {
                    if ( empty( $value ) ) {
                        continue;
                    }

                    if ( empty( $merged[ $key ] ) ) {
                        $merged[ $key ] = $value;
                    }
                }
            }

            if ( empty( $merged['business_name'] ) ) {
                $merged['business_name'] = get_bloginfo( 'name' );
            }

            if ( empty( $merged['description'] ) ) {
                $merged['description'] = get_bloginfo( 'description' );
            }

            if ( empty( $merged['url'] ) ) {
                $merged['url'] = home_url( '/' );
            }

            $best_email = $this->choose_best_public_email( $emails );
            if ( '' !== $best_email ) {
                $merged['email'] = $best_email;
            }

            return $merged;
        }

        /**
         * Find likely contact/about pages.
         *
         * @return array<int,string>
         */
        private function find_priority_pages() {
            $urls  = array();
            $pages = get_posts(
                array(
                    'post_type'      => 'page',
                    'post_status'    => 'publish',
                    'posts_per_page' => 20,
                    'orderby'        => 'menu_order title',
                    'order'          => 'ASC',
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                )
            );

            if ( empty( $pages ) ) {
                return array();
            }

            $needles = array(
                'contact',
                'about',
                'location',
                'locations',
                'reach',
                'get-in-touch',
                'connect',
            );

            foreach ( $pages as $page_id ) {
                $post = get_post( $page_id );
                if ( ! $post ) {
                    continue;
                }

                $haystack = strtolower( $post->post_name . ' ' . $post->post_title );
                foreach ( $needles as $needle ) {
                    if ( false !== strpos( $haystack, $needle ) ) {
                        $urls[] = get_permalink( $page_id );
                        break;
                    }
                }
            }

            return array_values( array_unique( array_filter( $urls ) ) );
        }

        /**
         * Analyze a rendered URL for SEO data suggestions.
         *
         * @param string $url URL.
         * @return array<string,string>
         */
        private function analyze_rendered_url( $url ) {
            $response = wp_remote_get(
                $url,
                array(
                    'timeout'     => 12,
                    'redirection' => 3,
                    'user-agent'  => 'StrongAnchor-Lightweight-SEO/' . SAT_LIGHTWEIGHT_SEO_VERSION . '; ' . home_url( '/' ),
                )
            );

            if ( is_wp_error( $response ) ) {
                return array();
            }

            $html = wp_remote_retrieve_body( $response );
            if ( ! is_string( $html ) || '' === trim( $html ) ) {
                return array();
            }

            $analysis = array();
            $dom      = $this->safe_load_html( $html );

            if ( ! $dom ) {
                return array();
            }

            $xpath  = new DOMXPath( $dom );
            $schema = $this->extract_schema_from_dom( $xpath );

            if ( ! empty( $schema['schema_type'] ) ) {
                $analysis['schema_type'] = $schema['schema_type'];
            }
            if ( ! empty( $schema['business_name'] ) ) {
                $analysis['business_name'] = $schema['business_name'];
            }
            if ( ! empty( $schema['alternate_name'] ) ) {
                $analysis['alternate_name'] = $schema['alternate_name'];
            }
            if ( ! empty( $schema['description'] ) ) {
                $analysis['description'] = $schema['description'];
            }
            if ( ! empty( $schema['url'] ) ) {
                $analysis['url'] = $schema['url'];
            }
            if ( ! empty( $schema['logo'] ) ) {
                $analysis['logo'] = $schema['logo'];
            }
            if ( ! empty( $schema['telephone'] ) ) {
                $analysis['telephone'] = $schema['telephone'];
            }
            if ( ! empty( $schema['email'] ) ) {
                $analysis['email'] = $schema['email'];
            }
            if ( ! empty( $schema['street_address'] ) ) {
                $analysis['street_address'] = $schema['street_address'];
            }
            if ( ! empty( $schema['address_locality'] ) ) {
                $analysis['address_locality'] = $schema['address_locality'];
            }
            if ( ! empty( $schema['address_region'] ) ) {
                $analysis['address_region'] = $schema['address_region'];
            }
            if ( ! empty( $schema['postal_code'] ) ) {
                $analysis['postal_code'] = $schema['postal_code'];
            }
            if ( ! empty( $schema['address_country'] ) ) {
                $analysis['address_country'] = $schema['address_country'];
            }
            if ( ! empty( $schema['area_served'] ) ) {
                $analysis['area_served'] = $schema['area_served'];
            }
            if ( ! empty( $schema['same_as'] ) ) {
                $analysis['same_as'] = $schema['same_as'];
            }
            if ( ! empty( $schema['price_range'] ) ) {
                $analysis['price_range'] = $schema['price_range'];
            }
            if ( ! empty( $schema['founder'] ) ) {
                $analysis['founder'] = $schema['founder'];
            }

            $meta_desc = $this->extract_meta_description( $xpath );
            if ( empty( $analysis['description'] ) && $meta_desc ) {
                $analysis['description'] = $meta_desc;
            }

            $title_text = $this->extract_title_text( $xpath );
            if ( empty( $analysis['business_name'] ) && $title_text ) {
                $analysis['business_name'] = $title_text;
            }

            $logo_url = $this->extract_logo_url( $xpath );
            if ( empty( $analysis['logo'] ) && $logo_url ) {
                $analysis['logo'] = $logo_url;
            }

            $emails = $this->extract_emails( $html );
            if ( empty( $analysis['email'] ) && ! empty( $emails ) ) {
                $analysis['email'] = reset( $emails );
            }

            $phones = $this->extract_phone_numbers( $html );
            if ( empty( $analysis['telephone'] ) && ! empty( $phones ) ) {
                $analysis['telephone'] = reset( $phones );
            }

            $socials = $this->extract_social_urls( $xpath );
            if ( empty( $analysis['same_as'] ) && ! empty( $socials ) ) {
                $analysis['same_as'] = implode( "\n", $socials );
            }

            $text = $this->extract_visible_text( $xpath );

            if ( empty( $analysis['description'] ) && $text ) {
                $analysis['description'] = $this->first_sentence_or_trim( $text, 220 );
            }

            if ( empty( $analysis['street_address'] ) || empty( $analysis['address_locality'] ) || empty( $analysis['address_region'] ) || empty( $analysis['postal_code'] ) ) {
                $address_guess = $this->extract_address_guess( $text );
                if ( ! empty( $address_guess ) ) {
                    $analysis = array_merge( $address_guess, array_filter( $analysis ) );
                }
            }

            if ( empty( $analysis['area_served'] ) ) {
                $served = $this->extract_area_served_guess( $text );
                if ( $served ) {
                    $analysis['area_served'] = $served;
                }
            }

            if ( empty( $analysis['schema_type'] ) ) {
                $analysis['schema_type'] = 'ProfessionalService';
            }

            $analysis['url'] = home_url( '/' );

            return $analysis;
        }

        /**
         * Safe DOM load helper.
         *
         * @param string $html HTML.
         * @return DOMDocument|null
         */
        private function safe_load_html( $html ) {
            if ( ! class_exists( 'DOMDocument' ) ) {
                return null;
            }

            $dom = new DOMDocument();
            libxml_use_internal_errors( true );
            $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
            libxml_clear_errors();

            return $loaded ? $dom : null;
        }

        /**
         * Extract schema from DOM.
         *
         * @param DOMXPath $xpath XPath.
         * @return array<string,string>
         */
        private function extract_schema_from_dom( $xpath ) {
            $out     = array();
            $scripts = $xpath->query( '//script[@type="application/ld+json"]' );

            if ( ! $scripts || 0 === $scripts->length ) {
                return $out;
            }

            foreach ( $scripts as $script ) {
                $json = trim( $script->textContent );
                if ( '' === $json ) {
                    continue;
                }

                $data = json_decode( $json, true );
                if ( null === $data ) {
                    continue;
                }

                $entity = $this->find_business_entity_in_schema( $data );
                if ( empty( $entity ) || ! is_array( $entity ) ) {
                    continue;
                }

                $type = isset( $entity['@type'] ) ? $entity['@type'] : '';
                if ( is_array( $type ) ) {
                    $type = reset( $type );
                }

                $out['schema_type']   = is_string( $type ) ? sanitize_text_field( $type ) : 'ProfessionalService';
                $out['business_name'] = isset( $entity['name'] ) ? sanitize_text_field( $entity['name'] ) : '';
                $out['alternate_name'] = isset( $entity['alternateName'] ) ? sanitize_text_field( $entity['alternateName'] ) : '';
                $out['description']   = isset( $entity['description'] ) ? sanitize_textarea_field( $entity['description'] ) : '';
                $out['url']           = isset( $entity['url'] ) ? esc_url_raw( $entity['url'] ) : '';

                if ( isset( $entity['logo'] ) ) {
                    if ( is_array( $entity['logo'] ) && ! empty( $entity['logo']['url'] ) ) {
                        $out['logo'] = esc_url_raw( $entity['logo']['url'] );
                    } elseif ( is_string( $entity['logo'] ) ) {
                        $out['logo'] = esc_url_raw( $entity['logo'] );
                    }
                }

                if ( isset( $entity['image'] ) && empty( $out['logo'] ) ) {
                    if ( is_array( $entity['image'] ) && ! empty( $entity['image']['url'] ) ) {
                        $out['logo'] = esc_url_raw( $entity['image']['url'] );
                    } elseif ( is_string( $entity['image'] ) ) {
                        $out['logo'] = esc_url_raw( $entity['image'] );
                    }
                }

                $out['telephone'] = isset( $entity['telephone'] ) ? sanitize_text_field( $entity['telephone'] ) : '';
                $out['email']     = isset( $entity['email'] ) ? sanitize_email( $entity['email'] ) : '';
                $out['price_range'] = isset( $entity['priceRange'] ) ? sanitize_text_field( $entity['priceRange'] ) : '';

                if ( isset( $entity['founder'] ) ) {
                    if ( is_array( $entity['founder'] ) && ! empty( $entity['founder']['name'] ) ) {
                        $out['founder'] = sanitize_text_field( $entity['founder']['name'] );
                    } elseif ( is_string( $entity['founder'] ) ) {
                        $out['founder'] = sanitize_text_field( $entity['founder'] );
                    }
                }

                if ( isset( $entity['address'] ) && is_array( $entity['address'] ) ) {
                    $out['street_address']   = isset( $entity['address']['streetAddress'] ) ? sanitize_text_field( $entity['address']['streetAddress'] ) : '';
                    $out['address_locality'] = isset( $entity['address']['addressLocality'] ) ? sanitize_text_field( $entity['address']['addressLocality'] ) : '';
                    $out['address_region']   = isset( $entity['address']['addressRegion'] ) ? sanitize_text_field( $entity['address']['addressRegion'] ) : '';
                    $out['postal_code']      = isset( $entity['address']['postalCode'] ) ? sanitize_text_field( $entity['address']['postalCode'] ) : '';
                    $out['address_country']  = isset( $entity['address']['addressCountry'] ) ? sanitize_text_field( $entity['address']['addressCountry'] ) : '';
                }

                if ( isset( $entity['sameAs'] ) && is_array( $entity['sameAs'] ) ) {
                    $same_as = array();
                    foreach ( $entity['sameAs'] as $same_as_url ) {
                        $same_as_url = esc_url_raw( $same_as_url );
                        if ( $same_as_url ) {
                            $same_as[] = $same_as_url;
                        }
                    }
                    if ( ! empty( $same_as ) ) {
                        $out['same_as'] = implode( "\n", array_unique( $same_as ) );
                    }
                }

                if ( isset( $entity['areaServed'] ) ) {
                    $areas = $this->normalize_area_served_from_schema( $entity['areaServed'] );
                    if ( ! empty( $areas ) ) {
                        $out['area_served'] = implode( "\n", $areas );
                    }
                }

                break;
            }

            return $out;
        }

        /**
         * Recursively find a relevant business entity inside schema.
         *
         * @param mixed $data Schema data.
         * @return array<string,mixed>|null
         */
        private function find_business_entity_in_schema( $data ) {
            $wanted_types = array( 'LocalBusiness', 'ProfessionalService', 'Organization', 'MedicalBusiness' );

            if ( is_array( $data ) ) {
                if ( isset( $data['@type'] ) ) {
                    $types = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );
                    foreach ( $types as $type ) {
                        if ( in_array( $type, $wanted_types, true ) ) {
                            return $data;
                        }
                    }
                }

                if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
                    foreach ( $data['@graph'] as $graph_item ) {
                        $found = $this->find_business_entity_in_schema( $graph_item );
                        if ( ! empty( $found ) ) {
                            return $found;
                        }
                    }
                }

                foreach ( $data as $value ) {
                    if ( is_array( $value ) ) {
                        $found = $this->find_business_entity_in_schema( $value );
                        if ( ! empty( $found ) ) {
                            return $found;
                        }
                    }
                }
            }

            return null;
        }

        /**
         * Normalize areaServed values from schema.
         *
         * @param mixed $raw Raw areaServed.
         * @return array<int,string>
         */
        private function normalize_area_served_from_schema( $raw ) {
            $areas = array();

            if ( is_string( $raw ) ) {
                $areas[] = sanitize_text_field( $raw );
            } elseif ( is_array( $raw ) ) {
                $is_assoc = $this->is_assoc( $raw );
                if ( $is_assoc ) {
                    if ( ! empty( $raw['name'] ) ) {
                        $areas[] = sanitize_text_field( $raw['name'] );
                    }
                } else {
                    foreach ( $raw as $item ) {
                        if ( is_string( $item ) ) {
                            $areas[] = sanitize_text_field( $item );
                        } elseif ( is_array( $item ) && ! empty( $item['name'] ) ) {
                            $areas[] = sanitize_text_field( $item['name'] );
                        }
                    }
                }
            }

            return array_values( array_unique( array_filter( $areas ) ) );
        }

        /**
         * Check if array is associative.
         *
         * @param array<mixed> $array Array.
         * @return bool
         */
        private function is_assoc( $array ) {
            if ( array() === $array ) {
                return false;
            }

            return array_keys( $array ) !== range( 0, count( $array ) - 1 );
        }

        /**
         * Extract meta description from DOM.
         *
         * @param DOMXPath $xpath XPath.
         * @return string
         */
        private function extract_meta_description( $xpath ) {
            $nodes = $xpath->query( '//meta[@name="description"]/@content' );
            if ( $nodes && $nodes->length > 0 ) {
                return sanitize_textarea_field( trim( $nodes->item( 0 )->nodeValue ) );
            }

            $nodes = $xpath->query( '//meta[@property="og:description"]/@content' );
            if ( $nodes && $nodes->length > 0 ) {
                return sanitize_textarea_field( trim( $nodes->item( 0 )->nodeValue ) );
            }

            return '';
        }

        /**
         * Extract page title text.
         *
         * @param DOMXPath $xpath XPath.
         * @return string
         */
        private function extract_title_text( $xpath ) {
            $nodes = $xpath->query( '//meta[@property="og:site_name"]/@content' );
            if ( $nodes && $nodes->length > 0 ) {
                return sanitize_text_field( trim( $nodes->item( 0 )->nodeValue ) );
            }

            $nodes = $xpath->query( '//title' );
            if ( $nodes && $nodes->length > 0 ) {
                $title = wp_strip_all_tags( $nodes->item( 0 )->textContent );
                $title = preg_replace( '/\s*[\-|–|—|\|].*$/u', '', $title );
                return sanitize_text_field( trim( $title ) );
            }

            return '';
        }

        /**
         * Extract likely logo URL.
         *
         * @param DOMXPath $xpath XPath.
         * @return string
         */
        private function extract_logo_url( $xpath ) {
            $meta_nodes = $xpath->query( '//meta[@property="og:image"]/@content' );
            if ( $meta_nodes && $meta_nodes->length > 0 ) {
                return esc_url_raw( trim( $meta_nodes->item( 0 )->nodeValue ) );
            }

            $img_nodes = $xpath->query( '//img[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "logo") or contains(translate(@alt, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "logo")]/@src' );
            if ( $img_nodes && $img_nodes->length > 0 ) {
                return esc_url_raw( trim( $img_nodes->item( 0 )->nodeValue ) );
            }

            return '';
        }

        /**
         * Extract visible text from DOM.
         *
         * @param DOMXPath $xpath XPath.
         * @return string
         */
        private function extract_visible_text( $xpath ) {
            $nodes = $xpath->query( '//body//*[not(self::script) and not(self::style) and not(self::noscript)]/text()' );
            if ( ! $nodes ) {
                return '';
            }

            $parts = array();
            foreach ( $nodes as $node ) {
                $text = trim( preg_replace( '/\s+/u', ' ', $node->nodeValue ) );
                if ( '' !== $text ) {
                    $parts[] = $text;
                }
            }

            $text = implode( ' ', $parts );
            $text = preg_replace( '/\s+/u', ' ', $text );

            return trim( $text );
        }

        /**
         * Extract email candidates.
         *
         * @param string $html HTML.
         * @return array<int,string>
         */
        private function extract_emails( $html ) {
            $emails    = array();
            $site_host = $this->get_site_host();

            if ( preg_match_all( '/mailto:([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $html, $matches ) ) {
                foreach ( $matches[1] as $email ) {
                    $email = sanitize_email( $email );
                    if ( $email ) {
                        $this->store_email_candidate_score( $emails, $email, $this->score_email_candidate( $email, true, $site_host ) );
                    }
                }
            }

            if ( preg_match_all( '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $html, $matches ) ) {
                foreach ( $matches[0] as $email ) {
                    $email = sanitize_email( $email );
                    if ( $email ) {
                        $this->store_email_candidate_score( $emails, $email, $this->score_email_candidate( $email, false, $site_host ) );
                    }
                }
            }

            if ( empty( $emails ) ) {
                return array();
            }

            arsort( $emails, SORT_NUMERIC );

            return array_keys( $emails );
        }

        /**
         * Pick the best public-facing email suggestion from analyzed pages.
         *
         * @param array<int,array<string,string>> $sources Page email sources.
         * @return string
         */
        private function choose_best_public_email( $sources ) {
            $site_host  = $this->get_site_host();
            $best_email = '';
            $best_score = PHP_INT_MIN;

            foreach ( $sources as $source ) {
                $email = isset( $source['email'] ) ? sanitize_email( $source['email'] ) : '';
                if ( '' === $email ) {
                    continue;
                }

                $url   = isset( $source['url'] ) ? (string) $source['url'] : '';
                $score = $this->score_email_candidate( $email, false, $site_host ) + $this->get_email_page_bonus( $url );

                if ( $score > $best_score ) {
                    $best_score = $score;
                    $best_email = $email;
                }
            }

            if ( '' !== $best_email && $best_score >= 0 ) {
                return $best_email;
            }

            return $this->get_public_admin_email_fallback();
        }

        /**
         * Store the best score for an email candidate.
         *
         * @param array<string,int> $emails Candidate score map.
         * @param string            $email  Email candidate.
         * @param int               $score  Candidate score.
         * @return void
         */
        private function store_email_candidate_score( &$emails, $email, $score ) {
            if ( ! isset( $emails[ $email ] ) || $score > $emails[ $email ] ) {
                $emails[ $email ] = (int) $score;
            }
        }

        /**
         * Score an email candidate for public-facing use.
         *
         * @param string $email     Email candidate.
         * @param bool   $is_mailto Whether the email came from a mailto link.
         * @param string $site_host Preferred site host.
         * @return int
         */
        private function score_email_candidate( $email, $is_mailto = false, $site_host = '' ) {
            $email = sanitize_email( $email );
            if ( '' === $email || false === strpos( $email, '@' ) ) {
                return PHP_INT_MIN;
            }

            list( $local_part, $domain ) = array_pad( explode( '@', strtolower( $email ), 2 ), 2, '' );

            if ( '' === $local_part || '' === $domain ) {
                return PHP_INT_MIN;
            }

            $score = 0;
            $label = $this->normalize_email_label( $local_part );

            if ( $is_mailto ) {
                $score += 15;
            }

            if ( $this->is_site_email_domain( $domain, $site_host ) ) {
                $score += 20;
            }

            foreach ( array( 'info', 'contact', 'hello', 'support', 'help', 'team', 'office', 'sales', 'service', 'bookings', 'booking', 'appointments', 'connect', 'enquiries', 'inquiries' ) as $needle ) {
                if ( preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/', $label ) ) {
                    $score += 40;
                    break;
                }
            }

            foreach ( array( 'noreply', 'no reply', 'do not reply', 'donotreply' ) as $needle ) {
                if ( false !== strpos( $label, $needle ) ) {
                    $score -= 100;
                    break;
                }
            }

            foreach ( array( 'admin', 'administrator', 'webmaster', 'wordpress', 'root', 'postmaster', 'system', 'hostmaster' ) as $needle ) {
                if ( preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/', $label ) ) {
                    $score -= 20;
                    break;
                }
            }

            if ( preg_match( '/(^|\.)example\.(com|org|net)$/', $domain ) || 'localhost' === $domain || 'users.noreply.github.com' === $domain ) {
                $score -= 100;
            }

            return $score;
        }

        /**
         * Normalize an email label for keyword matching.
         *
         * @param string $value Value.
         * @return string
         */
        private function normalize_email_label( $value ) {
            $value = strtolower( (string) $value );
            $value = preg_replace( '/[^a-z0-9]+/', ' ', $value );

            return trim( (string) $value );
        }

        /**
         * Get a small page-based bonus for likely contact pages.
         *
         * @param string $url URL.
         * @return int
         */
        private function get_email_page_bonus( $url ) {
            $path = wp_parse_url( $url, PHP_URL_PATH );
            if ( ! is_string( $path ) || '' === $path ) {
                return 0;
            }

            $haystack = strtolower( str_replace( array( '-', '_', '/' ), ' ', $path ) );

            foreach ( array( 'contact', 'support', 'connect', 'get in touch' ) as $needle ) {
                if ( false !== strpos( $haystack, $needle ) ) {
                    return 20;
                }
            }

            foreach ( array( 'about', 'location', 'locations', 'reach' ) as $needle ) {
                if ( false !== strpos( $haystack, $needle ) ) {
                    return 10;
                }
            }

            return 0;
        }

        /**
         * Get a last-resort admin email only when it already looks public-facing.
         *
         * @return string
         */
        private function get_public_admin_email_fallback() {
            $email = sanitize_email( get_option( 'admin_email' ) );
            if ( '' === $email ) {
                return '';
            }

            if ( $this->score_email_candidate( $email, false, $this->get_site_host() ) < 25 ) {
                return '';
            }

            return $email;
        }

        /**
         * Get the normalized site host for email matching.
         *
         * @return string
         */
        private function get_site_host() {
            $host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

            return is_string( $host ) ? strtolower( $host ) : '';
        }

        /**
         * Determine whether an email domain belongs to the site.
         *
         * @param string $domain    Email domain.
         * @param string $site_host Site host.
         * @return bool
         */
        private function is_site_email_domain( $domain, $site_host ) {
            $domain    = strtolower( trim( (string) $domain ) );
            $site_host = strtolower( trim( (string) $site_host ) );

            if ( '' === $domain || '' === $site_host ) {
                return false;
            }

            if ( $domain === $site_host || '.' . $site_host === substr( $domain, -1 - strlen( $site_host ) ) ) {
                return true;
            }

            return '.' . $domain === substr( $site_host, -1 - strlen( $domain ) );
        }

        /**
         * Extract phone numbers.
         *
         * @param string $html HTML.
         * @return array<int,string>
         */
        private function extract_phone_numbers( $html ) {
            $phones = array();

            if ( preg_match_all( "~tel:([^\"'\s<>]+)~i", $html, $matches ) ) {
                foreach ( $matches[1] as $phone ) {
                    $clean = $this->clean_phone( $phone );
                    if ( $clean ) {
                        $phones[] = $clean;
                    }
                }
            }

            if ( preg_match_all( '/(?:\+?\d[\d\s().\-]{8,}\d)/', wp_strip_all_tags( $html ), $matches ) ) {
                foreach ( $matches[0] as $phone ) {
                    $clean = $this->clean_phone( $phone );
                    if ( $clean ) {
                        $phones[] = $clean;
                    }
                }
            }

            return array_values( array_unique( $phones ) );
        }

        /**
         * Normalize phone number string.
         *
         * @param string $value Raw value.
         * @return string
         */
        private function clean_phone( $value ) {
            $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $value = preg_replace( '/[^\d+().\-\s]/', '', $value );
            $digits = preg_replace( '/\D/', '', $value );
            if ( strlen( $digits ) < 10 ) {
                return '';
            }

            return trim( $value );
        }

        /**
         * Extract social/profile URLs.
         *
         * @param DOMXPath $xpath XPath.
         * @return array<int,string>
         */
        private function extract_social_urls( $xpath ) {
            $urls    = array();
            $domains = array(
                'facebook.com',
                'instagram.com',
                'linkedin.com',
                'youtube.com',
                'x.com',
                'twitter.com',
                'tiktok.com',
                'threads.net',
                'pinterest.com',
            );

            $nodes = $xpath->query( '//a[@href]' );
            if ( ! $nodes ) {
                return array();
            }

            foreach ( $nodes as $node ) {
                $href = $node->getAttribute( 'href' );
                foreach ( $domains as $domain ) {
                    if ( false !== strpos( $href, $domain ) ) {
                        $urls[] = esc_url_raw( $href );
                        break;
                    }
                }
            }

            return array_values( array_unique( array_filter( $urls ) ) );
        }

        /**
         * Guess address components from visible text.
         *
         * @param string $text Text.
         * @return array<string,string>
         */
        private function extract_address_guess( $text ) {
            $out = array();

            if ( ! $text ) {
                return $out;
            }

            $pattern = '/(\d{1,6}\s+[A-Za-z0-9.#\-\s]+?(?:Street|St\.?|Road|Rd\.?|Avenue|Ave\.?|Boulevard|Blvd\.?|Drive|Dr\.?|Lane|Ln\.?|Way|Court|Ct\.?|Parkway|Pkwy\.?|Suite|Ste\.?)[,\s]+([A-Za-z.\-\s]+?)[,\s]+([A-Z]{2})\s+(\d{5}(?:-\d{4})?))/i';

            if ( preg_match( $pattern, $text, $matches ) ) {
                $out['street_address']   = sanitize_text_field( trim( $matches[1] ) );
                $out['address_locality'] = sanitize_text_field( trim( $matches[2] ) );
                $out['address_region']   = sanitize_text_field( trim( $matches[3] ) );
                $out['postal_code']      = sanitize_text_field( trim( $matches[4] ) );
                $out['address_country']  = $this->guess_country_from_locale();
            }

            return $out;
        }

        /**
         * Guess area served phrases from text.
         *
         * @param string $text Text.
         * @return string
         */
        private function extract_area_served_guess( $text ) {
            if ( ! $text ) {
                return '';
            }

            $areas = array();

            if ( preg_match_all( '/(?:serving|serve|services? in|proudly serving)\s+([A-Z][A-Za-z\s,&\-]{2,80})/i', $text, $matches ) ) {
                foreach ( $matches[1] as $match ) {
                    $clean = trim( preg_replace( '/\s{2,}/', ' ', $match ) );
                    $clean = preg_replace( '/[.;:].*$/', '', $clean );
                    if ( $clean ) {
                        $areas[] = sanitize_text_field( $clean );
                    }
                }
            }

            return implode( "\n", array_slice( array_values( array_unique( $areas ) ), 0, 8 ) );
        }

        /**
         * Trim text to a sentence or character limit.
         *
         * @param string $text Text.
         * @param int    $length Max length.
         * @return string
         */
        private function first_sentence_or_trim( $text, $length ) {
            $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
            if ( mb_strlen( $text ) <= $length ) {
                return $text;
            }

            if ( preg_match( '/^(.{40,' . (int) $length . '}?[.!?])\s/u', $text, $matches ) ) {
                return trim( $matches[1] );
            }

            return trim( mb_substr( $text, 0, $length - 1 ) ) . '…';
        }

        /**
         * Guess country from locale.
         *
         * @return string
         */
        private function guess_country_from_locale() {
            $locale = get_locale();
            if ( preg_match( '/_([A-Z]{2})$/', $locale, $matches ) ) {
                return $matches[1];
            }

            return '';
        }

        /**
         * Get the site logo URL.
         *
         * @return string
         */
        private function get_site_logo_url() {
            $custom_logo_id = get_theme_mod( 'custom_logo' );
            if ( $custom_logo_id ) {
                $src = wp_get_attachment_image_url( $custom_logo_id, 'full' );
                if ( $src ) {
                    return $src;
                }
            }

            $site_icon_id = get_option( 'site_icon' );
            if ( $site_icon_id ) {
                $src = wp_get_attachment_image_url( $site_icon_id, 'full' );
                if ( $src ) {
                    return $src;
                }
            }

            return '';
        }

        /**
         * Register meta boxes.
         */
        public function register_meta_boxes() {
            $post_types = get_post_types(
                array(
                    'public' => true,
                ),
                'names'
            );

            unset( $post_types['attachment'] );

            foreach ( $post_types as $post_type ) {
                add_meta_box(
                    'sat-lwseo-snippet',
                    __( 'Search Snippet', 'sat-lwseo' ),
                    array( $this, 'render_meta_box' ),
                    $post_type,
                    'normal',
                    'high'
                );
            }
        }

        /**
         * Render SEO snippet meta box.
         *
         * @param WP_Post $post Post object.
         */
        public function render_meta_box( $post ) {
            wp_nonce_field( 'sat_lwseo_meta_box', 'sat_lwseo_meta_box_nonce' );

            $meta_title = get_post_meta( $post->ID, self::META_TITLE_KEY, true );
            $meta_desc  = get_post_meta( $post->ID, self::META_DESC_KEY, true );

            $fallback_title = get_the_title( $post );
            $fallback_desc  = $this->build_default_description_for_post( $post );
            $permalink      = get_permalink( $post );
            ?>
            <div class="sat-lwseo-metabox">
                <p>
                    <label for="sat-lwseo-meta-title"><strong><?php echo esc_html__( 'SEO Title', 'sat-lwseo' ); ?></strong></label><br>
                    <input type="text" id="sat-lwseo-meta-title" name="sat_lwseo_meta_title" class="widefat" value="<?php echo esc_attr( $meta_title ); ?>" maxlength="220">
                </p>
                <p>
                    <label for="sat-lwseo-meta-desc"><strong><?php echo esc_html__( 'Meta Description', 'sat-lwseo' ); ?></strong></label><br>
                    <textarea id="sat-lwseo-meta-desc" name="sat_lwseo_meta_desc" class="widefat" rows="4" maxlength="320"><?php echo esc_textarea( $meta_desc ); ?></textarea>
                </p>
                <p class="description">
                    <?php echo esc_html__( 'Leave blank to use the normal page title and generated excerpt.', 'sat-lwseo' ); ?>
                </p>

                <div class="sat-lwseo-preview" style="border:1px solid #dcdcde;background:#fff;padding:14px;margin-top:14px;max-width:760px;">
                    <div id="sat-lwseo-preview-url" style="font-size:13px;color:#202124;margin-bottom:4px;word-break:break-all;"><?php echo esc_html( $permalink ); ?></div>
                    <div id="sat-lwseo-preview-title" style="font-size:20px;line-height:1.3;color:#1a0dab;margin-bottom:4px;"><?php echo esc_html( $meta_title ? $meta_title : $fallback_title ); ?></div>
                    <div id="sat-lwseo-preview-desc" style="font-size:14px;line-height:1.5;color:#4d5156;"><?php echo esc_html( $meta_desc ? $meta_desc : $fallback_desc ); ?></div>
                </div>

                <p style="margin-top:8px;">
                    <span id="sat-lwseo-title-count"><?php echo esc_html( strlen( $meta_title ) ); ?></span> / 60 title chars ·
                    <span id="sat-lwseo-desc-count"><?php echo esc_html( strlen( $meta_desc ) ); ?></span> / 160 description chars
                </p>
            </div>

            <script>
            jQuery(function($) {
                const titleField = $('#sat-lwseo-meta-title');
                const descField = $('#sat-lwseo-meta-desc');
                const previewTitle = $('#sat-lwseo-preview-title');
                const previewDesc = $('#sat-lwseo-preview-desc');
                const titleCount = $('#sat-lwseo-title-count');
                const descCount = $('#sat-lwseo-desc-count');
                const fallbackTitle = <?php echo wp_json_encode( $fallback_title ); ?>;
                const fallbackDesc = <?php echo wp_json_encode( $fallback_desc ); ?>;

                function updatePreview() {
                    const titleVal = titleField.val().trim();
                    const descVal = descField.val().trim();
                    previewTitle.text(titleVal || fallbackTitle);
                    previewDesc.text(descVal || fallbackDesc);
                    titleCount.text(titleField.val().length);
                    descCount.text(descField.val().length);
                }

                titleField.on('input change', updatePreview);
                descField.on('input change', updatePreview);
                updatePreview();
            });
            </script>
            <?php
        }

        /**
         * Save meta box values.
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post Post object.
         */
        public function save_meta_box( $post_id, $post ) {
            if ( ! isset( $_POST['sat_lwseo_meta_box_nonce'] ) ) {
                return;
            }

            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sat_lwseo_meta_box_nonce'] ) ), 'sat_lwseo_meta_box' ) ) {
                return;
            }

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }

            if ( wp_is_post_revision( $post_id ) ) {
                return;
            }

            if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }

            $meta_title = isset( $_POST['sat_lwseo_meta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['sat_lwseo_meta_title'] ) ) : '';
            $meta_desc  = isset( $_POST['sat_lwseo_meta_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sat_lwseo_meta_desc'] ) ) : '';

            if ( '' !== $meta_title ) {
                update_post_meta( $post_id, self::META_TITLE_KEY, $meta_title );
            } else {
                delete_post_meta( $post_id, self::META_TITLE_KEY );
            }

            if ( '' !== $meta_desc ) {
                update_post_meta( $post_id, self::META_DESC_KEY, $meta_desc );
            } else {
                delete_post_meta( $post_id, self::META_DESC_KEY );
            }
        }

        /**
         * Override document title on singular views.
         *
         * @param string $title Current title.
         * @return string
         */
        public function filter_document_title( $title ) {
            if ( is_admin() || ! is_singular() ) {
                return $title;
            }

            $post_id = get_queried_object_id();
            if ( ! $post_id ) {
                return $title;
            }

            $custom_title = get_post_meta( $post_id, self::META_TITLE_KEY, true );
            if ( $custom_title ) {
                return $custom_title;
            }

            return $title;
        }

        /**
         * Output meta description on singular views.
         */
        public function output_meta_description() {
            if ( is_admin() || ! is_singular() ) {
                return;
            }

            $post_id = get_queried_object_id();
            if ( ! $post_id ) {
                return;
            }

            $custom_desc = get_post_meta( $post_id, self::META_DESC_KEY, true );
            if ( ! $custom_desc ) {
                $post = get_post( $post_id );
                if ( ! $post ) {
                    return;
                }
                $custom_desc = $this->build_default_description_for_post( $post );
            }

            if ( ! $custom_desc ) {
                return;
            }

            echo "\n" . '<meta name="description" content="' . esc_attr( $custom_desc ) . '">' . "\n";
        }

        /**
         * Output homepage JSON-LD.
         */
        public function output_json_ld() {
            if ( is_admin() || ! ( is_front_page() || is_home() ) ) {
                return;
            }

            $settings = $this->get_settings();
            if ( empty( $settings['enabled'] ) || empty( $settings['business_name'] ) ) {
                return;
            }

            $schema = array(
                '@context' => 'https://schema.org',
                '@type'    => $this->sanitize_schema_type( $settings['schema_type'] ),
                'name'     => $settings['business_name'],
                'url'      => $settings['url'] ? $settings['url'] : home_url( '/' ),
            );

            if ( ! empty( $settings['alternate_name'] ) ) {
                $schema['alternateName'] = $settings['alternate_name'];
            }
            if ( ! empty( $settings['description'] ) ) {
                $schema['description'] = $settings['description'];
            }
            if ( ! empty( $settings['logo'] ) ) {
                $schema['logo'] = $settings['logo'];
                $schema['image'] = $settings['logo'];
            }
            if ( ! empty( $settings['telephone'] ) ) {
                $schema['telephone'] = $settings['telephone'];
            }
            if ( ! empty( $settings['email'] ) ) {
                $schema['email'] = $settings['email'];
            }
            if ( ! empty( $settings['price_range'] ) ) {
                $schema['priceRange'] = $settings['price_range'];
            }
            if ( ! empty( $settings['founder'] ) ) {
                $schema['founder'] = array(
                    '@type' => 'Person',
                    'name'  => $settings['founder'],
                );
            }

            $has_address = ! empty( $settings['street_address'] ) || ! empty( $settings['address_locality'] ) || ! empty( $settings['address_region'] ) || ! empty( $settings['postal_code'] ) || ! empty( $settings['address_country'] );
            if ( $has_address ) {
                $schema['address'] = array(
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $settings['street_address'],
                    'addressLocality' => $settings['address_locality'],
                    'addressRegion'   => $settings['address_region'],
                    'postalCode'      => $settings['postal_code'],
                    'addressCountry'  => $settings['address_country'],
                );
            }

            $same_as = $this->explode_lines( $settings['same_as'] );
            if ( ! empty( $same_as ) ) {
                $schema['sameAs'] = $same_as;
            }

            $areas = $this->explode_lines( $settings['area_served'] );
            if ( ! empty( $areas ) ) {
                $schema['areaServed'] = array();
                foreach ( $areas as $area ) {
                    $schema['areaServed'][] = array(
                        '@type' => 'Place',
                        'name'  => $area,
                    );
                }
            }

            echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
        }

        /**
         * Build a fallback description for a post.
         *
         * @param WP_Post $post Post.
         * @return string
         */
        private function build_default_description_for_post( $post ) {
            $desc = '';

            if ( ! empty( $post->post_excerpt ) ) {
                $desc = $post->post_excerpt;
            } elseif ( ! empty( $post->post_content ) ) {
                $desc = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
            }

            $desc = preg_replace( '/\s+/u', ' ', $desc );
            $desc = trim( $desc );

            if ( '' === $desc ) {
                $desc = get_bloginfo( 'description' );
            }

            return $this->first_sentence_or_trim( $desc, 160 );
        }

        /**
         * Sanitize schema type.
         *
         * @param string $type Type.
         * @return string
         */
        private function sanitize_schema_type( $type ) {
            $allowed = array( 'Organization', 'LocalBusiness', 'ProfessionalService', 'MedicalBusiness' );
            if ( in_array( $type, $allowed, true ) ) {
                return $type;
            }

            return 'ProfessionalService';
        }

        /**
         * Sanitize multiline text.
         *
         * @param string $value Value.
         * @return string
         */
        private function sanitize_multiline_text( $value ) {
            $lines = $this->explode_lines( $value );
            $lines = array_map( 'sanitize_text_field', $lines );
            $lines = array_values( array_unique( array_filter( $lines ) ) );

            return implode( "\n", $lines );
        }

        /**
         * Sanitize multiline URLs.
         *
         * @param string $value Value.
         * @return string
         */
        private function sanitize_multiline_urls( $value ) {
            $lines = $this->explode_lines( $value );
            $urls  = array();

            foreach ( $lines as $line ) {
                $url = esc_url_raw( trim( $line ) );
                if ( $url ) {
                    $urls[] = $url;
                }
            }

            return implode( "\n", array_values( array_unique( $urls ) ) );
        }

        /**
         * Split newline text into an array.
         *
         * @param string $value Value.
         * @return array<int,string>
         */
        private function explode_lines( $value ) {
            $value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
            $parts = array_map( 'trim', explode( "\n", $value ) );

            return array_values( array_filter( $parts ) );
        }
    }

    SAT_Lightweight_SEO::instance();
}
