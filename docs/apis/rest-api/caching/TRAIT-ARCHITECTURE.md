# Trait-Based Caching Architecture

## Why a Trait?

WooCommerce has **two base controller classes**:

1. **`WC_REST_Controller`** (legacy) - `includes/rest-api/Controllers/Version3/`
   - Extends `WP_REST_Controller`
   - Used by most existing controllers (Products, Orders, Customers, etc.)

2. **`RestApiControllerBase`** (modern) - `src/Internal/`
   - Implements `RegisterHooksInterface`
   - Used by newer internal controllers
   - Different registration pattern

**Problem**: We need caching in both hierarchies without duplicating code.

**Solution**: `WC_REST_Cacheable` trait! ✅

## Trait Method Override Behavior

### Yes, Trait Methods CAN Be Overridden! ✅

```php
trait WC_REST_Cacheable {
    protected function get_cache_ttl() {
        return 5 * MINUTE_IN_SECONDS;
    }
}

class MyController {
    use WC_REST_Cacheable;
    
    // This OVERRIDES the trait method ✅
    protected function get_cache_ttl() {
        return 10 * MINUTE_IN_SECONDS;
    }
}
```

**How it works**:
- Class methods take precedence over trait methods
- Trait provides default implementation
- Class can override any trait method
- Perfect for our use case!

## Architecture

```
WC_REST_Cacheable Trait
├── All caching logic
├── Default implementations
└── Overridable methods

WC_REST_Controller                RestApiControllerBase
├── use WC_REST_Cacheable         ├── use WC_REST_Cacheable
├── Existing methods              ├── Existing methods
└── register_cache_hooks()        └── register_cache_hooks()
```

## Implementation Details

### File Structure

```
/includes/rest-api/Traits/
└── trait-wc-rest-cacheable.php  (New!)

/includes/rest-api/Controllers/Version3/
└── class-wc-rest-controller.php
    ├── use WC_REST_Cacheable
    └── Calls register_cache_hooks() in constructor

/src/Internal/
└── RestApiControllerBase.php
    ├── use WC_REST_Cacheable
    └── Calls register_cache_hooks() in register()
```

### WC_REST_Controller Integration

```php
abstract class WC_REST_Controller extends WP_REST_Controller {

	use WC_REST_Cacheable;
	
	protected $namespace = 'wc/v1';
	protected $rest_base = '';
	
	public function __construct() {
		$this->register_cache_hooks();  // Trait method
	}
	
	// Rest of existing methods...
}
```

### RestApiControllerBase Integration

```php
abstract class RestApiControllerBase implements RegisterHooksInterface {

	use WC_REST_Cacheable;
	
	protected string $route_namespace = 'wc/v3';
	
	public function register() {
		add_filter( 'woocommerce_rest_api_get_rest_namespaces', ... );
		$this->register_cache_hooks();  // Trait method
	}
	
	// Rest of existing methods...
}
```

## Usage Examples

### Example 1: Legacy Controller (WC_REST_Products_Controller)

```php
class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
    
    protected $cache_enabled = true;  // From trait
    
    // Override trait methods
    protected function get_cache_key_info( $request ) {
        // Your implementation
    }
    
    protected function get_cache_hash_filters( $request ) {
        return array(
            'woocommerce_rest_prepare_product_object',
            'rest_prepare_product',
        );
    }
    
    protected function remove_non_deterministic_fields( $data ) {
        // Remove related_ids
    }
    
    protected function get_single_entity_cache_key( $entity_id ) {
        return 'wc_rest_product_' . $entity_id;
    }
    
    // extract_entity_ids() - provided by trait ✅
    // is_collection() - provided by trait ✅
    // extract_entity_id() - provided by trait ✅
}
```

### Example 2: Modern Controller (RestApiControllerBase)

