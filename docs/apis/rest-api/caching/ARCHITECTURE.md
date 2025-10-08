# WooCommerce REST API Caching Architecture

## Overview

The caching system is built into `WC_REST_Controller` and provides a generalized, extensible framework for caching any WooCommerce REST API endpoint. It uses ETags for conditional requests and includes intelligent cache invalidation based on hook changes and entity modifications.

## Key Features

### 1. **Hook-Based Cache Invalidation**
Inspired by `WC_Product_Variable_Data_Store_CPT::get_price_hash()`, the cache hash includes the actual callbacks registered to relevant hooks. When extensions activate/deactivate, the hash changes automatically, invalidating stale caches.

### 2. **Reverse Index for Collections**
Collection caches track which entities they contain. When an entity changes, only collections containing that entity are invalidated (not all collections).

### 3. **ETag Support**
Full HTTP ETag support for `304 Not Modified` responses, saving bandwidth and processing time.

### 4. **Zero-Configuration Defaults**
Existing endpoints continue to work without modification. Caching is opt-in by overriding specific methods.

## Architecture

### Base Class Methods (WC_REST_Controller)

#### Methods to Override (Opt-In)

```php
// Required: Determine cache key for the request
protected function get_cache_key_info( $request ) {
    // Return array with 'type' and 'key', or null to skip caching
}

// Required: Specify which hooks affect the response
protected function get_cache_hash_filters( $request ) {
    // Return array of filter names
}

// Optional: Extract ID from a single entity (default: $entity['id'] ?? null)
protected function extract_entity_id( $entity ) {
    // Return entity ID or null
}

// Optional: Extract all IDs from response (base class has full implementation)
// Only override if you need special logic (e.g., extracting parent IDs too)
protected function extract_entity_ids( $data ) {
    // Return array of entity IDs
}

// Optional: Check if response is a collection (default: isset($data[0]))
protected function is_collection( $data ) {
    // Return true if collection, false if single item
}

// Optional: Remove non-deterministic fields for ETag
protected function remove_non_deterministic_fields( $data ) {
    // Return cleaned data (default: no cleaning)
}

// Optional: Customize cache TTL
protected function get_cache_ttl() {
    // Return TTL in seconds (default: 5 minutes)
}

// Optional: Get cache key for single entity
protected function get_single_entity_cache_key( $entity_id ) {
    // Return cache key string or null
}
```

#### Public Methods (Call These)

```php
// Invalidate all caches for an entity
public function invalidate_entity_cache( $entity_id );
```

### How It Works

#### Request Flow (Cache Hit)

```
1. GET /wc/v3/products/123
   ↓
2. rest_pre_dispatch filter
   ↓
3. get_cache_key_info() → 'wc_rest_product_123'
   ↓
4. Check transient exists?
   ↓
5. Compare cached hash with current hash (hooks changed?)
   ↓
6. Check If-None-Match header against cached ETag
   ↓
7. Return 304 or 200 with cached data
   
   ✅ NO DATABASE QUERIES!
```

#### Request Flow (Cache Miss)

```
1. GET /wc/v3/products?page=1
   ↓
2. rest_pre_dispatch filter → no cache found
   ↓
3. Normal WooCommerce processing (DB queries, hooks, etc.)
   ↓
4. rest_post_dispatch filter
   ↓
5. extract_entity_ids() → [12, 45, 78, ...]
   ↓
6. remove_non_deterministic_fields() → clean data
   ↓
7. Generate ETag from cleaned data
   ↓
8. Cache: {hash, etag, data, entity_ids}
   ↓
9. Build reverse index:
   - wc_rest_product_collections_12 → ['wc_rest_collection_xyz']
   - wc_rest_product_collections_45 → ['wc_rest_collection_xyz']
   ↓
10. Return response with ETag and Cache-Control headers
```

#### Cache Invalidation Flow

```
Product 123 Updated
↓
woocommerce_update_product hook
↓
invalidate_entity_cache(123)
↓
1. Delete: wc_rest_product_123 (single)
↓
2. Check: wc_rest_product_collections_123
   Found: ['wc_rest_collection_abc', 'wc_rest_collection_xyz']
↓
3. Delete: wc_rest_collection_abc
   Delete: wc_rest_collection_xyz
↓
4. Delete: wc_rest_product_collections_123 (cleanup)
```

## Implementation Guide

