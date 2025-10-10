<?php
/**
 * REST API Caching Trait
 *
 * Provides caching functionality for REST API controllers.
 * Can be used by both WC_REST_Controller and RestApiControllerBase.
 */

namespace Automattic\WooCommerce\Internal\Traits;

use Automattic\WooCommerce\Internal\Caches\RestApiObjectCache;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Trait for adding caching capabilities to REST API controllers.
 *
 * Usage:
 * 1. Add 'use RestApiCache;' to your controller class
 * 2. Implement required methods
 * 3. Call register_cache_hooks() in constructor or register() method
 *
 * @since   9.5.0
 */
trait RestApiCache {

	/**
	 * Cache instance.
	 *
	 * @var RestApiObjectCache|null
	 */
	private $cache_instance = null;

	/**
	 * Register cache-related hooks.
	 *
	 * Call this from the controller's constructor or init method.
	 */
	protected function register_cache_hooks() {
		add_filter( 'rest_pre_dispatch', array( $this, 'handle_rest_pre_dispatch' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'handle_rest_post_dispatch' ), 10, 3 );
		add_filter( 'rest_send_nocache_headers', array( $this, 'handle_rest_send_nocache_headers' ), 10, 2 );
	}

	/**
	 * Get the RestApiObjectCache instance.
	 *
	 * @return RestApiObjectCache
	 */
	protected function get_cache_instance() {
		if ( null === $this->cache_instance ) {
			$this->cache_instance = wc_get_container()->get( RestApiObjectCache::class );
		}
		return $this->cache_instance;
	}

	/**
	 * Get the current request route.
	 *
	 * Helper method for controllers to avoid having to call $request->get_route() repeatedly.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string Request route.
	 */
	protected function get_request_route( $request ) {
		return $request->get_route();
	}

	/**
	 * Get the matched route pattern for the current request.
	 *
	 * This returns the route pattern that was matched (e.g., '/wc/v3/products/(?P<id>[\d]+)')
	 * rather than the actual route (e.g., '/wc/v3/products/123').
	 * Useful for determining which endpoint was matched without parsing.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null Matched route pattern or null if not available.
	 */
	protected function get_matched_route( $request ) {
		$route = $request->get_route();
		$routes = rest_get_server()->get_routes();
		
		foreach ( $routes as $pattern => $handlers ) {
			if ( preg_match( '@^' . $pattern . '$@i', $route ) ) {
				return $pattern;
			}
		}
		
		return null;
	}

	/**
	 * Get the default entity type for caching.
	 *
	 * Override this method in classes to provide a default entity type.
	 *
	 * @return string|null Entity type (e.g., 'product', 'variation'), or null if no default.
	 */
	protected function get_default_entity_type() {
		return null;
	}

	/**
	 * Check if a request is cacheable.
	 *
	 * Override this method in classes to enable caching.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the request should be cached, false otherwise.
	 */
	protected function request_is_cacheable( $request ) {
		return false; // Default: no caching.
	}

	/**
	 * Get the core version of an entity.
	 *
	 * Override this method in classes to provide entity versioning logic.
	 * This is called when the cached version is not found.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id Entity ID.
	 * @return int|null Entity version, or null if not available.
	 */
	protected function get_entity_version_core( $entity_type, $entity_id ) {
		return null;
	}

	/**
	 * Get the version of an entity.
	 *
	 * Attempts to retrieve from transient cache first, falls back to get_entity_version_core.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id Entity ID.
	 * @return int|null Entity version, or null if not available.
	 */
	protected function get_entity_version( $entity_type, $entity_id ) {
		$transient_key = 'wc_rest_api_entity_version_' . $entity_type . '_' . $entity_id;
		$version       = get_transient( $transient_key );

		if ( false === $version ) {
			$version = $this->get_entity_version_core( $entity_type, $entity_id );
			if ( null !== $version ) {
				// Store with long expiration (30 days).
				set_transient( $transient_key, $version, 30 * DAY_IN_SECONDS );
			}
		}

		return $version;
	}

