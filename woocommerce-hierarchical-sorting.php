<?php
/**
 * Plugin Name: WooCommerce Hierarchical Sorting
 * Description: Custom product ordering by category → size → manufacturer → price, optimized for WooCommerce + AJAX + cache compatibility.
 * Version: 1.0.0
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

    const ORDER_KEY = 'custom_hierarchicke_razeni';
    const META_KEY  = '_sort_key';
    const PRICE_KEY = '_sort_price_num';

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
        add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'save_product_object' ), 20, 1 );

        add_action( 'edited_product_cat', array( __CLASS__, 'schedule_term_rebuild' ), 10, 1 );
        add_action( 'edited_pa_raze', array( __CLASS__, 'schedule_term_rebuild' ), 10, 1 );
        add_action( 'edited_pa_vyrobce', array( __CLASS__, 'schedule_term_rebuild' ), 10, 1 );

        add_action( 'wc_hs_rebuild_term_job', array( __CLASS__, 'rebuild_term_products' ), 10, 1 );

        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_action( 'admin_post_wc_hs_run_migration', array( __CLASS__, 'handle_manual_migration' ) );
        add_action( 'wp_ajax_wc_hs_run_migration', array( __CLASS__, 'ajax_migration' ) );
        add_action( 'wp_ajax_nopriv_wc_hs_run_migration', array( __CLASS__, 'ajax_migration' ) );
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
    }

    /**
     * Rebuild product sort key before save (extra safety).
     */
    public static function save_product_object( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        self::build_sort_key( $product->get_id() );
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

        $sort_key = $cat_key . '|' . $raze_key . '|' . $vyrob_key . '|' . $price_key;

        $old_sort_key = get_post_meta( $product_id, self::META_KEY, true );
        if ( $old_sort_key !== $sort_key ) {
            update_post_meta( $product_id, self::META_KEY, $sort_key );
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

        $price_num = number_format( (float) $price_raw, 2, '.', '' );
        return (int) round( (float) $price_num * 100 );
    }

    /**
     * Normalize each key segment.
     */
    private static function normalize_sort_part( $value ) {
        if ( is_null( $value ) ) {
            return '';
        }

        return mb_strtolower( trim( (string) $value ), 'UTF-8' );
    }

    /**
     * Get first term name based on term_order, then alphabetical.
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

        $has_term_order = false;
        foreach ( $terms as $term ) {
            if ( isset( $term->term_order ) ) {
                $has_term_order = true;
                break;
            }
        }

        if ( $has_term_order ) {
            usort(
                $terms,
                function( $a, $b ) {
                    $a_order = isset( $a->term_order ) ? (int) $a->term_order : 999;
                    $b_order = isset( $b->term_order ) ? (int) $b->term_order : 999;
                    return $a_order - $b_order;
                }
            );

            $first = $terms[0] ?? null;
            return $first ? (string) $first->name : '';
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
     * Schedule rebuild after a taxonomy edit.
     */
    public static function schedule_term_rebuild( $term_id ) {
        $term_id = absint( $term_id );
        if ( $term_id < 1 ) {
            return;
        }

        wp_schedule_single_event( time() + 5, 'wc_hs_rebuild_term_job', array( $term_id ) );
    }

    /**
     * Rebuild all products in a term.
     */
    public static function rebuild_term_products( $term_id ) {
        $term_id = absint( $term_id );
        if ( $term_id < 1 ) {
            return 0;
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
                        'taxonomy' => 'product_cat',
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
                $total++;
            }

            $page++;
        } while ( count( $query->posts ) === 200 );

        return $total;
    }

    /**
     * Batch migration for all products.
     */
    public static function migrate_products( $per_page = 200 ) {
        $page = 1;
        $total = 0;

        do {
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
                break;
            }

            foreach ( $query->posts as $product_id ) {
                self::build_sort_key( $product_id );
                $total++;
            }

            $page++;
        } while ( count( $query->posts ) === $per_page );

        return $total;
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
     * Render admin page with manual migration trigger.
     */
    public static function render_admin_page() {
        $message = '';

        if ( isset( $_GET['wc_hs_status'] ) ) {
            $status = sanitize_key( wp_unslash( $_GET['wc_hs_status'] ) );

            if ( 'ok' === $status ) {
                $message = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sort keys were rebuilt successfully.', 'wc-hierarchical-sorting' ) . '</p></div>';
            }
        }

        echo '<div class="wrap"><h1>' . esc_html__( 'Hierarchical sorting', 'wc-hierarchical-sorting' ) . '</h1>';
        echo $message;
        echo '<p>' . esc_html__( 'This rebuilds the precomputed _sort_key meta used for category → size → manufacturer → price ordering.', 'wc-hierarchical-sorting' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'wc_hs_run_migration', 'wc_hs_nonce' );
        echo '<input type="hidden" name="action" value="wc_hs_run_migration" />';
        echo '<button class="button button-primary" type="submit">' . esc_html__( 'Run rebuild', 'wc-hierarchical-sorting' ) . '</button>';
        echo '</form></div>';
    }

    /**
     * Handle manual migration from admin form.
     */
    public static function handle_manual_migration() {
        if ( ! isset( $_POST['wc_hs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_hs_nonce'] ) ), 'wc_hs_run_migration' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'wc-hierarchical-sorting' ) );
        }

        $count = self::migrate_products( 200 );

        wp_safe_redirect( admin_url( 'admin.php?page=wc-hierarchical-sorting&wc_hs_status=ok' ) );
        exit;
    }

    /**
     * AJAX fallback for migration.
     */
    public static function ajax_migration() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Access denied.', 'wc-hierarchical-sorting' ) ) );
        }

        $count = self::migrate_products( 200 );

        wp_send_json_success(
            array(
                'message' => sprintf( __( 'Rebuilt %d products.', 'wc-hierarchical-sorting' ), $count ),
                'count'   => $count,
            )
        );
    }
}

add_action( 'plugins_loaded', array( 'WC_Hierarchical_Sorting', 'init' ) );

if ( ! function_exists( 'wc_hs_build_product_sort_key' ) ) {
    function wc_hs_build_product_sort_key( $product_id ) {
        return WC_Hierarchical_Sorting::build_sort_key( $product_id );
    }
}

if ( ! function_exists( 'wc_hs_migrate_products' ) ) {
    function wc_hs_migrate_products( $per_page = 200 ) {
        return WC_Hierarchical_Sorting::migrate_products( $per_page );
    }
}

endif;
