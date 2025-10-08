# Method Naming Conventions

## Hook Callback Methods

Methods that are registered as WordPress hook callbacks follow the naming convention: `handle_{hook_name}`

### Hook Callbacks in RestApiCache Trait

| Method Name | Hook | Access | Annotation |
|-------------|------|--------|------------|
| `handle_rest_pre_dispatch()` | `rest_pre_dispatch` | public | @internal |
| `handle_rest_post_dispatch()` | `rest_post_dispatch` | public | @internal |

### Why This Convention?

**Clarity**: Immediately clear that this is a hook callback
```php
public function handle_rest_pre_dispatch( $result, $server, $request ) {
    // ↑ Name clearly indicates: "handles rest_pre_dispatch hook"
}
```

**Searchability**: Easy to find all hook handlers
```bash
grep "handle_" RestApiCache.php
# Returns all hook handler methods
```

**Consistency**: Follows WordPress core patterns
```php
// Similar to WordPress core:
handle_bulk_actions_edit_shop_order
handle_admin_notices
handle_save_post
```

### @internal Annotation

Hook callbacks are marked `@internal` because:

1. **Not part of public API** - Controllers shouldn't call these directly
2. **Called by WordPress** - Invoked automatically by hook system
3. **Implementation detail** - Internal to the caching mechanism

```php
/**
 * Handle rest_pre_dispatch filter...
 *
 * @internal  ← Indicates: don't call this directly
 *
 * @param ...
 */
public function handle_rest_pre_dispatch( $result, $server, $request ) {
```

## Public Methods

Methods that controllers should call are NOT marked @internal:

| Method | Purpose | For Controllers To |
|--------|---------|-------------------|
| `register_cache_hooks()` | Register hooks | Call in constructor |
| `invalidate_entity_cache()` | Clear cache | Call on entity update |

## Protected Methods (Override These)

Methods controllers override to customize behavior:

| Method | Override? | Purpose |
|--------|-----------|---------|
| `get_cache_key_info()` | Required | Define cache keys |
| `get_cache_hash_filters()` | Required | Specify tracked hooks |
| `is_collection()` | Optional | Detect collections |
| `extract_entity_id()` | Optional | Extract single ID |
| `extract_entity_ids()` | Optional | Extract all IDs |
| `remove_non_deterministic_fields()` | Optional | Clean for ETag |
| `get_cache_ttl()` | Optional | Cache duration |
| `get_single_entity_cache_key()` | Optional | Single entity key |
| `matches_route()` | Optional | Route matching |

## Method Visibility

### Public Methods
- `handle_rest_pre_dispatch()` - public (WordPress calls it) - @internal
- `handle_rest_post_dispatch()` - public (WordPress calls it) - @internal
- `invalidate_entity_cache()` - public (controllers call it)

### Protected Methods
All other methods are `protected`:
- Can be overridden by controllers
- Not accessible outside the class hierarchy
- Clear API boundary

## Usage Examples

### Controllers Call Public Methods

```php
class WC_REST_Products_Controller {
    use RestApiCache;
    
    public function __construct() {
        parent::__construct();
        $this->register_cache_hooks();  // ✅ Public method
    }
}

// When product updates
$controller->invalidate_entity_cache( $product_id );  // ✅ Public method
```

### Controllers Don't Call Hook Handlers

```php
// ❌ DON'T DO THIS
$controller->handle_rest_pre_dispatch( $result, $server, $request );
// This is called by WordPress, not by you!

// ✅ DO THIS
$this->register_cache_hooks();
// Let WordPress call the handlers
```

### Controllers Override Protected Methods

```php
class WC_REST_Products_Controller {
    use RestApiCache;
    
    // ✅ Override protected methods
    protected function get_cache_key_info( $request ) {
        // Your implementation
    }
    
    // ❌ Don't override @internal methods
    public function handle_rest_pre_dispatch( $result, $server, $request ) {
        // Don't do this - internal implementation
    }
}
```

## Comparison: Before vs After

### Before (Less Clear)

```php
trait RestApiCache {
    protected function register_cache_hooks() {
        add_filter( 'rest_pre_dispatch', array( $this, 'maybe_return_cached_response' ), 10, 3 );
        //                                                   ↑ Unclear it's a hook handler
    }
    
    public function maybe_return_cached_response( $result, $server, $request ) {
        // Is this a public API or internal?
    }
}
```

### After (Clear)

```php
trait RestApiCache {
    protected function register_cache_hooks() {
        add_filter( 'rest_pre_dispatch', array( $this, 'handle_rest_pre_dispatch' ), 10, 3 );
        //                                                   ↑ Clear: handles rest_pre_dispatch
    }
    
    /**
     * @internal  ← Clear: don't call directly
     */
    public function handle_rest_pre_dispatch( $result, $server, $request ) {
        // Internal implementation
    }
}
```

## Summary

**Hook handlers**:
- Named: `handle_{hook_name}`
- Visibility: public (WordPress needs access)
- Annotation: @internal (not for direct use)

**Public API**:
- `register_cache_hooks()` - call in constructor
- `invalidate_entity_cache()` - call on entity update

**Protected API**:
- All `get_*` methods - override to customize

This makes the API clear and prevents misuse! ✅
