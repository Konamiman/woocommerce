<?php
/**
 * REST API Object Cache
 *
 * Provides caching for REST API responses.
 */

namespace Automattic\WooCommerce\Internal\Caches;

use Automattic\WooCommerce\Caching\ObjectCache;

/**
 * Cache for REST API responses.
 *
 * @since 9.5.0
 */
class RestApiObjectCache extends ObjectCache {

	/**
	 * Get the object type identifier for this cache.
	 *
	 * @return string
	 */
	public function get_object_type(): string {
		return 'rest-api-cache';
	}

	/**
	 * Get the id of an object. Returns null as we always provide explicit IDs.
	 *
	 * @param array|object $object The object to get the id for.
	 * @return int|string|null
	 */
	protected function get_object_id( $object ) {
		return null;
	}

	/**
	 * Validate an object before it's cached.
	 *
	 * Ensures the cache entry contains all required keys for REST API responses.
	 *
	 * @param array|object $object Object to validate.
	 * @return array|null An array with validation error messages, null or an empty array if there are no errors.
	 */
	protected function validate( $object ): ?array {
		// Cache entries must be arrays.
		if ( ! is_array( $object ) ) {
			return array( 'Cache entry must be an array.' );
		}

		// Required keys for REST API cache entries.
		$required_keys = array(
			'entity_type',
			'created_at',
			'hooks_hash',
			'data',
			'entity_versions',
			'etag',
		);

		$errors = array();
		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $object ) ) {
				$errors[] = "Missing required key: {$key}";
			}
		}

		return empty( $errors ) ? null : $errors;
	}
}
