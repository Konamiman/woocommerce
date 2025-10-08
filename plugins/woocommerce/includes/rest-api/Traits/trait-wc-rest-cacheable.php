<?php
/**
 * REST API Caching Trait
 *
 * Provides caching functionality for REST API controllers.
 * Can be used by both WC_REST_Controller and RestApiControllerBase.
 *
 * @package WooCommerce\RestApi
 * @since   9.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trait for adding caching capabilities to REST API controllers.
 *
 * Usage:
 * 1. Add 'use WC_REST_Cacheable;' to your controller class
 * 2. Set $cache_enabled = true
 * 3. Implement required methods (or use defaults)
 * 4. Call register_cache_hooks() in constructor
 *
 * @package WooCommerce\RestApi
 */
trait WC_REST_Cacheable {

	/**
	 * Whether REST API caching is enabled for this controller.
	 *
	 * @var bool
	 */
	protected $cache_enabled = false;

	/**
	 * Register cache-related hooks.
	 *
	 * Call this from the controller's constructor or init method.
	 */
	protected function register_cache_hooks() {
		if ( $this->is_cache_enabled() ) {
			add_filter( 'rest_pre_dispatch', array( $this, 'maybe_return_cached_response' ), 10, 3 );
			add_filter( 'rest_post_dispatch', array( $this, 'maybe_cache_response' ), 10, 3 );
		}
	}

	/**
	 * Check if caching is enabled for this controller.
	 *
	 * @return bool
	 */
	protected function is_cache_enabled() {
		return $this->cache_enabled;
	}

	/**
	 * Get cache key information for the request.
	 *
	 * Override this method in classes to enable caching.
	 * Return null to skip caching for this request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|null Array with 'type' and 'key' or null to skip caching.
	 */
	protected function get_cache_key_info( $request ) {
		return null; // Default: no caching.
	}

	/**
	 * Get filter names to include in cache hash.
	 *
	 * Override in classes to specify which hooks affect the response.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array Array of filter names.
	 */
	protected function get_cache_hash_filters( $request ) {
		return array();
	}

	/**
	 * Check if response data is a collection.
	 *
	 * Override in classes if you need custom collection detection logic.
	 *
	 * @param array $data Response data.
	 * @return bool True if data represents a collection, false for single item.
	 */
	protected function is_collection( $data ) {
		// Collections are indexed arrays with numeric keys starting at 0.
		// Single items are associative arrays with string keys.
		return isset( $data[0] );
	}

	/**
	 * Extract entity ID from a single entity array.
	 *
	 * Override in classes if your entities use a different ID field.
	 *
	 * @param array $entity Single entity data.
	 * @return int|null Entity ID or null if not found.
	 */
	protected function extract_entity_id( $entity ) {
		return $entity['id'] ?? null;
	}

	/**
	 * Extract entity IDs from response data.
	 *
	 * Most classes won't need to override this - override extract_entity_id() instead.
	 *
	 * @param array $data Response data.
	 * @return array Array of entity IDs.
	 */
	protected function extract_entity_ids( $data ) {
		$ids = array();

		if ( $this->is_collection( $data ) ) {
			// Collection - extract IDs from each item.
			foreach ( $data as $item ) {
				$id = $this->extract_entity_id( $item );
				if ( null !== $id ) {
					$ids[] = $id;
				}
			}
		} else {
			// Single item.
			$id = $this->extract_entity_id( $data );
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}

		return array_unique( array_filter( $ids ) );
	}

	/**
	 * Remove non-deterministic fields from data for ETag generation.
	 *
	 * Override in classes to exclude fields that change on each request
	 * (e.g., random recommendations, timestamps).
	 *
	 * @param array $data Response data.
	 * @return array Cleaned data.
	 */
	protected function remove_non_deterministic_fields( $data ) {
		return $data; // Default: no cleaning.
	}