	/**
	 * Get request UID information for caching.
	 *
	 * Override this method in classes to enable caching.
	 * Return null to skip caching for this request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|null Array with 'request_hash' (string) and optional 'entity_type' (string), or null to skip caching.
	 */
	protected function get_request_uid_info( $request ) {
		if ( ! $this->request_is_cacheable( $request ) ) {
			return null;
		}

		// By default, return null - classes must override this.
		return null;
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
	 * Handle rest_pre_dispatch filter to check cache and return early if valid.
	 *
	 * @internal
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed Response or original result.
	 */
	public function handle_rest_pre_dispatch( $result, $server, $request ) {
		// Check for cache skip parameter first to minimize overhead.
		if ( $request->get_param( '_skip_cache' ) === 'true' ) {
			return null;
		}

		// Only handle GET requests for this controller's endpoints.
		if ( $result !== null || $request->get_method() !== 'GET' ) {
			return $result;
		}

		// If another controller already handled caching, skip.
		if ( $request->get_param( '_cache_uid_info' ) ) {
			return $result;
		}

		// Get request UID info - returns null if request is not cacheable.
		$uid_info = $this->get_request_uid_info( $request );
		if ( ! $uid_info ) {
			return null;
		}

		// Determine entity type.
		$entity_type = $uid_info['entity_type'] ?? $this->get_default_entity_type();
		if ( ! $entity_type ) {
			wc_doing_it_wrong(
				__METHOD__,
				'Request is cacheable but no entity type is provided. Override get_request_uid_info to return entity_type or get_default_entity_type to provide a default.',
				'9.5.0'
			);
			return null;
		}

		// Store UID info and entity type for post-dispatch.
		$uid_info['entity_type'] = $entity_type;
		$request->set_param( '_cache_uid_info', $uid_info );
		
		// Store controller class to ensure the same controller handles both pre and post dispatch.
		$request->set_param( '_caching_controller_class', get_class( $this ) );

		// Build cache ID from entity type and request hash.
		$cache_id = $entity_type . '-' . $uid_info['request_hash'];

		// Try to get cached response.
		$cached = $this->get_cache_instance()->get( $cache_id );

		// No cache or invalid cache structure - continue to normal processing.
		if ( ! $cached || ! isset( $cached['hooks_hash'], $cached['etag'], $cached['data'], $cached['created_at'], $cached['entity_versions'] ) ) {
			return null;
		}

		// Check if cache has expired based on creation time.
		$current_time    = time();
		$expiration_time = $cached['created_at'] + $this->get_cache_ttl();
		if ( $current_time >= $expiration_time ) {
			// Cache expired - delete and continue to normal processing.
			$this->get_cache_instance()->remove( $cache_id );
			return null;
		}

		// Calculate current hooks hash to see if hooks have changed.
		$current_hash = $this->generate_cache_hash( $request );

		if ( $cached['hooks_hash'] !== $current_hash ) {
			// Hooks have changed - invalidate cache.
			$this->get_cache_instance()->remove( $cache_id );
			return null;
		}

		// Validate entity versions (will be added later - for now, skip validation).
		// TODO: Add entity version validation here.

		// Cache is valid - check ETag.
		$request_etag = $request->get_header( 'if_none_match' );

		// Determine cache visibility based on authentication.
		$cache_visibility = is_user_logged_in() ? 'private' : 'public';

		// Prepare cache headers (used for both 304 and 200 responses).
		// Date header shows when the data was cached (important for max-age calculation).
		$cache_headers = array(
			'ETag'          => $cached['etag'],
			'Cache-Control' => $cache_visibility . ', must-revalidate, max-age=' . $this->get_cache_ttl(),
			'Date'          => gmdate( 'D, d M Y H:i:s', $cached['created_at'] ) . ' GMT',
		);

		if ( $request_etag === $cached['etag'] ) {
			// Return 304 - no database queries!
			$cache_headers['X-WC-Cache'] = 'HIT';
			return new WP_REST_Response( null, 304, $cache_headers );
		}

		// Cache valid but ETag doesn't match - return cached data with full headers.
		$cache_headers['X-WC-Cache'] = 'HIT';
		return new WP_REST_Response( $cached['data'], 200, $cache_headers );
	}

	/**
	 * Handle rest_send_nocache_headers filter to prevent WordPress from overriding our cache headers.
	 *
	 * @internal
	 *
	 * @param bool            $send_no_cache_headers Whether to send no-cache headers.
	 * @param WP_REST_Request $request               Request object.
	 * @return bool False if we're handling caching, original value otherwise.
	 */
	public function handle_rest_send_nocache_headers( $send_no_cache_headers, $request ) {
		// If any controller is handling caching for this request, don't let WordPress send no-cache headers.
		if ( $request->get_param( '_cache_uid_info' ) ) {
			return false;
		}

		return $send_no_cache_headers;
	}

	/**
	 * Handle rest_post_dispatch filter to cache the response after all hooks have run.
	 *
	 * @internal
	 *
	 * @param WP_REST_Response $response Result to send to the client.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  Request used to generate the response.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_rest_post_dispatch( $response, $server, $request ) {
		// Check for cache skip parameter first to minimize overhead.
		if ( $request->get_param( '_skip_cache' ) === 'true' ) {
			$response->header( 'X-WC-Cache', 'SKIP' );
			return $response;
		}

		// Only handle GET requests that succeeded.
		if ( $request->get_method() !== 'GET' || $response->get_status() !== 200 ) {
			return $response;
		}

		// Get UID info from pre-dispatch (already computed there).
		$uid_info = $request->get_param( '_cache_uid_info' );
		if ( ! $uid_info ) {
			// Pre-dispatch didn't set UID info, so this request doesn't use caching.
			return $response;
		}

		// Verify this is the same controller that handled pre-dispatch.
		$caching_controller = $request->get_param( '_caching_controller_class' );
		if ( $caching_controller !== get_class( $this ) ) {
			return $response;
		}

		// If this was a cache hit from pre-dispatch, all headers are already set.
		// Pre-dispatch sets: ETag, Cache-Control, Date, X-WC-Cache
		$headers = $response->get_headers();
		if ( isset( $headers['X-WC-Cache'] ) ) {
			return $response; // Headers already complete, nothing to do.
		}

		$data = $response->get_data();

		// Extract entity IDs from the response.
		$entity_ids = $this->extract_entity_ids( $data );

		// Build entity versions array.
		$entity_type     = $uid_info['entity_type'];
		$entity_versions = array();
		foreach ( $entity_ids as $entity_id ) {
			$version = $this->get_entity_version( $entity_type, $entity_id );
			if ( null !== $version ) {
				$entity_versions[ $entity_id ] = $version;
			}
		}

		// Remove non-deterministic fields for ETag generation.
		$etag_data = $this->remove_non_deterministic_fields( $data );

		// Generate ETag from the actual response content.
		$etag = '"' . md5( wp_json_encode( $etag_data ) ) . '"';

		// Determine cache visibility based on authentication.
		$cache_visibility = is_user_logged_in() ? 'private' : 'public';

		// Set cache headers.
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', $cache_visibility . ', must-revalidate, max-age=' . $this->get_cache_ttl() );
		$response->header( 'X-WC-Cache', 'MISS' );

		// Prepare cached data.
		$cache_data = array(
			'entity_type'      => $entity_type,
			'created_at'       => time(),
			'hooks_hash'       => $this->generate_cache_hash( $request ),
			'data'             => $data,
			'entity_versions'  => $entity_versions,
			'etag'             => $etag,
		);

		// Build cache ID from entity type and request hash.
		$cache_id = $entity_type . '-' . $uid_info['request_hash'];

		// Cache the response using RestApiObjectCache.
		$this->get_cache_instance()->set( $cache_data, $cache_id, $this->get_cache_ttl() );

		// Remove UID info and controller class so other controllers know this request was handled.
		$request->set_param( '_cache_uid_info', null );
		$request->set_param( '_caching_controller_class', null );

		return $response;
	}

	/**
	 * Invalidate cache for an entity.
	 *
	 * Call this method when an entity changes to clear its caches.
	 * This invalidates the entity version transient, which will cause all cached responses
	 * containing this entity to be invalidated on next retrieval.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 */
	public function invalidate_entity_cache( $entity_type, $entity_id ) {
		// Delete the entity version transient to force cache invalidation.
		$transient_key = 'wc_rest_api_entity_version_' . $entity_type . '_' . $entity_id;
		delete_transient( $transient_key );

		/**
		 * Fires after cache invalidation for an entity.
		 *
		 * @param string $entity_type Entity type.
		 * @param int    $entity_id   Entity ID.
		 * @param object $controller  Controller instance.
		 */
		do_action( 'woocommerce_rest_api_cache_invalidated', $entity_type, $entity_id, $this );
	}

	/**
	 * Flush all REST API caches.
	 *
	 * This removes all cached REST API responses.
	 */
	public function flush_all_caches() {
		$this->get_cache_instance()->flush();
	}
}
