# Code Path Analysis: When Variable Product Prices Are Identical

## Overview

This document provides a detailed analysis of the exact code paths I traced to determine when `WC_Product_Variable::get_variation_prices($for_display)` returns identical results for both `$for_display = true` and `$for_display = false`.

## Code Path Flow

### 1. Entry Point: `WC_Product_Variable::get_variation_prices()`

**File**: `plugins/woocommerce/includes/class-wc-product-variable.php`  
**Lines**: 99-107

```php
public function get_variation_prices( $for_display = false ) {
    $prices = $this->data_store->read_price_data( $this, $for_display );

    foreach ( $prices as $price_key => $variation_prices ) {
        $prices[ $price_key ] = $this->sort_variation_prices( $variation_prices );
    }

    return $prices;
}
```

**Analysis**: The method simply calls `read_price_data()` and sorts the results. The `$for_display` parameter is passed through unchanged.

### 2. Data Store Method: `WC_Product_Variable_Data_Store_CPT::read_price_data()`

**File**: `plugins/woocommerce/includes/data-stores/class-wc-product-variable-data-store-cpt.php`  
**Lines**: 268-277

```php
public function read_price_data( &$product, $for_display = false ) {
    $transient_name    = 'wc_var_prices_' . $product->get_id();
    $transient_version = WC_Cache_Helper::get_transient_version( 'product' );
    $price_hash        = $this->get_price_hash( $product, $for_display );
    // ... rest of method
}
```

**Analysis**: The method generates a cache key using `get_price_hash()` which includes the `$for_display` parameter.

### 3. Hash Generation: `get_price_hash()`

**File**: `plugins/woocommerce/includes/data-stores/class-wc-product-variable-data-store-cpt.php`  
**Lines**: 410-421

```php
protected function get_price_hash( &$product, $for_display = false ) {
    global $wp_filter;

    $price_hash = array( false );

    if ( $for_display && wc_tax_enabled() ) {
        $price_hash = array(
            get_option( 'woocommerce_tax_display_shop', 'excl' ),
            WC_Tax::get_rates(),
            empty( WC()->customer ) ? false : WC()->customer->is_vat_exempt(),
        );
    }
    // ... rest of method
}
```

**Key Finding**: The hash only differs when **BOTH** conditions are true:
1. `$for_display = true`
2. `wc_tax_enabled()` returns `true`

### 4. Tax Enablement Check: `wc_tax_enabled()`

**File**: `plugins/woocommerce/includes/wc-conditional-functions.php`  
**Lines**: 396-398

```php
function wc_tax_enabled() {
    return apply_filters( 'wc_tax_enabled', get_option( 'woocommerce_calc_taxes' ) === 'yes' );
}
```

**Analysis**: Tax is enabled when `woocommerce_calc_taxes` option is set to `'yes'`.

### 5. Price Calculation Logic

**File**: `plugins/woocommerce/includes/data-stores/class-wc-product-variable-data-store-cpt.php`  
**Lines**: 323-369

```php
// If we are getting prices for display, we need to account for taxes.
if ( $for_display ) {
    if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
        $price = '' === $price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
        $regular_price = '' === $regular_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
        $sale_price = '' === $sale_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
    } else {
        $price = '' === $price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
        $regular_price = '' === $regular_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
        $sale_price = '' === $sale_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
    }
}
```

**Analysis**: Tax calculations only occur when `$for_display = true`. When `$for_display = false`, prices are used as-is.

## Tax Calculation Functions Analysis

### 6. `wc_get_price_including_tax()` Function

**File**: `plugins/woocommerce/includes/wc-product-functions.php`  
**Lines**: 1201-1217

```php
if ( $product->is_taxable() ) {
    if ( ! wc_prices_include_tax() ) {
        // If the customer is exempt from VAT, set tax total to 0.
        if ( ! empty( WC()->customer ) && WC()->customer->get_is_vat_exempt() ) {
            $taxes_total = 0.00;
        } else {
            $tax_rates = WC_Tax::get_rates( $product->get_tax_class() );
            $taxes = WC_Tax::calc_tax( $line_price, $tax_rates, false );
            // ... calculate taxes
        }
        $return_price = NumberUtil::round( $line_price + $taxes_total, wc_get_price_decimals() );
    } else {
        // ... handle tax-inclusive prices
    }
}
```

### 7. `wc_get_price_excluding_tax()` Function

**File**: `plugins/woocommerce/includes/wc-product-functions.php`  
**Lines**: 1291-1304

```php
if ( $product->is_taxable() && wc_prices_include_tax() ) {
    // ... remove taxes from tax-inclusive prices
    $return_price = $line_price - array_sum( $remove_taxes );
} else {
    $return_price = $line_price;
}
```

