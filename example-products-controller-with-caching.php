<?php
/**
 * Example: How to implement REST API caching in WC_REST_Products_Controller
 * 
 * This demonstrates how to extend WC_REST_Controller with entity-specific caching logic.
 */

/**
 * Add this to WC_REST_Products_Controller class
 */
class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {

	/**
	 * Enable caching for this controller.
	 *
	 * @var bool
	 */
	protected $cache_enabled = true;

	/**
	 * Get cache key information for the request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|null Cache key info or null to skip caching.
	 */
	protected function get_cache_key_info( $request ) {
		$route = $request->get_route();

		// Single product: /wc/v3/products/{id}
		if ( preg_match( '#^/wc/v3/products/(\d+)$#', $route, $matches ) ) {
			return array(
				'type' => 'single',
				'key'  => 'wc_rest_product_' . $matches[1],
				'id'   => (int) $matches[1],
			);
		}

		// Collection endpoints: /wc/v3/products, /wc/v3/products/suggested-products
		if ( strpos( $route, '/wc/v3/products' ) !== false ) {
			$query_hash = md5( wp_json_encode( $request->get_query_params() ) );
			return array(
				'type' => 'collection',
				'key'  => 'wc_rest_collection_' . md5( $route . $query_hash ),
			);
		}

		return null;
	}

	/**
	 * Get filter names to include in cache hash.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array Filter names.
	 */
	protected function get_cache_hash_filters( $request ) {
		return array(
			'woocommerce_rest_prepare_product_object',
			'rest_prepare_product',
			'woocommerce_rest_product_object_query',
		);
	}

	/**
	 * Extract product IDs from response data.
	 *
	 * @param array $data Response data.
	 * @return array Product IDs.
	 */
	protected function extract_entity_ids( $data ) {
		$product_ids = array();

		// Single product response
		if ( isset( $data['id'] ) && ! isset( $data[0] ) ) {
			$product_ids[] = $data['id'];
		}

		// Collection response
		if ( is_array( $data ) && isset( $data[0] ) ) {
			foreach ( $data as $item ) {
				if ( isset( $item['id'] ) ) {
					$product_ids[] = $item['id'];
				}
			}
		}

		return array_unique( array_filter( $product_ids ) );
	}

	/**
	 * Remove non-deterministic fields from data.
	 *
	 * @param array $data Response data.
	 * @return array Cleaned data.
	 */
	protected function remove_non_deterministic_fields( $data ) {
		// Handle single product
		if ( isset( $data['related_ids'] ) ) {
			$clean_data = $data;
			unset( $clean_data['related_ids'] );
			return $clean_data;
		}

		// Handle product collection
		if ( is_array( $data ) ) {
			$clean_data = array();
			foreach ( $data as $key => $product ) {
				if ( is_array( $product ) && isset( $product['related_ids'] ) ) {
					$clean_product = $product;
					unset( $clean_product['related_ids'] );
					$clean_data[ $key ] = $clean_product;
				} else {
					$clean_data[ $key ] = $product;
				}
			}
			return $clean_data;
		}

		return $data;
	}

	/**
	 * Get cache key for a single product.
	 *
	 * @param int $entity_id Product ID.
	 * @return string Cache key.
	 */
	protected function get_single_entity_cache_key( $entity_id ) {
		return 'wc_rest_product_' . $entity_id;
	}
}

/**
 * Hook into product changes to invalidate caches
 */
add_action( 'woocommerce_update_product', 'wc_invalidate_product_rest_cache', 10, 1 );
add_action( 'woocommerce_new_product', 'wc_invalidate_product_rest_cache', 10, 1 );
add_action( 'woocommerce_delete_product', 'wc_invalidate_product_rest_cache', 10, 1 );

function wc_invalidate_product_rest_cache( $product_id ) {
	// Get the products controller instance
	// In practice, you might store this as a global or singleton
	$controller = new WC_REST_Products_Controller();
	
	// Invalidate cache for this product
	$controller->invalidate_entity_cache( $product_id );
	
	// For variations, also invalidate parent
	$product = wc_get_product( $product_id );
	if ( $product && $product->is_type( 'variation' ) ) {
		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$controller->invalidate_entity_cache( $parent_id );
		}
	}
}
