# Per-Controller Trait Approach

## Design Decision: Controllers Use Trait Directly

### Architecture

**Each controller that wants caching explicitly uses the trait**:

```php
use Automattic\WooCommerce\Internal\Traits\RestApiCache;

class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
    use RestApiCache;  // ← Explicit opt-in
    
    protected $cache_enabled = true;
    
    public function __construct() {
        parent::__construct();
        $this->register_cache_hooks();  // ← Register hooks
    }
    
    // Implement caching methods...
}
```

### Why Not in Base Classes?

**We considered** putting the trait in `WC_REST_Controller`:

```php
// NOT USED - would add overhead to all controllers
abstract class WC_REST_Controller extends WP_REST_Controller {
    use RestApiCache;
    
    public function __construct() {
        $this->register_cache_hooks();  // ← Called for ALL controllers
    }
}
```

**Problem**: Every controller (Orders, Customers, Coupons, etc.) would:
- ✗ Call `register_cache_hooks()` in constructor
- ✗ Call `is_cache_enabled()` check
- ✗ Have unused caching methods in memory
- ✗ Slight overhead even with `$cache_enabled = false`

### Per-Controller Approach Benefits

#### ✅ 1. Zero Overhead for Non-Cached Controllers

**Orders controller** (no caching):
```php
class WC_REST_Orders_Controller extends WC_REST_Orders_V2_Controller {
    // No trait
    // No caching code
    // No overhead
    // Zero impact ✅
}
```

**Products controller** (with caching):
```php
class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
    use RestApiCache;  // Only here!
    
    public function __construct() {
        parent::__construct();
        $this->register_cache_hooks();  // Only for products!
    }
}
```

#### ✅ 2. Explicit and Clear

```php
// Looking at a controller file, it's immediately clear:
use RestApiCache;  // ← This controller has caching

// vs base class approach:
// ← Is caching enabled? Need to check parent class and $cache_enabled property
```

#### ✅ 3. No Hook Registration Overhead

**Before** (if trait was in base class):
```
All 20+ controllers instantiated
↓
All 20+ call register_cache_hooks()
↓
All 20+ check is_cache_enabled()
↓
Only 2 actually register hooks
↓
18 wasted method calls per request initialization
```

**After** (per-controller trait):
```
All 20+ controllers instantiated
↓
Only 2 have the trait
↓
Only 2 call register_cache_hooks()
↓
2 register hooks
↓
Zero wasted calls ✅
```

#### ✅ 4. Easier to Review

```php
git diff class-wc-rest-products-controller.php

+ use RestApiCache;
+ public function __construct() { ... }
+ protected function get_cache_key_info() { ... }

// Reviewer can see: "This controller added caching" ✅
```

vs

```php
git diff class-wc-rest-controller.php

+ use RestApiCache;  // In base class

// Reviewer: "Which controllers does this affect?" 🤔
// Need to check all descendants
```

#### ✅ 5. Gradual Rollout

```php
// Phase 1: Enable for Products
class WC_REST_Products_Controller {
    use RestApiCache;  ✅
}

// Phase 2: Enable for Orders (later)
class WC_REST_Orders_Controller {
    use RestApiCache;  ← Add when ready
}

// Phase 3: Enable for Customers (even later)
class WC_REST_Customers_Controller {
    // No trait yet - not ready
}
```

**Easy to track** which controllers have caching enabled.

#### ✅ 6. No Accidental Activation

**Base class approach risk**:
```php
// Base class has trait
abstract class WC_REST_Controller {
    use RestApiCache;
}

// Developer creates new controller
class WC_REST_MyEntity_Controller extends WC_REST_Controller {
    // Oops! Accidentally set $cache_enabled = true somewhere
    // Caching active but not properly implemented
    // Bugs! 🐛
}
```

**Per-controller approach**:
```php
// Developer creates new controller
class WC_REST_MyEntity_Controller extends WC_REST_Controller {
    // No trait = no caching
    // Can't accidentally enable
    // Safe! ✅
}
```

## Implementation Pattern

### Step-by-Step for New Controllers

```php
// 1. Use the trait
use Automattic\WooCommerce\Internal\Traits\RestApiCache;

class WC_REST_Orders_Controller extends WC_REST_Orders_V2_Controller {
    
    // 2. Add the trait
    use RestApiCache;
    
    // 3. Add constructor
    public function __construct() {
        parent::__construct();
        $this->register_cache_hooks();
    }
    
    // 4. Enable caching
    protected $cache_enabled = true;
    
    // 5. Implement required methods
    protected function get_cache_key_info( $request ) {
        // Your implementation
    }
    
    protected function get_cache_hash_filters( $request ) {
        return array( 'woocommerce_rest_prepare_order_object' );
    }
    
    // Done!
}
```