### 8. `wc_prices_include_tax()` Function

**File**: `plugins/woocommerce/includes/wc-conditional-functions.php`  
**Lines**: 420-422

```php
function wc_prices_include_tax() {
    return wc_tax_enabled() && apply_filters( 'woocommerce_prices_include_tax', get_option( 'woocommerce_prices_include_tax' ) === 'yes' );
}
```

### 9. `is_taxable()` Method

**File**: `plugins/woocommerce/includes/abstracts/abstract-wc-product.php`  
**Lines**: 1775-1784

```php
public function is_taxable() {
    return apply_filters( 'woocommerce_product_is_taxable', $this->get_tax_status() === ProductTaxStatus::TAXABLE && wc_tax_enabled(), $this );
}
```

## Conditions for Identical Results

Based on the code path analysis, the calculations return identical results when **ANY** of the following conditions are met:

### Condition 1: Tax is Disabled
**Code Path**: `wc_tax_enabled()` → `get_option('woocommerce_calc_taxes') !== 'yes'`

**Result**: When tax is disabled, the `if ( $for_display && wc_tax_enabled() )` condition in `get_price_hash()` is never true, so both `$for_display = true` and `$for_display = false` generate the same hash and use the same cached data.

### Condition 2: Product is Not Taxable
**Code Path**: `is_taxable()` → `get_tax_status() !== ProductTaxStatus::TAXABLE || !wc_tax_enabled()`

**Result**: When `is_taxable()` returns `false`, the tax calculation functions (`wc_get_price_including_tax()` and `wc_get_price_excluding_tax()`) return the original price unchanged, making both calculations identical.

### Condition 3: No Tax Rates Exist
**Code Path**: `WC_Tax::get_rates($product->get_tax_class())` returns empty array

**Result**: When there are no tax rates for the product's tax class, `WC_Tax::calc_tax()` returns zero taxes, making both calculations identical.

### Condition 4: Customer is VAT Exempt and Prices Include Tax
**Code Path**: `WC()->customer->get_is_vat_exempt() === true && wc_prices_include_tax() === true`

**Result**: In `wc_get_price_including_tax()`, when the customer is VAT exempt, `$taxes_total = 0.00` (line 1205), so the function returns the original price. In `wc_get_price_excluding_tax()`, when prices include tax, it removes taxes, but if the customer is exempt, the tax calculation results in zero, making both functions return the same value.

### Condition 5: Prices Already Include Tax and Display Setting is 'incl'
**Code Path**: `get_option('woocommerce_tax_display_shop') === 'incl' && wc_prices_include_tax() === true`

**Result**: When prices already include tax and the display setting is 'incl', `wc_get_price_including_tax()` returns the original price (no change needed), and `wc_get_price_excluding_tax()` would remove taxes, but since the customer context might be the same, both could return identical results.

## Code Locations Summary

| Condition | Primary Code Location | Key Function/Method | Line Numbers |
|-----------|----------------------|-------------------|--------------|
| Tax Disabled | `wc-conditional-functions.php` | `wc_tax_enabled()` | 396-398 |
| Product Not Taxable | `abstract-wc-product.php` | `is_taxable()` | 1775-1784 |
| No Tax Rates | `WC_Tax::get_rates()` | `get_rates()` | N/A |
| VAT Exempt Customer | `wc-product-functions.php` | `wc_get_price_including_tax()` | 1204-1205 |
| Tax Display Setting | `class-wc-product-variable-data-store-cpt.php` | `read_price_data()` | 325-346 |

## Verification Method

To verify these conditions in practice, you can use this code:

```php
function debug_price_identical_conditions( $product ) {
    $conditions = array(
        'tax_enabled' => wc_tax_enabled(),
        'product_taxable' => $product->is_taxable(),
        'tax_rates_exist' => !empty( WC_Tax::get_rates( $product->get_tax_class() ) ),
        'customer_vat_exempt' => !empty( WC()->customer ) && WC()->customer->get_is_vat_exempt(),
        'prices_include_tax' => wc_prices_include_tax(),
        'tax_display_shop' => get_option( 'woocommerce_tax_display_shop', 'excl' ),
    );
    
    $prices_false = $product->get_variation_prices( false );
    $prices_true = $product->get_variation_prices( true );
    $identical = $this->are_prices_identical( $prices_false, $prices_true );
    
    return array(
        'conditions' => $conditions,
        'prices_identical' => $identical,
        'optimization_possible' => $identical
    );
}
```

This analysis provides the exact code paths and conditions that determine when variable product price calculations return identical results, enabling the optimization opportunity.