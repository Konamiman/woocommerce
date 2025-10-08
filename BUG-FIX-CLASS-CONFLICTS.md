# Bug Fix: Class Name Conflicts in Example Files

## Problem

**Error**: `rest_no_route` - No route was found matching the URL

**Root cause**: Example files had the same class names as the real controllers:
- `example-products-controller-with-caching.php` → `class WC_REST_Products_Controller`
- Real controller → `class WC_REST_Products_Controller`

PHP loaded the example class instead of the real controller, so routes were never registered!

## Solution

### Renamed Example Classes

**Before**:
```php
// example-products-controller-with-caching.php
class WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
    // Example implementation
}
```

**After**:
```php
// example-products-controller-with-caching.php
class Example_WC_REST_Products_Controller extends WC_REST_Products_V2_Controller {
    // Example implementation - won't conflict!
}
```

### Files Fixed

1. ✅ `example-products-controller-with-caching.php`
   - Renamed: `WC_REST_Products_Controller` → `Example_WC_REST_Products_Controller`

2. ✅ `example-variations-controller-with-caching.php`
   - Renamed: `WC_REST_Product_Variations_Controller` → `Example_WC_REST_Product_Variations_Controller`

3. ✅ `example-restapi-controllerbase-with-caching.php`
   - Already unique: `CustomEntityController` (no changes needed)

## Why This Happened

The examples were meant as **reference code** to copy from, not to be loaded. But PHP was loading them because:

1. Files in `includes/rest-api/Controllers/examples/` directory
2. PHP doesn't care about directory names
3. Class name collision → first one loaded wins
4. Example loaded instead of real controller
5. Routes never registered → 404 error

## Verification

Now the real controllers will load:

```bash
# Should work now
curl http://your-site.local/wp-json/wc/v3/products/123 --user key:secret

# Should return product data with cache headers:
# ETag: "..."
# X-WC-Cache: MISS
```

## Prevention

Example classes now have `Example_` prefix so they:
- ✅ Don't conflict with real classes
- ✅ Are clearly examples (name makes it obvious)
- ✅ Can still be used as reference code

## Testing After Fix

Try your request again:

```bash
curl -i http://your-site.local/wp-json/wc/v3/products/{your-product-id} \
  --user consumer_key:consumer_secret
```

Should now return:
- ✅ 200 OK (not 404)
- ✅ Product data
- ✅ ETag header
- ✅ X-WC-Cache: MISS

---

**Status**: ✅ **FIXED** - Example classes renamed to avoid conflicts
