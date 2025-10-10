<?php
/**
 * Example: How to implement REST API caching in WC_REST_Product_Variations_Controller
 * 
 * This demonstrates how the same caching system works for variations.
 */

/**
 * Example implementation - DO NOT USE DIRECTLY
 * This is a reference implementation showing how to add caching to the variations controller.
 * The actual implementation is in class-wc-rest-product-variations-controller.php
 */
class Example_WC_REST_Product_Variations_Controller extends WC_REST_Product_Variations_V2_Controller {

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

		// Single variation: /wc/v3/products/{product_id}/variations/{id}
		if ( preg_match( '#^/wc/v3/products/\d+/variations/(\d+)$#', $route, $matches ) ) {
			return array(
				'key'       => 'wc_rest_variation_' . $matches[1],
				'entity_id' => (int) $matches[1],
			);
		}

		// Variations collection: /wc/v3/products/{product_id}/variations
		if ( preg_match( '#^/wc/v3/products/(\d+)/variations$#', $route, $matches ) ) {
			$query_hash = md5( wp_json_encode( $request->get_query_params() ) );
			return array(
				'key' => 'wc_rest_variations_collection_' . $matches[1] . '_' . $query_hash,
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
			'woocommerce_rest_prepare_product_variation_object',
			'rest_prepare_product_variation',
		);
	}

	/**
	 * Extract variation IDs from response data.
	 * 
	 * For variations, we need to track both the variation ID and parent product ID
	 * for proper cache invalidation.
	 *
	 * @param array $data Response data.
	 * @return array Variation and parent product IDs.
	 */
	protected function extract_entity_ids( $data ) {
		$ids = array();

		if ( isset( $data[0] ) ) {
			// Collection response
			foreach ( $data as $item ) {
				if ( isset( $item['id'] ) ) {
					$ids[] = $item['id'];
					
					// Also track parent product ID for cache invalidation
					if ( isset( $item['parent_id'] ) && $item['parent_id'] > 0 ) {
						$ids[] = $item['parent_id'];
					}
				}
			}
		} else {
			// Single variation response
			if ( isset( $data['id'] ) ) {
				$ids[] = $data['id'];
				
				// Also track parent product ID
				if ( isset( $data['parent_id'] ) && $data['parent_id'] > 0 ) {
					$ids[] = $data['parent_id'];
				}
			}
		}

		return array_unique( array_filter( $ids ) );
	}

	/**
	 * Remove non-deterministic fields from data.
	 *
	 * Variations don't have related_ids, but this method is still required.
	 *
	 * @param array $data Response data.
	 * @return array Cleaned data.
	 */
	protected function remove_non_deterministic_fields( $data ) {
		// Variations don't have random fields by default
		return $data;
	}

	/**
	 * Get cache key for a single variation.
	 *
	 * @param int $entity_id Variation ID.
	 * @return string Cache key.
	 */
	protected function get_single_entity_cache_key( $entity_id ) {
		return 'wc_rest_variation_' . $entity_id;
	}
}

/**
 * Hook into variation changes to invalidate caches
 */
add_action( 'woocommerce_update_product_variation', 'wc_invalidate_variation_rest_cache', 10, 1 );
add_action( 'woocommerce_new_product_variation', 'wc_invalidate_variation_rest_cache', 10, 1 );
add_action( 'woocommerce_delete_product_variation', 'wc_invalidate_variation_rest_cache', 10, 1 );

function wc_invalidate_variation_rest_cache( $variation_id ) {
	$controller = new WC_REST_Product_Variations_Controller();
	
	// Invalidate cache for this variation
	$controller->invalidate_entity_cache( $variation_id );
	
	// Also invalidate parent product
	$variation = wc_get_product( $variation_id );
	if ( $variation && $variation->get_parent_id() ) {
		$product_controller = new WC_REST_Products_Controller();
		$product_controller->invalidate_entity_cache( $variation->get_parent_id() );
	}
}
