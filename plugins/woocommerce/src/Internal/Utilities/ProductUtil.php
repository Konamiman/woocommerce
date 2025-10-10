<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Utilities;

/**
 * Class with general utility methods related to products.
 */
class ProductUtil {
	/**
	 * Get the last modified version for a product.
	 *
	 * Returns a timestamp that changes whenever the product is modified.
	 * This is used for cache invalidation in the REST API.
	 *
	 * @param int $product_id Product ID.
	 * @return int|null Timestamp of last modification, or null if product doesn't exist.
	 */
	public function get_last_modified_version( $product_id ) {
		global $wpdb;

		// Check if we're using the CPT data store (the default).
		$data_store = \WC_Data_Store::load( 'product' );
		if ( is_a( $data_store, \WC_Product_Data_Store_CPT::class ) ) {
			// Query the posts table directly for performance.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_modified = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d",
					$product_id
				)
			);

			if ( ! $post_modified ) {
				return null;
			}

			return strtotime( $post_modified );
		}

		// Fallback: Use wc_get_product for custom data stores.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		$date_modified = $product->get_date_modified();
		if ( ! $date_modified ) {
			return null;
		}

		return $date_modified->getTimestamp();
	}

	/**
	 * Delete the transients related to a specific product.
	 * If the product is a variation, delete the transients for the parent too.
	 *
	 * @param WC_Product|int $product_or_id The product or the product id.
	 * @return void
	 */
	public function delete_product_specific_transients( $product_or_id ) {
		$parent_id = 0;
		if ( $product_or_id instanceof \WC_Product ) {
			$product    = $product_or_id;
			$product_id = $product->get_id();
		} else {
			$product_id = $product_or_id;
			$product    = wc_get_product( $product_id );
		}

		if ( $product instanceof \WC_Product_Variation ) {
			$parent_id = $product->get_parent_id();
		}

		$product_specific_transient_names = array(
			'wc_product_children_',
			'wc_var_prices_',
			'wc_related_',
			'wc_child_has_weight_',
			'wc_child_has_dimensions_',
		);

		foreach ( $product_specific_transient_names as $transient ) {
			delete_transient( $transient . $product_id );
			if ( $parent_id ) {
				delete_transient( $transient . $parent_id );
			}
		}
	}
}
