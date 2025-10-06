# UI Performance Testing Guide for Variable Product Price Optimization

## Prerequisites

1. **Browser Developer Tools**: Chrome DevTools, Firefox Developer Tools, or Safari Web Inspector
2. **Performance Monitoring**: Browser performance profiling tools
3. **Network Monitoring**: Network tab in developer tools
4. **Test Environment**: Staging site with test data

## Test Scenarios

### 1. **Product Page Performance**

#### Setup
- Create a variable product with 50-100 variations
- Ensure the product has different pricing scenarios:
  - Some variations with sale prices
  - Some variations without sale prices
  - Mix of taxable and non-taxable variations

#### Testing Steps

1. **Clear All Caches**
   ```bash
   # Clear WooCommerce transients
   wp transient delete --all
   
   # Clear object cache
   wp cache flush
   ```

2. **Open Product Page**
   - Navigate to the variable product page
   - Open browser developer tools (F12)
   - Go to Network tab
   - Check "Disable cache" option

3. **Measure Initial Load**
   - Reload the page (Ctrl+F5)
   - Record the following metrics:
     - **Page Load Time**: Total time to load the page
     - **DOM Content Loaded**: Time until DOM is ready
     - **First Contentful Paint**: Time to first content
     - **Largest Contentful Paint**: Time to largest content
     - **Time to Interactive**: Time until page is interactive

4. **Measure AJAX Requests**
   - Look for AJAX requests related to price updates
   - Record:
     - Number of AJAX requests
     - Total AJAX request time
     - Size of responses

#### Expected Metrics to Track

| Metric | Before Optimization | After Optimization | Improvement |
|--------|-------------------|-------------------|-------------|
| Page Load Time | X.Xs | X.Xs | X% |
| AJAX Requests | X | X | X% |
| Total AJAX Time | X.Xs | X.Xs | X% |
| Price Calculation Time | X.Xs | X.Xs | X% |

### 2. **Variation Selection Performance**

#### Testing Steps

1. **Open Product Page with Developer Tools**
2. **Select Different Variations**
   - Click through 10-20 different variation combinations
   - For each selection, measure:
     - Time from click to price update
     - Number of AJAX requests triggered
     - Response time for price updates

3. **Record Performance Data**
   ```javascript
   // Add this to browser console to measure variation selection performance
   let variationTimes = [];
   let startTime;
   
   // Monitor variation changes
   jQuery(document).on('found_variation', function(event, variation) {
       if (startTime) {
           variationTimes.push(performance.now() - startTime);
           console.log('Variation change time:', performance.now() - startTime, 'ms');
       }
   });
   
   jQuery(document).on('woocommerce_variation_select_change', function() {
       startTime = performance.now();
   });
   
   // After testing, get statistics
   console.log('Average variation time:', variationTimes.reduce((a,b) => a+b) / variationTimes.length, 'ms');
   console.log('Max variation time:', Math.max(...variationTimes), 'ms');
   console.log('Min variation time:', Math.min(...variationTimes), 'ms');
   ```

### 3. **Cart Page Performance**

#### Testing Steps

1. **Add Multiple Variations to Cart**
   - Add 5-10 different variations to cart
   - Navigate to cart page
   - Measure cart page load time

2. **Update Quantities**
   - Change quantities of different variations
   - Measure time for cart updates

### 4. **Checkout Performance**

#### Testing Steps

1. **Proceed to Checkout**
   - From cart page, go to checkout
   - Measure checkout page load time
   - Test with different payment methods

## Browser-Specific Testing

### Chrome DevTools

1. **Performance Tab**
   - Record performance while interacting with variations
   - Look for:
     - Long tasks (>50ms)
     - Layout thrashing
     - Excessive repaints

2. **Memory Tab**
   - Take heap snapshots before and after interactions
   - Look for memory leaks in price calculations

3. **Network Tab**
   - Monitor AJAX requests
   - Check for duplicate requests
   - Measure request/response times

### Firefox Developer Tools

1. **Performance Tab**
   - Record performance profiles
   - Analyze call stacks for price calculations

2. **Network Tab**
   - Monitor network requests
   - Check for caching effectiveness

## Performance Monitoring Script

Add this to your theme's `functions.php` for detailed performance logging:

```php
// Performance monitoring for variable products
add_action('wp_footer', function() {
    if (is_product()) {
        global $product;
        if ($product && $product->is_type('variable')) {
            ?>
            <script>
            // Monitor price calculation performance
            let priceCalculationTimes = [];
            
            // Override WooCommerce price update function
            const originalUpdatePrice = jQuery.fn.update_variation_values;
            jQuery.fn.update_variation_values = function() {
                const startTime = performance.now();
                const result = originalUpdatePrice.apply(this, arguments);
                const endTime = performance.now();
                
                priceCalculationTimes.push(endTime - startTime);
                console.log('Price calculation time:', endTime - startTime, 'ms');
                
                return result;
            };
            
            // Log performance data on page unload
            window.addEventListener('beforeunload', function() {
                if (priceCalculationTimes.length > 0) {
                    const avgTime = priceCalculationTimes.reduce((a,b) => a+b) / priceCalculationTimes.length;
                    console.log('Average price calculation time:', avgTime, 'ms');
                    console.log('Total price calculations:', priceCalculationTimes.length);
                }
            });
            </script>
            <?php
        }
    }
});
```

## Expected Results

### Before Optimization
- Multiple AJAX requests for price updates
- Longer variation selection times
- Higher memory usage for price calculations
- Potential duplicate price calculations

### After Optimization
- Reduced AJAX requests (when prices are identical)
- Faster variation selection
- Lower memory usage
- Unified caching for identical price scenarios

## Troubleshooting

### Common Issues

1. **Caching Not Working**
   - Check if transients are being created
   - Verify cache keys are consistent
   - Check for cache conflicts

2. **Performance Not Improving**
   - Verify optimization conditions are met
   - Check if prices are actually identical
   - Look for other performance bottlenecks

3. **Inconsistent Results**
   - Clear all caches between tests
   - Test with different product configurations
   - Check for plugin conflicts

## Reporting

Create a performance report template:

```markdown
# Performance Test Report - [Date]

## Test Environment
- WooCommerce Version: X.X.X
- WordPress Version: X.X.X
- PHP Version: X.X.X
- Theme: [Theme Name]
- Active Plugins: [List]

## Test Product
- Product ID: [ID]
- Variations: [Count]
- Tax Status: [Taxable/Non-taxable]
- Price Range: $X - $Y

## Results

### Page Load Performance
- Initial Load: X.Xs
- Variation Selection: X.Xs average
- AJAX Requests: X requests
- Memory Usage: X MB

### Optimization Impact
- Cache Hits: X%
- Duplicate Calculations Avoided: X
- Performance Improvement: X%

## Recommendations
[Based on results]
```

## Next Steps

1. **Baseline Testing**: Run tests before implementing optimization
2. **Implementation**: Apply the optimization changes
3. **Post-Testing**: Run the same tests after optimization
4. **Comparison**: Compare results and measure improvement
5. **Monitoring**: Set up ongoing performance monitoring