### Step 1: Use the Trait

```php
use Automattic\WooCommerce\Internal\Traits\RestApiCache;

class WC_REST_Your_Entity_Controller extends WC_REST_CRUD_Controller {
    use RestApiCache;
    
    public function __construct() {
        parent::__construct();
        $this->register_cache_hooks();
    }
    
    // ... implement required methods
}
```

### Step 2: Implement get_cache_key_info()

```php
protected function get_cache_key_info( $request ) {
    $route = $request->get_route();
    
    // Single entity endpoint
    if ( preg_match( '#^/wc/v3/your-entities/(\d+)$#', $route, $matches ) ) {
        return array(
            'is_collection' => false,
            'key'           => 'wc_rest_your_entity_' . $matches[1],
            'id'            => (int) $matches[1],
        );
    }
    
    // Collection endpoint
    if ( strpos( $route, '/wc/v3/your-entities' ) !== false ) {
        $query_hash = md5( wp_json_encode( $request->get_query_params() ) );
        return array(
            'is_collection' => true,
            'key'           => 'wc_rest_your_entities_collection_' . $query_hash,
        );
    }
    
    return null; // Skip caching for this request
}
```

### Step 3: Implement get_cache_hash_filters()

```php
protected function get_cache_hash_filters( $request ) {
    return array(
        'woocommerce_rest_prepare_your_entity_object',
        'rest_prepare_your_entity',
        // Add any other filters that modify the response
    );
}
```

### Step 4: (Optional) Implement extract_entity_id()

**For most controllers, you can skip this step!** The base class already provides a full implementation of `extract_entity_ids()` that works for standard entities.

Only override `extract_entity_id()` if your entities use a different ID field:

```php
// Example: If your entities use 'entity_id' instead of 'id'
protected function extract_entity_id( $entity ) {
    return $entity['entity_id'] ?? null;
}
```

Only override `extract_entity_ids()` if you need special logic (e.g., extracting multiple related IDs):

```php
// Example: Variations also track parent product ID
protected function extract_entity_ids( $data ) {
    $ids = array();
    
    if ( $this->is_collection( $data ) ) {
        foreach ( $data as $item ) {
            $id = $this->extract_entity_id( $item );
            if ( null !== $id ) {
                $ids[] = $id;
                
                // Also track parent ID for cache invalidation
                if ( isset( $item['parent_id'] ) && $item['parent_id'] > 0 ) {
                    $ids[] = $item['parent_id'];
                }
            }
        }
    } else {
        $id = $this->extract_entity_id( $data );
        if ( null !== $id ) {
            $ids[] = $id;
            
            if ( isset( $data['parent_id'] ) && $data['parent_id'] > 0 ) {
                $ids[] = $data['parent_id'];
            }
        }
    }
    
    return array_unique( array_filter( $ids ) );
}
```

**Note**: The base class provides the full implementation using `is_collection()` and `extract_entity_id()`.

### Step 5: (Optional) Override extract_entity_id() for non-standard ID fields

```php
// Only needed if your entities use a different ID field name
protected function extract_entity_id( $entity ) {
    return $entity['custom_id'] ?? null;
}
```

### Step 6: (Optional) Implement remove_non_deterministic_fields()

```php
protected function remove_non_deterministic_fields( $data ) {
    // Remove fields that change on each request
    // e.g., random recommendations, computed timestamps
    
    if ( $this->is_collection( $data ) ) {
        // Collection - clean each item
        $clean_data = array();
        foreach ( $data as $key => $item ) {
            if ( isset( $item['random_recommendations'] ) ) {
                $clean_item = $item;
                unset( $clean_item['random_recommendations'] );
                $clean_data[ $key ] = $clean_item;
            } else {
                $clean_data[ $key ] = $item;
            }
        }
        return $clean_data;
    }
    
    // Single item
    if ( isset( $data['random_recommendations'] ) ) {
        $clean_data = $data;
        unset( $clean_data['random_recommendations'] );
        return $clean_data;
    }
    
    return $data;
}
```

### Step 7: (Optional) Implement get_single_entity_cache_key()

```php
protected function get_single_entity_cache_key( $entity_id ) {
    return 'wc_rest_your_entity_' . $entity_id;
}
```

### Step 8: Hook into Entity Changes

