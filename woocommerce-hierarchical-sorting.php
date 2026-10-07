<?php
/**
 * Plugin Name: WooCommerce Hierarchical Sorting
 * Description: Custom product ordering by category → size → manufacturer → price, built for WooCommerce production use.
 * Version: 1.2.0
 * Author: Copilot
 * Text Domain: wc-hierarchical-sorting
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Hierarchical_Sorting' ) ) :

final class WC_Hierarchical_Sorting {

    const ORDER_KEY       = 'custom_hierarchicke_razeni';
    const META_KEY        = '_sort_key';
    const PRICE_KEY       = '_sort_price_num';
    const VERSION_KEY     = '_sort_version';
    const CURRENT_VERSION = '1';
    const SEPARATOR       = "\x1F";

    /**
     * Initialize plugin hooks.
     */
    public static function init() {
        add_filter( 'woocommerce_default_catalog_orderby', array( __CLASS__, 'default_catalog_orderby' ) );
        add_filter( 'woocommerce_catalog_orderby', array( __CLASS__, 'catalog_orderby' ) );
        add_filter( 'woocommerce_get_catalog_ordering_args', array( __CLASS__, 'catalog_ordering_args' ), 10, 3 );

        add_filter( 'posts_join', array( __CLASS__, 'posts_join' ), 10, 2 );
        add_filter( 'posts_orderby', array( __CLASS__, 'posts_orderby' ), 20, 2 );

        add_action( 'save_post_product', array( __CLASS__, 'save_product' ), 20, 3 );

        add_action( 'edited_product_cat', array( __CLASS__, 'schedule_term_rebuild' ), 10, 2 );
        add_action( 'edited_pa_raze', array( __CLASS__, 'schedule_term_rebuild' ), 10, 2 );
        add_action( 'edited_pa_vyrobce', array( __CLASS__, 'schedule_term_rebuild' ), 10, 2 );

        add_action( 'wc_hs_rebuild_term_job', array( __CLASS__, 'rebuild_term_products' ), 10, 2 );
        add_action( 'wc_hs_migration_batch', array( __CLASS__, 'migration_batch_worker' ), 10, 1 );

        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_action( 'admin_post_wc_hs_run_migration', array( __CLASS__, 'handle_manual_migration' ) );
        add_action( 'wp_ajax_wc_hs_run_migration', array( __CLASS__, 'ajax_migration' ) );
        add_action( 'wp_ajax_nopriv_wc_hs_run_migration', array( __CLASS__, 'ajax_migration' ) );
    }

    /**
     * Activate plugin and start initial migration.
     */
    public static function on_activation() {
        self::schedule_migration();
    }

    /**
     * Deactivate plugin and clean cron hooks.
     */
    public static function on_deactivation() {
        wp_clear_scheduled_hook( 'wc_hs_migration_batch' );
        wp_clear_scheduled_hook( 'wc_hs_rebuild_term_job' );
        delete_transient( 'wc_hs_migration_active' );
        delete_transient( 'wc_hs_migration_total' );
    }

    /**
     * Default sort in WooCommerce catalog.
     */
    public static function default_catalog_orderby( $default ) {
        return self::ORDER_KEY;
    }

    /**
     * Expose option in WooCommerce sorting dropdown.
     */
    public static function catalog_orderby( $sortby ) {
        $sortby[ self::ORDER_KEY ] = __( 'Kategorie → ráže → výrobce → cena', 'wc-hierarchical-sorting' );
        return $sortby;
    }

    /**
     * Add custom query flag used by our SQL ordering hooks.
     */
    public static function catalog_ordering_args( $args, $orderby, $order ) {
        if ( $orderby !== self::ORDER_KEY ) {
            return $args;
        }

        $args['orderby'] = 'menu_order';
        $args['order']   = 'ASC';
        $args[ self::ORDER_KEY ] = true;

        return $args;
    }

    /**
     * Add LEFT JOIN only when custom order is active on a product query.
     */
    public static function posts_join( $join, $query ) {
        if ( ! $query instanceof WP_Query ) {
            return $join;
        }

        if ( is_admin() || ! $query->is_main_query() ) {
            return $join;
        }

        if ( ! $query->get( self::ORDER_KEY ) ) {
            return $join;
        }

        if ( ! self::is_product_query( $query ) ) {
            return $join;
        }

        global $wpdb;
        $alias = 'wc_hs_sort';

        if ( false === strpos( $join, $alias ) ) {
            $join .= " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = '" . esc_sql( self::META_KEY ) . "') ";
        }

        return $join;
    }

    /**
     * Order products by the precomputed sort key.
     */
    public static function posts_orderby( $orderby, $query ) {
        if ( ! $query instanceof WP_Query ) {
            return $orderby;
        }

        if ( is_admin() || ! $query->is_main_query() ) {
            return $orderby;
        }

        if ( ! $query->get( self::ORDER_KEY ) ) {
            return $orderby;
        }

        if ( ! self::is_product_query( $query ) ) {
            return $orderby;
        }

        global $wpdb;
        $alias = 'wc_hs_sort';

        return "
            CASE
                WHEN {$alias}.meta_value IS NULL OR {$alias}.meta_value = '' THEN 1
                ELSE 0
            END ASC,
            CAST({$alias}.meta_value AS CHAR) ASC,
            {$wpdb->posts}.ID ASC
        ";
    }

    /**
     * Determine if the current query is a WooCommerce product query.
     */
    private static function is_product_query( $query ) {
        $post_type = $query->get( 'post_type' );

        if ( $post_type ) {
            if ( is_array( $post_type ) ) {
                return in_array( 'product', $post_type, true );
            }
            return 'product' === $post_type;
        }

        if ( function_exists( 'is_shop' ) && is_shop() ) {
            return true;
        }

        if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
            return true;
        }

        if ( function_exists( 'is_product_category' ) && is_product_category() ) {
            return true;
        }

        if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
            return true;
        }

        return false;
    }

    /**
     * Rebuild sort key when product is saved.
     */
    public static function save_product( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }

        self::build_sort_key( $post_id );
        self::invalidate_product_cache( $post_id );
    }

    /**
     * Build a single product sort key.
     */
    public static function build_sort_key( $product_id ) {
        $product_id = absint( $product_id );

        if ( $product_id < 1 ) {
            return '';
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        $category_name = self::get_first_term_name( $product_id, 'product_cat' );
        $raze_name     = self::get_first_term_name( $product_id, 'pa_raze' );
        $vyrobce_name  = self::get_first_term_name( $product_id, 'pa_vyrobce' );

        $cat_key   = self::normalize_sort_part( $category_name );
        $raze_key  = self::normalize_sort_part( $raze_name );
        $vyrob_key = self::normalize_sort_part( $vyrobce_name );

        $price_cents = self::get_product_price_cents( $product );
        $price_key   = $price_cents !== null
            ? str_pad( (string) $price_cents, 12, '0', STR_PAD_LEFT )
            : str_repeat( '9', 12 );

        $sort_key = $cat_key . self::SEPARATOR . $raze_key . self::SEPARATOR . $vyrob_key . self::SEPARATOR . $price_key;

        $old_sort_key = get_post_meta( $product_id, self::META_KEY, true );
        if ( $old_sort_key !== $sort_key ) {
            update_post_meta( $product_id, self::META_KEY, $sort_key );
        }

        $old_version = get_post_meta( $product_id, self::VERSION_KEY, true );
        if ( (string) $old_version !== (string) self::CURRENT_VERSION ) {
            update_post_meta( $product_id, self::VERSION_KEY, self::CURRENT_VERSION );
        }

        $price_num = $price_cents !== null ? number_format( $price_cents / 100, 2, '.', '' ) : '';
        $old_price = get_post_meta( $product_id, self::PRICE_KEY, true );

        if ( $old_price !== $price_num ) {
            if ( '' === $price_num ) {
                delete_post_meta( $product_id, self::PRICE_KEY );
            } else {
                update_post_meta( $product_id, self::PRICE_KEY, $price_num );
            }
        }

        return $sort_key;
    }

    /**
     * Invalidate WooCommerce product caches after sort key change.
     */
    private static function invalidate_product_cache( $product_id ) {
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $product_id );
        }

        delete_post_meta( $product_id, '_woocommerce_product_query_cache' );
        clean_post_cache( $product_id );
    }

    /**
     * Get price in cents for stable sorting.
     */
    private static function get_product_price_cents( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return null;
        }

        $price_raw = null;

        if ( $product->is_type( 'variable' ) ) {
            $variation_price = $product->get_variation_price( 'min', false );
            $price_raw = false !== $variation_price ? $variation_price : $product->get_price();
        } else {
            $price_raw = $product->get_price();
        }

        if ( null === $price_raw || '' === $price_raw || false === $price_raw ) {
            return null;
        }

        $price_num = (float) $price_raw;
        return (int) round( $price_num * 100 );
    }

    /**
     * Normalize each key segment.
     */
    private static function normalize_sort_part( $value ) {
        if ( is_null( $value ) ) {
            return '';
        }

        $value = trim( (string) $value );

        return mb_strtolower( $value, 'UTF-8' );
    }

    /**
     * Get the first term name using Collator when available.
     * This preserves proper Czech sorting for terms like 9 mm Luger, CZ, etc.
     */
    private static function get_first_term_name( $product_id, $taxonomy ) {
        if ( empty( $taxonomy ) ) {
            return '';
        }

        $terms = get_the_terms( $product_id, $taxonomy );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }

        $terms = array_values( $terms );

        if ( class_exists( 'Collator' ) ) {
            $names = wp_list_pluck( $terms, 'name' );
            $locale = get_locale();

            if ( ! $locale || 'en_US' === $locale ) {
                $locale = 'cs_CZ';
            }

            $coll = new Collator( $locale );
            $coll->asort( $names );

            $first_name = reset( $names );
            return (string) $first_name;
        }

        usort(
            $terms,
            function( $a, $b ) {
                return strcasecmp( (string) $a->name, (string) $b->name );
            }
        );

        $first = $terms[0] ?? null;
        return $first ? (string) $first->name : '';
    }

    /**
     * Schedule rebuild after taxonomy edit.
     */
    public static function schedule_term_rebuild( $term_id, $tt_id = 0, $taxonomy = '' ) {
        $term_id = absint( $term_id );
        if ( $term_id < 1 ) {
            return;
        }

        if ( '' === $taxonomy ) {
            $taxonomy = str_replace( 'edited_', '', current_filter() );
        }

        $queue = 'wc_hs_rebuild_term_job';
        $args  = array( $term_id, $taxonomy );

        if ( ! wp_next_scheduled( $queue, $args ) ) {
            wp_schedule_single_event( time() + 10, $queue, $args );
        }
    }

    /**
     * Rebuild all products in a taxonomy term.
     */
    public static function rebuild_term_products( $term_id, $taxonomy = 'product_cat' ) {
        $term_id = absint( $term_id );
        if ( $term_id < 1 ) {
            return 0;
        }

        $taxonomy = sanitize_key( $taxonomy );
        if ( '' === $taxonomy ) {
            $taxonomy = 'product_cat';
        }

        $page = 1;
        $total = 0;

        do {
            $args = array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => 200,
                'paged'          => $page,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => array(
                    array(
                        'taxonomy' => $taxonomy,
                        'field'    => 'term_id',
                        'terms'    => $term_id,
                    ),
                ),
            );

            $query = new WP_Query( $args );
            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $product_id ) {
                self::build_sort_key( $product_id );
                self::invalidate_product_cache( $product_id );
                $total++;
            }

            $page++;
        } while ( count( $query->posts ) === 200 );

        return $total;
    }

    /**
     * Start bulk migration in chunks.
     */
    public static function schedule_migration() {
        delete_transient( 'wc_hs_migration_active' );
        delete_transient( 'wc_hs_migration_total' );

        set_transient( 'wc_hs_migration_active', 1, DAY_IN_SECONDS );
        set_transient( 'wc_hs_migration_total', 0, DAY_IN_SECONDS );

        wp_clear_scheduled_hook( 'wc_hs_migration_batch' );
        wp_schedule_single_event( time() + 5, 'wc_hs_migration_batch', array( 1 ) );
    }

    /**
     * Process one page of migration.
     */
    public static function migration_batch_worker( $page = 1 ) {
        $page = max( 1, absint( $page ) );
        $per_page = 200;

        $args = array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );

        $query = new WP_Query( $args );

        if ( empty( $query->posts ) ) {
            delete_transient( 'wc_hs_migration_active' );
            return;
        }

        foreach ( $query->posts as $product_id ) {
            self::build_sort_key( $product_id );
            self::invalidate_product_cache( $product_id );
        }

        $total = (int) get_transient( 'wc_hs_migration_total' );
        $total += count( $query->posts );
        set_transient( 'wc_hs_migration_total', $total, DAY_IN_SECONDS );

        if ( count( $query->posts ) === $per_page ) {
            wp_schedule_single_event( time() + 5, 'wc_hs_migration_batch', array( $page + 1 ) );
        } else {
            delete_transient( 'wc_hs_migration_active' );
        }
    }

    /**
     * Return migration status for admin/AJAX.
     */
    public static function get_migration_status() {
        $active = (bool) get_transient( 'wc_hs_migration_active' );
        $total = (int) get_transient( 'wc_hs_migration_total' );

        return array(
            'active' => $active,
            'total'  => $total,
        );
    }

    /**
     * Register WooCommerce admin page.
     */
    public static function register_admin_page() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            __( 'Hierarchical sorting', 'wc-hierarchical-sorting' ),
            __( 'Hierarchical sorting', 'wc-hierarchical-sorting' ),
            'manage_woocommerce',
            'wc-hierarchical-sorting',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    /**
     * Render admin page.
     */
    public static function render_admin_page() {
        $status = self::get_migration_status();
        $message = '';

        if ( isset( $_GET['wc_hs_status'] ) ) {
            $status_param = sanitize_key( wp_unslash( $_GET['wc_hs_status'] ) );
            if ( 'started' === $status_param ) {
                $message = '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Migration started in the background.', 'wc-hierarchical-sorting' ) . '</p></div>';
            }
        }

        echo '<div class="wrap"><h1>' . esc_html__( 'Hierarchical sorting', 'wc-hierarchical-sorting' ) . '</h1>';
        echo $message;
        echo '<p>' . esc_html__( 'This rebuilds the precomputed _sort_key meta used for category → size → manufacturer → price order.', 'wc-hierarchical-sorting' ) . '</p>';

        if ( $status['active'] ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Migration in progress...', 'wc-hierarchical-sorting' ) . ' (' . absint( $status['total'] ) . ' ' . esc_html__( 'processed', 'wc-hierarchical-sorting' ) . ')</p></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'wc_hs_run_migration', 'wc_hs_nonce' );
        echo '<input type="hidden" name="action" value="wc_hs_run_migration" />';
        $button_text = $status['active'] ? __( 'Migration in progress...', 'wc-hierarchical-sorting' ) : __( 'Start migration', 'wc-hierarchical-sorting' );
        echo '<button class="button button-primary"' . ( $status['active'] ? ' disabled' : '' ) . ' type="submit">' . esc_html( $button_text ) . '</button>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Handle manual migration from WooCommerce admin.
     */
    public static function handle_manual_migration() {
        if ( ! isset( $_POST['wc_hs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_hs_nonce'] ) ), 'wc_hs_run_migration' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'wc-hierarchical-sorting' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Access denied.', 'wc-hierarchical-sorting' ) );
        }

        self::schedule_migration();

        wp_safe_redirect( admin_url( 'admin.php?page=wc-hierarchical-sorting&wc_hs_status=started' ) );
        exit;
    }

    /**
     * AJAX endpoint for migration triggers/status.
     */
    public static function ajax_migration() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Access denied.', 'wc-hierarchical-sorting' ) ) );
        }

        $action = isset( $_POST['wc_hs_action'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_hs_action'] ) ) : '';

        if ( 'start' === $action ) {
            self::schedule_migration();
            wp_send_json_success( array( 'message' => __( 'Migration started.', 'wc-hierarchical-sorting' ) ) );
        }

        if ( 'status' === $action ) {
            wp_send_json_success( self::get_migration_status() );
        }

        wp_send_json_error( array( 'message' => __( 'Invalid action.', 'wc-hierarchical-sorting' ) ) );
    }
}

// Activation / deactivation hooks
register_activation_hook( __FILE__, array( 'WC_Hierarchical_Sorting', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'WC_Hierarchical_Sorting', 'on_deactivation' ) );

add_action( 'plugins_loaded', array( 'WC_Hierarchical_Sorting', 'init' ) );

if ( ! function_exists( 'wc_hs_build_product_sort_key' ) ) {
    function wc_hs_build_product_sort_key( $product_id ) {
        return WC_Hierarchical_Sorting::build_sort_key( $product_id );
    }
}

if ( ! function_exists( 'wc_hs_schedule_migration' ) ) {
    function wc_hs_schedule_migration() {
        return WC_Hierarchical_Sorting::schedule_migration();
    }
}

if ( ! function_exists( 'wc_hs_get_migration_status' ) ) {
    function wc_hs_get_migration_status() {
        return WC_Hierarchical_Sorting::get_migration_status();
    }
}

endif;