```php
namespace Automattic\WooCommerce\Internal\MyFeature;

use Automattic\WooCommerce\Internal\RestApiControllerBase;

class MyEntityController extends RestApiControllerBase {
    
    protected $cache_enabled = true;  // From trait
    
    protected function get_rest_api_namespace(): string {
        return 'my-entities';
    }
    
    public function register_routes() {
        register_rest_route(
            $this->route_namespace,
            '/my-entities/(?P<id>[\d]+)',
            array(
                array(
                    'methods'  => WP_REST_Server::READABLE,
                    'callback' => fn( $request ) => $this->run( $request, 'get_entity' ),
                ),
            )
        );
    }
    
    // Override trait methods
    protected function get_cache_key_info( $request ) {
        $route = $request->get_route();
        
        if ( preg_match( '#^/wc/v3/my-entities/(\d+)$#', $route, $matches ) ) {
            return array(
                'type' => 'single',
                'key'  => 'wc_rest_my_entity_' . $matches[1],
                'id'   => (int) $matches[1],
            );
        }
        
        return null;
    }
    
    protected function get_cache_hash_filters( $request ) {
        return array( 'my_entity_prepare_filter' );
    }
    
    protected function get_single_entity_cache_key( $entity_id ) {
        return 'wc_rest_my_entity_' . $entity_id;
    }
    
    // All other caching methods provided by trait ✅
}
```

## Trait Methods Reference

### Methods Controllers Must Override

| Method | Required | Default | Purpose |
|--------|----------|---------|---------|
| `get_cache_key_info()` | ✅ Yes | Returns `null` | Define cache keys |
| `get_cache_hash_filters()` | ✅ Yes | Returns `[]` | Define tracked hooks |

### Methods Controllers Can Override

| Method | Override When | Default | Purpose |
|--------|---------------|---------|---------|
| `extract_entity_id()` | Different ID field | `$entity['id'] ?? null` | Extract single ID |
| `extract_entity_ids()` | Special logic needed | Full implementation | Extract all IDs |
| `is_collection()` | Custom format | `isset($data[0])` | Detect collections |
| `remove_non_deterministic_fields()` | Has random data | Returns data as-is | Clean for ETag |
| `get_cache_ttl()` | Different TTL | 5 minutes | Cache duration |
| `get_single_entity_cache_key()` | Custom key pattern | Returns `null` | Single entity key |
| `matches_route()` | Complex routing | Auto-detects | Route matching |

### Methods Controllers Shouldn't Override

| Method | Purpose | Note |
|--------|---------|------|
| `register_cache_hooks()` | Register WordPress hooks | Call in constructor/register() |
| `maybe_return_cached_response()` | Pre-dispatch handler | Called by WordPress |
| `maybe_cache_response()` | Post-dispatch handler | Called by WordPress |
| `generate_cache_hash()` | Generate hook-based hash | Core caching logic |
| `invalidate_entity_cache()` | Public API for invalidation | Call when entity changes |

## Key Differences: WC_REST_Controller vs RestApiControllerBase

### WC_REST_Controller Pattern

```php
class MyController extends WC_REST_Controller {
    protected $namespace = 'wc/v3';
    protected $rest_base = 'my-endpoint';
    
    public function __construct() {
        $this->register_cache_hooks();  // ✅ Called here
    }
}
```

**Route matching**:
```php
protected function matches_route( $route ) {
    // Uses $this->namespace and $this->rest_base
    $expected = '/' . $this->namespace . '/' . $this->rest_base;
    return strpos( $route, $expected ) === 0;
}
```

### RestApiControllerBase Pattern

```php
class MyController extends RestApiControllerBase {
    protected $route_namespace = 'wc/v3';
    
    public function register() {
        add_filter( 'woocommerce_rest_api_get_rest_namespaces', ... );
        $this->register_cache_hooks();  // ✅ Called here
    }
}
```

**Route matching**:
```php
protected function matches_route( $route ) {
    // Uses $this->route_namespace
    // Override this method with your specific route pattern
    return strpos( $route, '/' . $this->route_namespace . '/my-endpoint' ) !== false;
}
```

## Trait Method Override Examples

### Override for Custom ID Field

```php
class MyController extends RestApiControllerBase {
    use WC_REST_Cacheable;  // Already included via parent
    
    // Entity uses 'entity_id' instead of 'id'
    protected function extract_entity_id( $entity ) {
        return $entity['entity_id'] ?? null;
    }
    
    // extract_entity_ids() automatically uses extract_entity_id() ✅
}
```

### Override for Parent Relationship Tracking

```php
class VariationsController extends WC_REST_Controller {
    
    // Need to track both variation ID and parent product ID
    protected function extract_entity_ids( $data ) {
        $ids = array();
        
        if ( $this->is_collection( $data ) ) {
            foreach ( $data as $item ) {
                $id = $this->extract_entity_id( $item );
                if ( null !== $id ) {
                    $ids[] = $id;
                    
                    // Special: Also track parent
                    if ( isset( $item['parent_id'] ) ) {
                        $ids[] = $item['parent_id'];
                    }
                }
            }
        } else {
            $id = $this->extract_entity_id( $data );
            if ( null !== $id ) {
                $ids[] = $id;
                
                if ( isset( $data['parent_id'] ) ) {
                    $ids[] = $data['parent_id'];
                }
            }
        }
        
        return array_unique( array_filter( $ids ) );
    }
}
```

