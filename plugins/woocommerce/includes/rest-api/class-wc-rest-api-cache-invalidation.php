<?php
/**
 * REST API Cache Invalidation
 *
 * Handles cache invalidation when products and variations are modified.
 *
 * @package WooCommerce\RestApi
 * @since   9.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_REST_API_Cache_Invalidation class.
 */
class WC_REST_API_Cache_Invalidation {

	/**
	 * Initialize cache invalidation hooks.
	 */
	public static function init() {
		// Product cache invalidation.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'invalidate_product_cache' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'invalidate_product_cache' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( __CLASS__, 'invalidate_product_cache' ), 10, 1 );
		add_action( 'woocommerce_trash_product', array( __CLASS__, 'invalidate_product_cache' ), 10, 1 );
		add_action( 'woocommerce_untrash_product', array( __CLASS__, 'invalidate_product_cache' ), 10, 1 );

		// Variation cache invalidation.
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'invalidate_variation_cache' ), 10, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'invalidate_variation_cache' ), 10, 1 );
		add_action( 'woocommerce_delete_product_variation', array( __CLASS__, 'invalidate_variation_cache' ), 10, 1 );

		// When product meta changes (e.g., stock updates).
		add_action( 'updated_post_meta', array( __CLASS__, 'invalidate_on_meta_update' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'invalidate_on_meta_update' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'invalidate_on_meta_update' ), 10, 4 );
	}

	/**
	 * Invalidate product cache when a product changes.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function invalidate_product_cache( $product_id ) {
		$controller = new WC_REST_Products_Controller();
		$controller->invalidate_entity_cache( $product_id );

		// Also invalidate variation caches if this is a variable product.
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variable' ) ) {
			$variation_ids = $product->get_children();
			foreach ( $variation_ids as $variation_id ) {
				self::invalidate_variation_cache( $variation_id );
			}
		}
	}

	/**
	 * Invalidate variation cache when a variation changes.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public static function invalidate_variation_cache( $variation_id ) {
		$variation_controller = new WC_REST_Product_Variations_Controller();
		$variation_controller->invalidate_entity_cache( $variation_id );

		// Also invalidate parent product cache.
		$variation = wc_get_product( $variation_id );
		if ( $variation && $variation->get_parent_id() ) {
			$product_controller = new WC_REST_Products_Controller();
			$product_controller->invalidate_entity_cache( $variation->get_parent_id() );
		}
	}

	/**
	 * Invalidate cache when product meta is updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Object ID (product/variation ID).
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function invalidate_on_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
		// Only invalidate for product-related meta keys.
		$product_meta_keys = array(
			'_stock',
			'_stock_status',
			'_price',
			'_regular_price',
			'_sale_price',
			'_sku',
			'_global_unique_id',
			'_featured',
			'_visibility',
			'_tax_status',
			'_tax_class',
			'_manage_stock',
			'_backorders',
			'_sold_individually',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_virtual',
			'_downloadable',
			'_product_image_gallery',
			'_thumbnail_id',
		);

		if ( ! in_array( $meta_key, $product_meta_keys, true ) ) {
			return;
		}

		// Check if this is a product or variation.
		$post_type = get_post_type( $object_id );

		if ( 'product' === $post_type ) {
			self::invalidate_product_cache( $object_id );
		} elseif ( 'product_variation' === $post_type ) {
			self::invalidate_variation_cache( $object_id );
		}
	}
}

// Initialize cache invalidation hooks.
WC_REST_API_Cache_Invalidation::init();
