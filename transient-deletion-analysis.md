# Analysis: Code Paths That Delete `wc_var_prices_` Transients

## Overview

The `wc_var_prices_(product_id)` transients can be deleted before their regular expiration period (30 days) through several code paths. Here's a comprehensive analysis of all the scenarios where this happens.

## Primary Deletion Method

### `ProductUtil::delete_product_specific_transients()`

**File**: `plugins/woocommerce/src/Internal/Utilities/ProductUtil.php`  
**Lines**: 18-46

```php
public function delete_product_specific_transients( $product_or_id ) {
    // ... product ID resolution logic ...
    
    $product_specific_transient_names = array(
        'wc_product_children_',
        'wc_var_prices_',  // ← This is our target transient
        'wc_related_',
        'wc_child_has_weight_',
        'wc_child_has_dimensions_',
    );

    foreach ( $product_specific_transient_names as $transient ) {
        delete_transient( $transient . $product_id );
        if ( $parent_id ) {
            delete_transient( $transient . $parent_id );
        }
    }
}
```

**Key Point**: This method deletes `wc_var_prices_` transients for both the product and its parent (if it's a variation).

## Code Paths That Trigger Transient Deletion

### 1. **Product Updates** (Most Common)

**File**: `plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php`  
**Method**: `clear_caches()` (lines 1098-1106)

```php
protected function clear_caches( &$product ) {
    wc_delete_product_transients( $product->get_id() );
    if ( $product->get_parent_id( 'edit' ) ) {
        wc_delete_product_transients( $product->get_parent_id( 'edit' ) );
        // ... additional cache invalidation
    }
}
```

**Triggered by**:
- Product save operations
- Product updates via REST API
- Product updates via admin interface

### 2. **Variation Updates** (Very Common)

**File**: `plugins/woocommerce/includes/data-stores/class-wc-product-variation-data-store-cpt.php`  
**Lines**: 172, 277

```php
// In create() method
$this->clear_caches( $product );

// In update() method  
$this->clear_caches( $product );
```

**Triggered by**:
- Creating new variations
- Updating existing variations
- Bulk variation operations
- Variation price changes
- Variation attribute changes

### 3. **REST API Operations**

**File**: `plugins/woocommerce/includes/rest-api/Controllers/Version2/class-wc-rest-products-v2-controller.php`  
**Method**: `clear_transients()` (line 1549)

```php
public function clear_transients( $object ) {
    wc_delete_product_transients( $object->get_id() );
    wp_cache_delete( 'product-' . $object->get_id(), 'products' );
}
```

**Triggered by**:
- REST API product updates
- REST API variation updates
- Bulk operations via REST API

### 4. **Bulk Operations**

**File**: `plugins/woocommerce/includes/class-wc-ajax.php`  
**Line**: 2976

```php
do_action( 'woocommerce_bulk_edit_variations', $bulk_action, $data, $product_id, $variations );
WC_Product_Variable::sync( $product_id );
wc_delete_product_transients( $product_id );
```

**Triggered by**:
- Bulk edit variations in admin
- Bulk price updates
- Bulk attribute changes

### 5. **Post Status Changes**

**File**: `plugins/woocommerce/includes/class-wc-post-data.php`  
**Lines**: 340, 347

```php
case 'product':
    $parent_id = wp_get_post_parent_id( $id );
    if ( $parent_id ) {
        wc_delete_product_transients( $parent_id );
    }
    break;
case 'product_variation':
    wc_delete_product_transients( wp_get_post_parent_id( $id ) );
    break;
```

**Triggered by**:
- Product status changes (draft, published, private, etc.)
- Variation status changes
- Product deletion/trashing

### 6. **System Status Tools**

**File**: `plugins/woocommerce/includes/rest-api/Controllers/Version2/class-wc-rest-system-status-tools-v2-controller.php`  
**Line**: 464

```php
case 'clear_transients':
    wc_delete_product_transients();
    wc_delete_shop_order_transients();
    // ... other transient deletions
```

**Triggered by**:
- Admin tools → System Status → Tools → "Clear transients"
- Manual cache clearing operations

### 7. **Sales Start/End Events**

**File**: `plugins/woocommerce/includes/wc-product-functions.php`  
**Lines**: 552, 576

```php
// In wc_products_starting_sales()
$product_util->delete_product_specific_transients( $product ? $product : $product_id );

// In wc_products_ending_sales()  
$product_util->delete_product_specific_transients( $product ? $product : $product_id );
```

**Triggered by**:
- Scheduled sales starting
- Scheduled sales ending
- Cron job executions

### 8. **Hook-Based Deletions**

**File**: `plugins/woocommerce/includes/wc-product-functions.php`  
**Line**: 149

```php
do_action( 'woocommerce_delete_product_transients', $post_id );
```

**Triggered by**:
- Any code that hooks into `woocommerce_delete_product_transients`
- Third-party plugins
- Custom code

## Impact on Optimization

### **High Impact Scenarios**

1. **Variation Updates**: Every time a variation is updated, the parent product's `wc_var_prices_` transient is deleted
2. **Bulk Operations**: Bulk editing variations clears transients for the entire product
3. **REST API Updates**: API-based updates clear transients immediately

### **Medium Impact Scenarios**

1. **Product Updates**: General product updates clear transients
2. **Status Changes**: Product status changes clear transients
3. **Sales Events**: Scheduled sales clear transients

### **Low Impact Scenarios**

1. **System Tools**: Manual cache clearing (admin-initiated)
2. **Hook-Based**: Custom code or plugin-initiated

## Optimization Considerations

### **Cache Invalidation Frequency**

The `wc_var_prices_` transients are deleted much more frequently than their 30-day expiration period would suggest. This means:

1. **Optimization Benefits Are Reduced**: If transients are frequently deleted, the optimization benefits are limited
2. **Cache Misses Are Common**: Products with active variations will have their transients deleted frequently
3. **Performance Impact**: Frequent cache invalidation reduces the effectiveness of caching

### **Recommendations**

1. **Monitor Deletion Frequency**: Track how often `wc_var_prices_` transients are deleted for your products
2. **Consider Cache Warming**: Implement cache warming strategies for frequently accessed products
3. **Optimize Variation Updates**: Batch variation updates to reduce transient deletion frequency
4. **Use Object Caching**: Consider using persistent object caching (Redis, Memcached) for better performance

### **Testing Strategy**

To measure the impact of transient deletions on your optimization:

```php
// Add this to track transient deletions
add_action( 'woocommerce_delete_product_transients', function( $product_id ) {
    error_log( "Transient deleted for product ID: {$product_id} at " . current_time( 'Y-m-d H:i:s' ) );
});
```

## Conclusion

The `wc_var_prices_` transients are deleted much more frequently than their expiration period suggests, primarily due to:

1. **Variation updates** (most common)
2. **Product updates** via admin or API
3. **Bulk operations**
4. **Status changes**
5. **System maintenance**

This frequent deletion reduces the effectiveness of the optimization, but the optimization is still valuable for:
- Products with infrequently updated variations
- Products accessed more often than they're updated
- Scenarios where the optimization conditions are met

The key is to understand your specific use case and measure the actual cache hit rates and deletion frequency for your products.