### Override for Custom Collection Detection

```php
class MyController extends RestApiControllerBase {
    
    // API returns paginated format: { items: [...], total: 100 }
    protected function is_collection( $data ) {
        return isset( $data['items'] ) && is_array( $data['items'] );
    }
    
    protected function extract_entity_ids( $data ) {
        if ( $this->is_collection( $data ) ) {
            // Extract from paginated format
            $ids = array();
            foreach ( $data['items'] as $item ) {
                $id = $this->extract_entity_id( $item );
                if ( null !== $id ) {
                    $ids[] = $id;
                }
            }
            return array_unique( array_filter( $ids ) );
        }
        
        // Single entity
        $id = $this->extract_entity_id( $data );
        return $id ? array( $id ) : array();
    }
}
```

## Benefits of Trait Approach

### ✅ No Code Duplication
```php
// Same code works for both base classes
WC_REST_Controller     → use WC_REST_Cacheable;
RestApiControllerBase  → use WC_REST_Cacheable;
```

### ✅ Easy Maintenance
```php
// Update caching logic in one place
trait-wc-rest-cacheable.php
↓
All controllers benefit
```

### ✅ Flexible Overriding
```php
// Override only what you need
class MyController {
    use WC_REST_Cacheable;
    
    protected function get_cache_ttl() {
        return 10 * MINUTE_IN_SECONDS;  // Override just this
    }
    
    // All other methods use trait defaults
}
```

### ✅ Composition Over Inheritance
```php
// Controllers can use multiple traits
class MyController extends RestApiControllerBase {
    use WC_REST_Cacheable;
    use SomeOtherTrait;
    use AnotherTrait;
}
```

### ✅ Backward Compatible
```php
// Existing controllers without trait still work
class OldController extends WC_REST_Controller {
    // Doesn't set $cache_enabled = true
    // Caching not active ✅
}
```

## Testing Trait Overrides

### Unit Test Example

```php
class WC_REST_Cacheable_Test extends WP_UnitTestCase {
    
    public function test_trait_method_can_be_overridden() {
        $controller = new class extends WC_REST_Controller {
            use WC_REST_Cacheable;
            
            protected function get_cache_ttl() {
                return 999;  // Override
            }
        };
        
        $this->assertEquals( 999, $controller->get_cache_ttl() );
    }
    
    public function test_default_extract_entity_id() {
        $controller = new class extends WC_REST_Controller {
            use WC_REST_Cacheable;
        };
        
        $reflection = new ReflectionMethod( $controller, 'extract_entity_id' );
        $reflection->setAccessible( true );
        
        $result = $reflection->invoke( $controller, array( 'id' => 123 ) );
        $this->assertEquals( 123, $result );
    }
    
    public function test_override_extract_entity_id() {
        $controller = new class extends WC_REST_Controller {
            use WC_REST_Cacheable;
            
            protected function extract_entity_id( $entity ) {
                return $entity['custom_id'] ?? null;
            }
        };
        
        $reflection = new ReflectionMethod( $controller, 'extract_entity_id' );
        $reflection->setAccessible( true );
        
        $result = $reflection->invoke( $controller, array( 'custom_id' => 456 ) );
        $this->assertEquals( 456, $result );
    }
}
```

## Trait Conflict Resolution

If multiple traits provide the same method, use `insteadof`:

```php
class MyController extends RestApiControllerBase {
    use WC_REST_Cacheable;
    use SomeOtherTrait {
        SomeOtherTrait::matches_route insteadof WC_REST_Cacheable;
        WC_REST_Cacheable::generate_cache_hash as generateHash;
    }
    
    // Can still call both methods
    protected function my_method() {
        $this->matches_route( $route );      // From SomeOtherTrait
        $this->generateHash( $request );     // From WC_REST_Cacheable (aliased)
    }
}
```

(Note: This is unlikely to be needed in practice)

## Migration Guide

### For WC_REST_Controller Descendants

**Before** (methods in base class):
```php
class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
    // Caching methods inherited from WC_REST_Controller
}
```

**After** (methods in trait):
```php
class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
    // Caching methods from WC_REST_Cacheable trait (via parent)
}
```