```php
add_action( 'your_entity_updated', function( $entity_id ) {
    $controller = new WC_REST_Your_Entity_Controller();
    $controller->invalidate_entity_cache( $entity_id );
}, 10, 1 );
```

## Extension Developer Guide

### Adding Custom Data to Cache Hash

Extensions can participate in cache invalidation by modifying the hash:

```php
add_filter( 'woocommerce_rest_api_cache_hash', function( $hash_data, $request, $controller ) {
    if ( $controller instanceof WC_REST_Products_Controller ) {
        // Add custom data that affects the response
        $hash_data['my_extension_version'] = MY_EXTENSION_VERSION;
        $hash_data['my_custom_setting'] = get_option( 'my_custom_setting' );
    }
    
    return $hash_data;
}, 10, 3 );
```

### Triggering Cache Invalidation

When extension data changes:

```php
// Invalidate cache for specific product
do_action( 'woocommerce_rest_api_cache_invalidated', $product_id );

// Or call the controller directly
$controller = new WC_REST_Products_Controller();
$controller->invalidate_entity_cache( $product_id );
```

## Performance Characteristics

### Cache Hit (304 Response)
- ✅ 0 database queries
- ✅ 0 product objects loaded
- ✅ 0 hook executions
- ✅ Minimal bandwidth (headers only)
- ⏱️ Response time: <10ms

### Cache Hit (200 Response with Cached Data)
- ✅ 0 database queries
- ✅ 0 product objects loaded
- ✅ 0 hook executions
- ⏱️ Response time: <20ms
- 📦 Full response body sent

### Cache Miss
- Normal WooCommerce processing
- Response cached for subsequent requests
- ⏱️ Response time: normal (no overhead)

## Cache Headers

All cached responses include:

```http
ETag: "a1b2c3d4e5f6..."
Cache-Control: private, must-revalidate, max-age=300
X-WC-Cache: HIT-304 | HIT-200 | MISS
```

## Best Practices

### 1. **Choose Appropriate TTL**
- Short-lived data (prices): 1-5 minutes
- Stable data (descriptions): 5-15 minutes
- Static data (categories): 15-60 minutes

### 2. **Identify Non-Deterministic Fields**
- Random values (related products)
- Computed timestamps
- User-specific data (not cacheable)

### 3. **Track All Affecting Hooks**
Include every hook that can modify the response:
```php
protected function get_cache_hash_filters( $request ) {
    return array(
        'woocommerce_rest_prepare_product_object',
        'rest_prepare_product',
        'woocommerce_rest_product_object_query',
        'woocommerce_product_get_price', // If price hooks affect output
        // etc.
    );
}
```

### 4. **Invalidate Aggressively**
When in doubt, invalidate. It's better to have fresh data than stale caches.

## Monitoring

### Check Cache Status

```bash
# Headers show cache status
curl -I https://example.com/wp-json/wc/v3/products/123

HTTP/1.1 200 OK
ETag: "abc123..."
X-WC-Cache: HIT-200
Cache-Control: private, must-revalidate, max-age=300
```

### Test 304 Response

```bash
# First request (cache miss)
curl -I https://example.com/wp-json/wc/v3/products/123
# Note the ETag value

# Second request with If-None-Match
curl -I https://example.com/wp-json/wc/v3/products/123 \
  -H "If-None-Match: \"abc123...\""

HTTP/1.1 304 Not Modified
ETag: "abc123..."
X-WC-Cache: HIT-304
```

## Troubleshooting

### Cache Not Being Used

1. Verify `$cache_enabled = true`
2. Check `get_cache_key_info()` returns non-null
3. Ensure endpoint is GET method only
4. Check for PHP errors in logs

### Stale Data Being Served

1. Verify invalidation hooks are registered
2. Check TTL is not too long
3. Ensure `extract_entity_ids()` captures all relevant IDs
4. Verify hooks in `get_cache_hash_filters()` are complete

### Performance Not Improving

1. Check `X-WC-Cache` header (should be HIT)
2. Verify database query count drops
3. Check object cache (Redis/Memcached) is working
4. Monitor transient storage performance

## Future Enhancements

Possible improvements:

1. **Tiered Caching**: Object cache → Persistent cache → Database
2. **Cache Warming**: Pre-generate popular endpoints
3. **Conditional Caching**: Cache only high-traffic endpoints
4. **Cache Analytics**: Track hit rates and performance gains
5. **Cluster Support**: Distributed cache invalidation
