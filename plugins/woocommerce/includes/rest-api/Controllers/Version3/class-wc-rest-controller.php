<?php
/**
 * REST Controller
 *
 * This class extend `WP_REST_Controller` in order to include /batch endpoint
 * for almost all endpoints in WooCommerce REST API.
 *
 * It's required to follow "Controller Classes" guide before extending this class:
 * <https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/>
 *
 * NOTE THAT ONLY CODE RELEVANT FOR MOST ENDPOINTS SHOULD BE INCLUDED INTO THIS CLASS.
 * If necessary extend this class and create new abstract classes like `WC_REST_CRUD_Controller` or `WC_REST_Terms_Controller`.
 *
 * @class   WC_REST_Controller
 * @package WooCommerce\RestApi
 * @see     https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Rest Controller Class
 *
 * @package WooCommerce\RestApi
 * @extends  WP_REST_Controller
 * @version  2.6.0
 */
abstract class WC_REST_Controller extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Used to cache computed return fields.
	 *
	 * @var null|array
	 */
	private $_fields = null;

	/**
	 * Used to verify if cached fields are for correct request object.
	 *
	 * @var null|WP_REST_Request
	 */
	private $_request = null;

	/**
	 * Whether REST API caching is enabled for this controller.
	 *
	 * @var bool
	 */
	protected $cache_enabled = false;

	/**
	 * Constructor - register cache hooks.
	 */
	public function __construct() {
		if ( $this->is_cache_enabled() ) {
			add_filter( 'rest_pre_dispatch', array( $this, 'maybe_return_cached_response' ), 10, 3 );
			add_filter( 'rest_post_dispatch', array( $this, 'maybe_cache_response' ), 10, 3 );
		}
	}

	/**
	 * Add the schema from additional fields to an schema array.
	 *
	 * The type of object is inferred from the passed schema.
	 *
	 * @param array $schema Schema array.
	 *
	 * @return array
	 */
	protected function add_additional_fields_schema( $schema ) {
		if ( empty( $schema['title'] ) ) {
			return $schema;
		}

		/**
		 * Can't use $this->get_object_type otherwise we cause an inf loop.
		 */
		$object_type = $schema['title'];

		$additional_fields = $this->get_additional_fields( $object_type );

		foreach ( $additional_fields as $field_name => $field_options ) {
			if ( ! $field_options['schema'] ) {
				continue;
			}

			$schema['properties'][ $field_name ] = $field_options['schema'];
		}

		$schema['properties'] = apply_filters( 'woocommerce_rest_' . $object_type . '_schema', $schema['properties'] );

		return $schema;
	}

	/**
	 * Compatibility functions for WP 5.5, since custom types are not supported anymore.
	 * See @link https://core.trac.wordpress.org/changeset/48306
	 *
	 * @param string $method Optional. HTTP method of the request.
	 *
	 * @return array Endpoint arguments.
	 */
	public function get_endpoint_args_for_item_schema( $method = WP_REST_Server::CREATABLE ) {

		$endpoint_args = parent::get_endpoint_args_for_item_schema( $method );

		if ( false === strpos( WP_REST_Server::EDITABLE, $method ) ) {
			return $endpoint_args;
		}

		$endpoint_args = $this->adjust_wp_5_5_datatype_compatibility( $endpoint_args );

		return $endpoint_args;
	}

	/**
	 * Change datatypes `date-time` to string, and `mixed` to composite of all built in types. This is required for maintaining forward compatibility with WP 5.5 since custom post types are not supported anymore.
	 *
	 * See @link https://core.trac.wordpress.org/changeset/48306
	 *
	 * We still use the 'mixed' type, since if we convert to composite type everywhere, it won't work in 5.4 anymore because they require to define the full schema.
	 *
	 * @param array $endpoint_args Schema with datatypes to convert.

	 * @return mixed Schema with converted datatype.
	 */
	protected function adjust_wp_5_5_datatype_compatibility( $endpoint_args ) {
		if ( version_compare( get_bloginfo( 'version' ), '5.5', '<' ) ) {
			return $endpoint_args;
		}

		foreach ( $endpoint_args as $field_id => $params ) {

			if ( ! isset( $params['type'] ) ) {
				continue;
			}

			/**
			 * Custom types are not supported as of WP 5.5, this translates type => 'date-time' to type => 'string'.
			 */
			if ( 'date-time' === $params['type'] ) {
				$params['type'] = array( 'null', 'string' );
			}

			/**
			 * WARNING: Order of fields here is important, types of fields are ordered from most specific to least specific as perceived by core's built-in type validation methods.
			 */
			if ( 'mixed' === $params['type'] ) {
				$params['type'] = array( 'null', 'object', 'string', 'number', 'boolean', 'integer', 'array' );
			}

			if ( isset( $params['properties'] ) ) {
				$params['properties'] = $this->adjust_wp_5_5_datatype_compatibility( $params['properties'] );
			}

			if ( isset( $params['items'] ) && isset( $params['items']['properties'] ) ) {
				$params['items']['properties'] = $this->adjust_wp_5_5_datatype_compatibility( $params['items']['properties'] );
			}

			$endpoint_args[ $field_id ] = $params;
		}
		return $endpoint_args;
	}

	/**
	 * Get normalized rest base.
	 *
	 * @return string
	 */
	protected function get_normalized_rest_base() {
		return preg_replace( '/\(.*\)\//i', '', $this->rest_base );
	}

	/**
	 * Check batch limit.
	 *
	 * @param array $items Request items.
	 * @return bool|WP_Error
	 */
	protected function check_batch_limit( $items ) {
		$limit = apply_filters( 'woocommerce_rest_batch_items_limit', 100, $this->get_normalized_rest_base() );
		$total = 0;

		if ( ! empty( $items['create'] ) && is_countable( $items['create'] ) ) {
			$total += count( $items['create'] );
		}

		if ( ! empty( $items['update'] ) && is_countable( $items['update'] ) ) {
			$total += count( $items['update'] );
		}

		if ( ! empty( $items['delete'] ) && is_countable( $items['delete'] ) ) {
			$total += count( $items['delete'] );
		}

		if ( $total > $limit ) {
			/* translators: %s: items limit */
			return new WP_Error( 'woocommerce_rest_request_entity_too_large', sprintf( __( 'Unable to accept more than %s items for this request.', 'woocommerce' ), $limit ), array( 'status' => 413 ) );
		}

		return true;
	}

	/**
	 * Bulk create, update and delete items.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array Of WP_Error or WP_REST_Response.
	 */
	public function batch_items( $request ) {
		/**
		 * REST Server
		 *
		 * @var WP_REST_Server $wp_rest_server
		 */
		global $wp_rest_server;

		// Get the request params.
		$items    = array_filter( $request->get_params() );
		$query    = $request->get_query_params();
		$response = array();

		// Check batch limit.
		$limit = $this->check_batch_limit( $items );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		if ( ! empty( $items['create'] ) ) {
			foreach ( $items['create'] as $item ) {
				$_item = new WP_REST_Request( 'POST', $request->get_route() );

				// Default parameters.
				$defaults = array();
				$schema   = $this->get_public_item_schema();
				foreach ( $schema['properties'] as $arg => $options ) {
					if ( isset( $options['default'] ) ) {
						$defaults[ $arg ] = $options['default'];
					}
				}
				$_item->set_default_params( $defaults );

				// Set request parameters.
				$_item->set_body_params( $item );

				// Set query (GET) parameters.
				$_item->set_query_params( $query );

				$allowed = $this->create_item_permissions_check( $_item );
				if ( is_wp_error( $allowed ) ) {
					$response['create'][] = array(
						'id'    => 0,
						'error' => array(
							'code'    => $allowed->get_error_code(),
							'message' => $allowed->get_error_message(),
							'data'    => $allowed->get_error_data(),
						),
					);
					continue;
				}

				$_response = $this->create_item( $_item );

				if ( is_wp_error( $_response ) ) {
					$response['create'][] = array(
						'id'    => 0,
						'error' => array(
							'code'    => $_response->get_error_code(),
							'message' => $_response->get_error_message(),
							'data'    => $_response->get_error_data(),
						),
					);
				} else {
					$response['create'][] = $wp_rest_server->response_to_data( $_response, '' );
				}
			}
		}

		if ( ! empty( $items['update'] ) ) {
			foreach ( $items['update'] as $item ) {
				$_item = new WP_REST_Request( 'PUT', $request->get_route() );
				$_item->set_body_params( $item );

				$allowed = $this->update_item_permissions_check( $_item );
				if ( is_wp_error( $allowed ) ) {
					$response['update'][] = array(
						'id'    => $_item['id'],
						'error' => array(
							'code'    => $allowed->get_error_code(),
							'message' => $allowed->get_error_message(),
							'data'    => $allowed->get_error_data(),
						),
					);
					continue;
				}

				$_response = $this->update_item( $_item );

				if ( is_wp_error( $_response ) ) {
					$response['update'][] = array(
						'id'    => $item['id'],
						'error' => array(
							'code'    => $_response->get_error_code(),
							'message' => $_response->get_error_message(),
							'data'    => $_response->get_error_data(),
						),
					);
				} else {
					$response['update'][] = $wp_rest_server->response_to_data( $_response, '' );
				}
			}
		}

		if ( ! empty( $items['delete'] ) ) {
			foreach ( $items['delete'] as $id ) {
				$id = is_array( $id ) ? $id : (int) $id;

				if ( 0 === $id ) {
					continue;
				}

				$_item = new WP_REST_Request( 'DELETE', $request->get_route() );
				if ( is_array( $id ) ) {
					$id['force'] = true;
					$_item->set_query_params( $id );
				} else {
					$_item->set_query_params(
						array(
							'id'    => $id,
							'force' => true,
						)
					);
				}

				$allowed = $this->delete_item_permissions_check( $_item );
				if ( is_wp_error( $allowed ) ) {
					$response['delete'][] = array(
						'id'    => $id,
						'error' => array(
							'code'    => $allowed->get_error_code(),
							'message' => $allowed->get_error_message(),
							'data'    => $allowed->get_error_data(),
						),
					);
					continue;
				}

				$_response = $this->delete_item( $_item );

				if ( is_wp_error( $_response ) ) {
					$response['delete'][] = array(
						'id'    => $id,
						'error' => array(
							'code'    => $_response->get_error_code(),
							'message' => $_response->get_error_message(),
							'data'    => $_response->get_error_data(),
						),
					);
				} else {
					$response['delete'][] = $wp_rest_server->response_to_data( $_response, '' );
				}
			}
		}

		return $response;
	}

	/**
	 * Validate a text value for a text based setting.
	 *
	 * @since 3.0.0
	 * @param string $value Value.
	 * @param array  $setting Setting.
	 * @return string
	 */
	public function validate_setting_text_field( $value, $setting ) {
		$value = is_null( $value ) ? '' : $value;
		return wp_kses_post( trim( stripslashes( $value ) ) );
	}

	/**
	 * Validate select based settings.
	 *
	 * @since 3.0.0
	 * @param string $value Value.
	 * @param array  $setting Setting.
	 * @return string|WP_Error
	 */
	public function validate_setting_select_field( $value, $setting ) {
		if ( array_key_exists( $value, $setting['options'] ) ) {
			return $value;
		} else {
			return new WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'woocommerce' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Validate multiselect based settings.
	 *
	 * @since 3.0.0
	 * @param array $values Values.
	 * @param array $setting Setting.
	 * @return array|WP_Error
	 */
	public function validate_setting_multiselect_field( $values, $setting ) {
		if ( empty( $values ) ) {
			return array();
		}

		if ( ! is_array( $values ) ) {
			return new WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'woocommerce' ), array( 'status' => 400 ) );
		}

		$final_values = array();
		foreach ( $values as $value ) {
			if ( array_key_exists( $value, $setting['options'] ) ) {
				$final_values[] = $value;
			}
		}

		return $final_values;
	}

	/**
	 * Validate image_width based settings.
	 *
	 * @since 3.0.0
	 * @param array $values Values.
	 * @param array $setting Setting.
	 * @return string|WP_Error
	 */
	public function validate_setting_image_width_field( $values, $setting ) {
		if ( ! is_array( $values ) ) {
			return new WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'woocommerce' ), array( 'status' => 400 ) );
		}

		$current = $setting['value'];
		if ( isset( $values['width'] ) ) {
			$current['width'] = intval( $values['width'] );
		}
		if ( isset( $values['height'] ) ) {
			$current['height'] = intval( $values['height'] );
		}
		if ( isset( $values['crop'] ) ) {
			$current['crop'] = (bool) $values['crop'];
		}
		return $current;
	}

	/**
	 * Validate radio based settings.
	 *
	 * @since 3.0.0
	 * @param string $value Value.
	 * @param array  $setting Setting.
	 * @return string|WP_Error
	 */
	public function validate_setting_radio_field( $value, $setting ) {
		return $this->validate_setting_select_field( $value, $setting );
	}

	/**
	 * Validate checkbox based settings.
	 *
	 * @since 3.0.0
	 * @param string $value Value.
	 * @param array  $setting Setting.
	 * @return string|WP_Error
	 */
	public function validate_setting_checkbox_field( $value, $setting ) {
		if ( in_array( $value, array( 'yes', 'no' ) ) ) {
			return $value;
		} elseif ( empty( $value ) ) {
			$value = isset( $setting['default'] ) ? $setting['default'] : 'no';
			return $value;
		} else {
			return new WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'woocommerce' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Validate textarea based settings.
	 *
	 * @since 3.0.0
	 * @since 9.0.0 No longer allows storing IFRAME, which was allowed for "ShareThis" integration no longer found in core.
	 * @param string $value Value.
	 * @param array  $setting Setting.
	 * @return string
	 */
	public function validate_setting_textarea_field( $value, $setting ) {
		$value = is_null( $value ) ? '' : $value;
		return wp_kses_post( trim( stripslashes( $value ) ) );
	}

	/**
	 * Add meta query.
	 *
	 * @since 3.0.0
	 * @param array $args       Query args.
	 * @param array $meta_query Meta query.
	 * @return array
	 */
	protected function add_meta_query( $args, $meta_query ) {
		if ( empty( $args['meta_query'] ) ) {
			$args['meta_query'] = array();
		}

		$args['meta_query'][] = $meta_query;

		return $args['meta_query'];
	}

	/**
	 * Get the batch schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_public_batch_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'batch',
			'type'       => 'object',
			'properties' => array(
				'create' => array(
					'description' => __( 'List of created resources.', 'woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type' => 'object',
					),
				),
				'update' => array(
					'description' => __( 'List of updated resources.', 'woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type' => 'object',
					),
				),
				'delete' => array(
					'description' => __( 'List of delete resources.', 'woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
			),
		);

		return $schema;
	}

	/**
	 * Gets an array of fields to be included on the response.
	 *
	 * Included fields are based on item schema and `_fields=` request argument.
	 * Updated from WordPress 5.3, included into this class to support old versions.
	 *
	 * @since 3.5.0
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array Fields to be included in the response.
	 */
	public function get_fields_for_response( $request ) {
		// From xdebug profiling, this method could take upto 25% of request time in index calls.
		// Cache it and make sure _fields was cached on current request object!
		// TODO: Submit this caching behavior in core.
		if ( isset( $this->_fields ) && is_array( $this->_fields ) && $request === $this->_request ) {
			return $this->_fields;
		}
		$this->_request = $request;

		$schema     = $this->get_item_schema();
		$properties = isset( $schema['properties'] ) ? $schema['properties'] : array();

		$additional_fields = $this->get_additional_fields();

		foreach ( $additional_fields as $field_name => $field_options ) {
			// For back-compat, include any field with an empty schema
			// because it won't be present in $this->get_item_schema().
			if ( is_null( $field_options['schema'] ) ) {
				$properties[ $field_name ] = $field_options;
			}
		}

		// Exclude fields that specify a different context than the request context.
		$context = $request['context'];
		if ( $context ) {
			foreach ( $properties as $name => $options ) {
				if ( ! empty( $options['context'] ) && ! in_array( $context, $options['context'], true ) ) {
					unset( $properties[ $name ] );
				}
			}
		}

		$fields = array_keys( $properties );

		if ( ! isset( $request['_fields'] ) ) {
			$this->_fields = $fields;
			return $fields;
		}
		$requested_fields = wp_parse_list( $request['_fields'] );
		if ( 0 === count( $requested_fields ) ) {
			$this->_fields = $fields;
			return $fields;
		}
		// Trim off outside whitespace from the comma delimited list.
		$requested_fields = array_map( 'trim', $requested_fields );
		// Always persist 'id', because it can be needed for add_additional_fields_to_object().
		if ( in_array( 'id', $fields, true ) ) {
			$requested_fields[] = 'id';
		}
		// Return the list of all requested fields which appear in the schema.
		$this->_fields = array_reduce(
			$requested_fields,
			function ( $response_fields, $field ) use ( $fields ) {
				if ( in_array( $field, $fields, true ) ) {
					$response_fields[] = $field;
					return $response_fields;
				}
				// Check for nested fields if $field is not a direct match.
				$nested_fields = explode( '.', $field );
				// A nested field is included so long as its top-level property
				// is present in the schema.
				if ( in_array( $nested_fields[0], $fields, true ) ) {
					$response_fields[] = $field;
				}
				return $response_fields;
			},
			array()
		);
		return $this->_fields;
	}

	/**
	 * Limit the contents of the meta_data property based on certain request parameters.
	 *
	 * Note that if both `include_meta` and `exclude_meta` are present in the request,
	 * `include_meta` will take precedence.
	 *
	 * @param \WP_REST_Request $request   The request.
	 * @param array            $meta_data All of the meta data for an object.
	 *
	 * @return array
	 */
	protected function get_meta_data_for_response( $request, $meta_data ) {
		$fields = $this->get_fields_for_response( $request );
		if ( ! in_array( 'meta_data', $fields, true ) ) {
			return array();
		}

		$include = (array) $request['include_meta'];
		$exclude = (array) $request['exclude_meta'];

		if ( ! empty( $include ) ) {
			$meta_data = array_filter(
				$meta_data,
				function ( WC_Meta_Data $item ) use ( $include ) {
					$data = $item->get_data();
					return in_array( $data['key'], $include, true );
				}
			);
		} elseif ( ! empty( $exclude ) ) {
			$meta_data = array_filter(
				$meta_data,
				function ( WC_Meta_Data $item ) use ( $exclude ) {
					$data = $item->get_data();
					return ! in_array( $data['key'], $exclude, true );
				}
			);
		}

		// Ensure the array indexes are reset so it doesn't get converted to an object in JSON.
		return array_values( $meta_data );
	}

	/* -------------------------------------------------------------------------
	 * REST API Caching Methods
	 * ------------------------------------------------------------------------- */

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
	 * Override this method in child classes to enable caching.
	 * Return null to skip caching for this request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|null Array with 'type' and 'key' or null to skip caching.
	 */
	protected function get_cache_key_info( $request ) {
		return null; // Default: no caching
	}

	/**
	 * Get filter names to include in cache hash.
	 *
	 * Override in child classes to specify which hooks affect the response.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array Array of filter names.
	 */
	protected function get_cache_hash_filters( $request ) {
		return array();
	}

	/**
	 * Extract entity IDs from response data.
	 *
	 * Override in child classes to extract IDs for cache invalidation tracking.
	 *
	 * @param array $data Response data.
	 * @return array Array of entity IDs.
	 */
	protected function extract_entity_ids( $data ) {
		$ids = array();

		// Check if this is a collection (indexed array) vs single item (associative array).
		// Collections have numeric keys starting at 0.
		if ( isset( $data[0] ) ) {
			// Collection - extract IDs from each item.
			foreach ( $data as $item ) {
				if ( isset( $item['id'] ) ) {
					$ids[] = $item['id'];
				}
			}
		} elseif ( isset( $data['id'] ) ) {
			// Single item - just the one ID.
			$ids[] = $data['id'];
		}

		return array_unique( array_filter( $ids ) );
	}

	/**
	 * Remove non-deterministic fields from data for ETag generation.
	 *
	 * Override in child classes to exclude fields that change on each request
	 * (e.g., random recommendations, timestamps).
	 *
	 * @param array $data Response data.
	 * @return array Cleaned data.
	 */
	protected function remove_non_deterministic_fields( $data ) {
		return $data; // Default: no cleaning
	}

	/**
	 * Get cache TTL in seconds.
	 *
	 * Override in child classes to customize cache duration.
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
		 * @param WC_REST_Controller $controller   Controller instance.
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
	 * @param int    $entity_id          Entity ID.
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
	 * Override in child classes to customize the index key pattern.
	 *
	 * @param int $entity_id Entity ID.
	 * @return string Index key.
	 */
	protected function get_entity_collection_index_key( $entity_id ) {
		return 'wc_rest_' . $this->get_normalized_rest_base() . '_collections_' . $entity_id;
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
		if ( $result !== null
			|| $request->get_method() !== 'GET'
			|| strpos( $request->get_route(), $this->namespace ) === false ) {
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
				return new WP_REST_Response( null, 304, array(
					'ETag'        => $cached['etag'],
					'X-WC-Cache'  => 'HIT-304',
					'Cache-Control' => 'private, must-revalidate, max-age=' . $this->get_cache_ttl(),
				) );
			}

			// Cache valid but ETag doesn't match - return cached data.
			return new WP_REST_Response( $cached['data'], 200, array(
				'ETag'        => $cached['etag'],
				'X-WC-Cache'  => 'HIT-200',
				'Cache-Control' => 'private, must-revalidate, max-age=' . $this->get_cache_ttl(),
			) );
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
		if ( $request->get_method() !== 'GET'
			|| $response->get_status() !== 200
			|| strpos( $request->get_route(), $this->namespace ) === false ) {
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
		 * @param int                $entity_id  Entity ID.
		 * @param WC_REST_Controller $controller Controller instance.
		 */
		do_action( 'woocommerce_rest_api_cache_invalidated', $entity_id, $this );
	}

	/**
	 * Get cache key for a single entity.
	 *
	 * Override in child classes to provide entity-specific cache keys.
	 *
	 * @param int $entity_id Entity ID.
	 * @return string|null Cache key or null.
	 */
	protected function get_single_entity_cache_key( $entity_id ) {
		return null;
	}
}