**No changes needed!** ✅ Parent already uses trait.

### For RestApiControllerBase Descendants

**Before** (no caching):
```php
class MyController extends RestApiControllerBase {
    // No caching available
}
```

**After** (caching available):
```php
class MyController extends RestApiControllerBase {
    
    protected $cache_enabled = true;
    
    protected function get_cache_key_info( $request ) {
        // Implement caching
    }
    
    protected function get_cache_hash_filters( $request ) {
        // Specify hooks
    }
    
    // All other methods provided by trait ✅
}
```

## Files Modified

1. **`trait-wc-rest-cacheable.php`** (NEW)
   - Contains all caching logic
   - ~200 lines of shared code

2. **`class-wc-rest-controller.php`**
   - Added: `use WC_REST_Cacheable;`
   - Added: `register_cache_hooks()` call in constructor
   - Removed: ~200 lines of caching methods
   - Net change: **~198 lines removed** ✅

3. **`RestApiControllerBase.php`**
   - Added: `use WC_REST_Cacheable;`
   - Added: `register_cache_hooks()` call in register()
   - Net change: **+2 lines** ✅

## Advantages Over Inheritance

### Why Not Create a CacheableRestController Base Class?

```php
// Hypothetical alternative (NOT used)
abstract class WC_REST_Cacheable_Controller extends WC_REST_Controller {
    // Caching methods here
}

class WC_REST_Products_Controller extends WC_REST_Cacheable_Controller {
    // Problem: Can't extend both Cacheable_Controller and Products_V2_Controller!
}
```

**Problem**: PHP only supports single inheritance.

### Trait Solves This

```php
// ✅ Trait approach
class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
    use WC_REST_Cacheable;  // Composition!
    
    // Can extend Products_V2_Controller AND get caching ✅
}
```

**Benefit**: Composition over inheritance - mix-in functionality without inheritance conflicts.

## Best Practices

### 1. Always Call `register_cache_hooks()`

```php
// WC_REST_Controller pattern
public function __construct() {
    $this->register_cache_hooks();
    // Other initialization
}

// RestApiControllerBase pattern
public function register() {
    parent::register();  // If extending another controller
    $this->register_cache_hooks();
}
```

### 2. Override Only What's Needed

```php
// ✅ Good - minimal overrides
class MyController {
    use WC_REST_Cacheable;
    
    protected $cache_enabled = true;
    protected function get_cache_key_info( $request ) { /* ... */ }
    protected function get_cache_hash_filters( $request ) { /* ... */ }
}

// ❌ Bad - unnecessary overrides
class MyController {
    use WC_REST_Cacheable;
    
    protected function extract_entity_id( $entity ) {
        return $entity['id'] ?? null;  // Same as trait default!
    }
}
```

### 3. Document Overrides

```php
/**
 * Override cache TTL for this controller.
 *
 * Extended to 15 minutes because entity data is very stable.
 */
protected function get_cache_ttl() {
    return 15 * MINUTE_IN_SECONDS;
}
```

## Common Questions

### Q: Can I use the trait without a base class?

**A**: Yes! But you need to call `register_cache_hooks()` yourself:

```php
class StandaloneController extends WP_REST_Controller {
    use WC_REST_Cacheable;
    
    public function __construct() {
        $this->register_cache_hooks();  // Required!
    }
}
```

### Q: What if my controller already has a constructor?

**A**: Call the trait method and your logic:

```php
class MyController extends WC_REST_Controller {
    
    public function __construct() {
        parent::__construct();  // Calls register_cache_hooks()
        
        // Your additional initialization
        add_action( 'my_custom_action', ... );
    }
}
```

### Q: Can I disable caching at runtime?

**A**: Yes, via the property:

```php
class MyController extends WC_REST_Controller {
    
    public function __construct() {
        parent::__construct();
        
        // Conditionally enable caching
        if ( apply_filters( 'my_enable_caching', true ) ) {
            $this->cache_enabled = true;
        }
    }
}
```

### Q: What happens if I forget to call `register_cache_hooks()`?

**A**: Caching won't work (hooks not registered), but everything else works normally. No errors.

## Conclusion

The trait approach provides:

✅ **Zero code duplication** across both base classes  
✅ **Full override capability** for all methods  
✅ **Backward compatibility** (caching is opt-in)  
✅ **Easy adoption** for new controllers  
✅ **Flexible composition** (can combine with other traits)  

This is the standard PHP pattern for sharing behavior across unrelated inheritance hierarchies.