## Controllers Currently Enabled

| Controller | Status | Endpoints |
|-----------|--------|-----------|
| **WC_REST_Products_Controller** | ✅ Enabled | 3 endpoints |
| **WC_REST_Product_Variations_Controller** | ✅ Enabled | 2 endpoints |
| WC_REST_Orders_Controller | ⏸️ Not yet | - |
| WC_REST_Customers_Controller | ⏸️ Not yet | - |
| WC_REST_Coupons_Controller | ⏸️ Not yet | - |
| ... (all others) | ⏸️ Not yet | - |

**Total enabled**: 2 controllers, 5 endpoints  
**Total overhead for others**: 0 ✅

## Performance Impact

### For Non-Cached Controllers

**With base class approach**:
- Constructor calls `register_cache_hooks()`
- Method checks `is_cache_enabled()` → false
- Returns early
- Cost: ~1-2 microseconds per controller
- × 20 controllers = ~20-40 microseconds

**With per-controller approach**:
- No trait in controller
- No method calls
- Cost: 0 microseconds ✅

### For Cached Controllers

**Identical in both approaches**:
- Trait provides caching logic
- Hooks registered
- Cache works

## Migration Path

### Adding Caching to a New Controller

**Before**:
```php
class WC_REST_Orders_Controller extends WC_REST_Orders_V2_Controller {
    // No caching
}
```

**After** (just 3 additions):
```php
use Automattic\WooCommerce\Internal\Traits\RestApiCache;  // 1. Import

class WC_REST_Orders_Controller extends WC_REST_Orders_V2_Controller {
    use RestApiCache;  // 2. Use trait
    
    protected $cache_enabled = true;
    
    public function __construct() {  // 3. Add constructor
        parent::__construct();
        $this->register_cache_hooks();
    }
    
    // 4. Implement methods (see examples)
}
```

## Code Review Benefits

### Clear Diff

```diff
+ use Automattic\WooCommerce\Internal\Traits\RestApiCache;

  class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
  
+   use RestApiCache;
+   
+   protected $cache_enabled = true;
+   
+   public function __construct() {
+       parent::__construct();
+       $this->register_cache_hooks();
+   }
+
+   protected function get_cache_key_info( $request ) {
+       // Implementation
+   }
```

**Reviewer sees**:
- ✅ This controller added caching
- ✅ All changes in one file
- ✅ Clear opt-in pattern

### vs Base Class Diff

```diff
# In base class
+ use RestApiCache;
+ $this->register_cache_hooks();

# In product controller  
+ $cache_enabled = true;
+ protected function get_cache_key_info() { ... }

# Reviewer: "Wait, which controllers does this affect?"
# Need to check all descendants of base class
```

## Summary

### Per-Controller Approach

✅ **Zero overhead** for controllers without caching  
✅ **Explicit opt-in** - clear which controllers have caching  
✅ **Easy to review** - all changes in one file  
✅ **Gradual rollout** - enable one controller at a time  
✅ **Safe** - can't accidentally enable caching  
✅ **Flexible** - works with any controller type  

### Implementation Checklist

To add caching to a controller:
- [ ] Import trait: `use Automattic\WooCommerce\Internal\Traits\RestApiCache;`
- [ ] Use trait: `use RestApiCache;` in class
- [ ] Add constructor calling `register_cache_hooks()`
- [ ] Set `$cache_enabled = true`
- [ ] Implement `get_cache_key_info()`
- [ ] Implement `get_cache_hash_filters()`
- [ ] Optionally implement other methods

That's it! ~20 lines to add full caching support.

## Comparison Table

| Aspect | Base Class Approach | Per-Controller Approach ✅ |
|--------|--------------------|-----------------------------|
| **Overhead for non-cached** | 1-2 μs per controller | 0 μs |
| **Explicit opt-in** | Check property | Check trait usage |
| **Code clarity** | Inherited | Explicit in file |
| **Review complexity** | Need to check hierarchy | Single file |
| **Accidental enablement** | Possible | Not possible |
| **Gradual rollout** | Via property | Via trait usage |

**Winner**: Per-controller approach for cleaner, more explicit code!