	/**
	 * Get cache TTL in seconds.
	 *
	 * Override in classes to customize cache duration.
	 *
	 * @return int Cache TTL in seconds.
	 */
	protected function get_cache_ttl() {
		return 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Generate cache hash based on request and hooks.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string Cache hash.
	 */
	protected function generate_cache_hash( $request ) {
		global $wp_filter;

		$cache_hash_data = array();
		$filter_names    = $this->get_cache_hash_filters( $request );

		foreach ( $filter_names as $filter_name ) {
			if ( ! empty( $wp_filter[ $filter_name ] ) ) {
				$cache_hash_data[ $filter_name ] = array();

				foreach ( $wp_filter[ $filter_name ] as $priority => $callbacks ) {
					$cache_hash_data[ $filter_name ][ $priority ] = array_values(
						wp_list_pluck( $callbacks, 'function' )
					);
				}
			}
		}

		/**
		 * Filter cache hash data.
		 *
		 * @param array           $cache_hash_data Hash data.
		 * @param WP_REST_Request $request         Request object.
		 * @param object          $controller      Controller instance.
		 */
		$cache_hash_data = apply_filters(
			'woocommerce_rest_api_cache_hash',
			$cache_hash_data,
			$request,
			$this
		);

		return md5( wp_json_encode( $cache_hash_data ) );
	}

	/**
	 * Register collection cache for entity (reverse index).
	 *
	 * @param int    $entity_id            Entity ID.
	 * @param string $collection_cache_key Collection cache key.
	 */
	protected function register_collection_cache_for_entity( $entity_id, $collection_cache_key ) {
		$index_key   = $this->get_entity_collection_index_key( $entity_id );
		$collections = get_transient( $index_key );

		if ( ! is_array( $collections ) ) {
			$collections = array();
		}

		if ( ! in_array( $collection_cache_key, $collections, true ) ) {
			$collections[] = $collection_cache_key;
			// Use TTL + 1 minute buffer to ensure index outlives cache.
			set_transient( $index_key, $collections, $this->get_cache_ttl() + MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Get collection caches that include a specific entity.
	 *
	 * @param int $entity_id Entity ID.
	 * @return array Array of collection cache keys.
	 */
	protected function get_collection_caches_for_entity( $entity_id ) {
		$index_key   = $this->get_entity_collection_index_key( $entity_id );
		$collections = get_transient( $index_key );

		return is_array( $collections ) ? $collections : array();
	}

	/**
	 * Get entity collection index key.
	 *
	 * Override in classes to customize the index key pattern.
	 *
	 * @param int $entity_id Entity ID.
	 * @return string Index key.
	 */
	protected function get_entity_collection_index_key( $entity_id ) {
		// Use a normalized rest base if available, otherwise fall back to simple key.
		if ( method_exists( $this, 'get_normalized_rest_base' ) ) {
			return 'wc_rest_' . $this->get_normalized_rest_base() . '_collections_' . $entity_id;
		}
		
		return 'wc_rest_entity_collections_' . $entity_id;
	}

	/**
	 * Check if a route matches this controller's routes.
	 *
	 * Override in classes for custom route matching logic.
	 *
	 * @param string $route Request route.
	 * @return bool True if route matches this controller.
	 */
	protected function matches_route( $route ) {
		// For classes with $namespace and $rest_base properties.
		if ( isset( $this->namespace ) && isset( $this->rest_base ) ) {
			$expected_route = '/' . $this->namespace . '/' . $this->rest_base;
			
			if ( method_exists( $this, 'get_normalized_rest_base' ) ) {
				$normalized_base    = $this->get_normalized_rest_base();
				$expected_normalized = '/' . $this->namespace . '/' . $normalized_base;
				
				return strpos( $route, $expected_route ) === 0 
					|| strpos( $route, $expected_normalized ) === 0;
			}
			
			return strpos( $route, $expected_route ) === 0;
		}

		// For classes with $route_namespace property (RestApiControllerBase).
		if ( isset( $this->route_namespace ) ) {
			return strpos( $route, '/' . $this->route_namespace . '/' ) !== false;
		}

		return false;
	}

	/**
	 * Check cache and return early if valid (pre-dispatch).
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed Response or original result.
	 */
	public function maybe_return_cached_response( $result, $server, $request ) {
		// Only handle GET requests for this controller's endpoints.
		if ( $result !== null || $request->get_method() !== 'GET' ) {
			return $result;
		}

		// Check if this request matches this controller's routes.
		$route = $request->get_route();
		if ( ! $this->matches_route( $route ) ) {
			return $result;
		}

		$cache_info = $this->get_cache_key_info( $request );
		if ( ! $cache_info ) {
			return $result;
		}

		// Try to get cached response.
		$cached = get_transient( $cache_info['key'] );

		if ( $cached && isset( $cached['hash'], $cached['etag'], $cached['data'] ) ) {
			// Calculate current hash to see if hooks have changed.
			$current_hash = $this->generate_cache_hash( $request );

			if ( $cached['hash'] !== $current_hash ) {
				// Hooks have changed - invalidate cache.
				delete_transient( $cache_info['key'] );
				return $result;
			}

			// Cache is valid - check ETag.
			$request_etag = $request->get_header( 'if_none_match' );

			if ( $request_etag === $cached['etag'] ) {
				// Return 304 - no database queries!
				return new WP_REST_Response(
					null,
					304,
					array(
						'ETag'          => $cached['etag'],
						'X-WC-Cache'    => 'HIT-304',
						'Cache-Control' => 'private, must-revalidate, max-age=' . $this->get_cache_ttl(),
					)
				);
			}

			// Cache valid but ETag doesn't match - return cached data.
			return new WP_REST_Response(
				$cached['data'],
				200,
				array(
					'ETag'          => $cached['etag'],
					'X-WC-Cache'    => 'HIT-200',
					'Cache-Control' => 'private, must-revalidate, max-age=' . $this->get_cache_ttl(),
				)
			);
		}

		// No cache - store cache info for later use in post_dispatch.
		$request->set_param( '_cache_info', $cache_info );

		return $result;
	}

	/**
	 * Cache the response after all hooks have run (post-dispatch).
	 *
	 * @param WP_REST_Response $response Result to send to the client.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  Request used to generate the response.
	 * @return WP_REST_Response Response object.
	 */
	public function maybe_cache_response( $response, $server, $request ) {
		// Only handle GET requests that succeeded.
		if ( $request->get_method() !== 'GET' || $response->get_status() !== 200 ) {
			return $response;
		}

		// Check if this request matches this controller's routes.
		if ( ! $this->matches_route( $request->get_route() ) ) {
			return $response;
		}

		// If this was a cache hit, skip.
		if ( $response->get_header( 'X-WC-Cache' ) ) {
			return $response;
		}

		// Get cache info.
		$cache_info = $request->get_param( '_cache_info' );
		if ( ! $cache_info ) {
			$cache_info = $this->get_cache_key_info( $request );
		}

		if ( ! $cache_info ) {
			return $response;
		}

		$data = $response->get_data();

		// Extract entity IDs from the response.
		$entity_ids = $this->extract_entity_ids( $data );

		// Remove non-deterministic fields for ETag generation.
		$etag_data = $this->remove_non_deterministic_fields( $data );

		// Generate ETag from the actual response content.
		$etag = '"' . md5( wp_json_encode( $etag_data ) ) . '"';

		// Set cache headers.
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'private, must-revalidate, max-age=' . $this->get_cache_ttl() );
		$response->header( 'X-WC-Cache', 'MISS' );

		// Prepare cached data.
		$cache_data = array(
			'hash'       => $this->generate_cache_hash( $request ),
			'etag'       => $etag,
			'data'       => $data,
			'entity_ids' => $entity_ids,
		);

		// Cache the response.
		set_transient( $cache_info['key'], $cache_data, $this->get_cache_ttl() );

		// For collections, build reverse index.
		if ( isset( $cache_info['type'] ) && 'collection' === $cache_info['type'] ) {
			foreach ( $entity_ids as $entity_id ) {
				$this->register_collection_cache_for_entity( $entity_id, $cache_info['key'] );
			}
		}

		return $response;
	}

	/**
	 * Invalidate cache for an entity.
	 *
	 * Call this method when an entity changes to clear its caches.
	 *
	 * @param int $entity_id Entity ID.
	 */
	public function invalidate_entity_cache( $entity_id ) {
		// Delete single entity cache.
		$cache_key = $this->get_single_entity_cache_key( $entity_id );
		if ( $cache_key ) {
			delete_transient( $cache_key );
		}

		// Delete collection caches that include this entity.
		$collection_cache_keys = $this->get_collection_caches_for_entity( $entity_id );
		foreach ( $collection_cache_keys as $cache_key ) {
			delete_transient( $cache_key );
		}

		// Clean up the reverse index.
		delete_transient( $this->get_entity_collection_index_key( $entity_id ) );

		/**
		 * Fires after cache invalidation for an entity.
		 *
		 * @param int    $entity_id  Entity ID.
		 * @param object $controller Controller instance.
		 */
		do_action( 'woocommerce_rest_api_cache_invalidated', $entity_id, $this );
	}

	/**
	 * Get cache key for a single entity.
	 *
	 * Override in classes to provide entity-specific cache keys.
	 *
	 * @param int $entity_id Entity ID.
	 * @return string|null Cache key or null.
	 */
	protected function get_single_entity_cache_key( $entity_id ) {
		return null;
	}
}
