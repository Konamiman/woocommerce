<?php
/**
 * Example: How to implement REST API caching with RestApiControllerBase
 * 
 * This demonstrates how to use the WC_REST_Cacheable trait with the newer
 * RestApiControllerBase class used in src/Internal controllers.
 */

namespace Automattic\WooCommerce\Internal\Example;

use Automattic\WooCommerce\Internal\RestApiControllerBase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Example controller using RestApiControllerBase with caching.
 */
class CustomEntityController extends RestApiControllerBase {

	/**
	 * Enable caching for this controller.
	 *
	 * @var bool
	 */
	protected $cache_enabled = true;

	/**
	 * Get the WooCommerce REST API namespace.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'custom-entities';
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Single entity endpoint
		register_rest_route(
			$this->route_namespace,
			'/custom-entities/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_entity' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'read', $request->get_param( 'id' ) ),
				),
			)
		);

		// Collection endpoint
		register_rest_route(
			$this->route_namespace,
			'/custom-entities',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_entities' ),
					'permission_callback' => fn( $request ) => $this->check_permission( $request, 'read' ),
				),
			)
		);
	}

	/**
	 * Get a single entity.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array Entity data.
	 */
	protected function get_entity( WP_REST_Request $request ) {
		$entity_id = $request->get_param( 'id' );
		
		// Your logic to fetch entity
		return array(
			'id'   => $entity_id,
			'name' => 'Entity ' . $entity_id,
			'data' => 'Some data',
		);
	}

	/**
	 * Get multiple entities.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array Array of entities.
	 */
	protected function get_entities( WP_REST_Request $request ) {
		// Your logic to fetch entities
		return array(
			array( 'id' => 1, 'name' => 'Entity 1' ),
			array( 'id' => 2, 'name' => 'Entity 2' ),
			array( 'id' => 3, 'name' => 'Entity 3' ),
		);
	}

	/* -------------------------------------------------------------------------
	 * Caching Implementation - Override these methods from WC_REST_Cacheable
	 * ------------------------------------------------------------------------- */

	/**
	 * Get cache key information for the request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|null Cache key info or null to skip caching.
	 */
	protected function get_cache_key_info( $request ) {
		$route = $request->get_route();

		// Single entity: /wc/v3/custom-entities/{id}
		if ( preg_match( '#^/wc/v3/custom-entities/(\d+)$#', $route, $matches ) ) {
			return array(
				'key'       => 'wc_rest_custom_entity_' . $matches[1],
				'entity_id' => (int) $matches[1],
			);
		}

		// Collection endpoint: /wc/v3/custom-entities
		if ( strpos( $route, '/wc/v3/custom-entities' ) !== false ) {
			$query_hash = md5( wp_json_encode( $request->get_query_params() ) );
			return array(
				'key' => 'wc_rest_custom_entities_collection_' . $query_hash,
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
			'woocommerce_rest_prepare_custom_entity',
			// Add any other filters that modify the response
		);
	}

	/**
	 * Get cache key for a single entity.
	 *
	 * @param int $entity_id Entity ID.
	 * @return string Cache key.
	 */
	protected function get_single_entity_cache_key( $entity_id ) {
		return 'wc_rest_custom_entity_' . $entity_id;
	}

	/**
	 * Override matches_route for RestApiControllerBase pattern.
	 *
	 * @param string $route Request route.
	 * @return bool True if route matches this controller.
	 */
	protected function matches_route( $route ) {
		// RestApiControllerBase uses $route_namespace instead of $namespace
		return strpos( $route, '/' . $this->route_namespace . '/custom-entities' ) !== false;
	}

	// Note: extract_entity_ids() is provided by the trait.
	// Note: get_data_for_etag() - override if needed to exclude non-deterministic fields.
}

/**
 * Hook into entity changes to invalidate caches.
 */
add_action( 'custom_entity_updated', function( $entity_id ) {
	$container  = wc_get_container();
	$controller = $container->get( CustomEntityController::class );
	$controller->invalidate_entity_cache( $entity_id );
}, 10, 1 );
