# Complete Testing Workflow for Variable Product Price Optimization

## Prerequisites

1. **Install the testing tools**:
   ```bash
   # Copy the performance testing files to your WordPress installation
   cp performance-test-setup.php wp-content/plugins/
   cp wp-cli-performance-commands.php wp-content/plugins/
   
   # Activate the performance testing plugin
   wp plugin activate performance-test-setup
   wp plugin activate wp-cli-performance-commands
   ```

2. **Set up logging directory**:
   ```bash
   mkdir -p wp-content/debug
   chmod 755 wp-content/debug
   ```

## Phase 1: Baseline Testing (Before Optimization)

### 1.1 Create Test Environment

```bash
# Create a test variable product with 100 variations
wp wc-perf create-test-product --variations=100 --taxable=false

# Note the product ID returned (e.g., 123)
```

### 1.2 Run Baseline Performance Tests

```bash
# Run comprehensive performance tests
wp wc-perf test-performance --iterations=20 --output-format=json > baseline-results.json

# Run with different scenarios
wp wc-perf test-performance --iterations=10 --clear-cache=true --output-format=table
```

### 1.3 UI Testing (Baseline)

1. **Open the test product page** in your browser
2. **Open Developer Tools** (F12)
3. **Go to Network tab** and check "Disable cache"
4. **Reload the page** and record:
   - Page load time
   - Number of AJAX requests
   - Total AJAX request time
   - Price calculation time

5. **Test variation selection**:
   - Click through 20 different variations
   - Record time for each price update
   - Note any duplicate requests

### 1.4 Monitor Real-time Performance

```bash
# Monitor for 2 minutes
wp wc-perf monitor --duration=120
```

## Phase 2: Implement Optimization

### 2.1 Apply the Optimization Changes

Implement the optimization code in your WooCommerce installation:

```php
// Add to wp-content/plugins/woocommerce/includes/data-stores/class-wc-product-variable-data-store-cpt.php

/**
 * Check if prices would be identical for both $for_display = true and $for_display = false
 */
private function would_prices_be_identical( $product ) {
    // If tax is disabled, prices are always identical
    if ( ! wc_tax_enabled() ) {
        return true;
    }
    
    // If product is not taxable, prices are always identical
    if ( ! $product->is_taxable() ) {
        return true;
    }
    
    // If no tax rates exist for this product class, prices are identical
    $tax_rates = WC_Tax::get_rates( $product->get_tax_class() );
    if ( empty( $tax_rates ) ) {
        return true;
    }
    
    // If customer is VAT exempt and prices include tax, both calculations are identical
    if ( ! empty( WC()->customer ) && 
         WC()->customer->get_is_vat_exempt() && 
         wc_prices_include_tax() ) {
        return true;
    }
    
    return false;
}

// Modify the get_price_hash method
protected function get_price_hash( &$product, $for_display = false ) {
    global $wp_filter;

    // Check if both calculations would return identical results
    if ( $this->would_prices_be_identical( $product ) ) {
        // Use a unified hash that works for both $for_display values
        $price_hash = array( 'unified' );
    } else {
        // Use existing logic for different calculations
        $price_hash = array( false );
        
        if ( $for_display && wc_tax_enabled() ) {
            $price_hash = array(
                get_option( 'woocommerce_tax_display_shop', 'excl' ),
                WC_Tax::get_rates(),
                empty( WC()->customer ) ? false : WC()->customer->is_vat_exempt(),
            );
        }
    }

    // ... rest of existing logic
}
```

### 2.2 Clear All Caches

```bash
# Clear all caches to ensure fresh testing
wp transient delete --all
wp cache flush
wp wc-perf cleanup --product-id=123
```

## Phase 3: Post-Optimization Testing

### 3.1 Recreate Test Product

```bash
# Create a new test product with the same configuration
wp wc-perf create-test-product --variations=100 --taxable=false
```

### 3.2 Run Performance Tests

```bash
# Run the same tests as baseline
wp wc-perf test-performance --iterations=20 --output-format=json > current-results.json

# Compare with baseline
wp wc-perf compare-results --baseline-file=baseline-results.json --current-file=current-results.json
```

### 3.3 UI Testing (Post-Optimization)

1. **Repeat the same UI tests** as in Phase 1.3
2. **Compare the results** with baseline measurements
3. **Look for improvements** in:
   - Page load time
   - AJAX request count
   - Price calculation speed
   - Cache hit rates

### 3.4 Monitor Real-time Performance

```bash
# Monitor for the same duration
wp wc-perf monitor --duration=120
```

## Phase 4: Analysis and Reporting

### 4.1 Generate Performance Report

```bash
# Create a comprehensive report
wp wc-perf test-performance --iterations=50 --output-format=json > final-results.json
```

### 4.2 Analyze Results

```bash
# Compare all results
wp wc-perf compare-results --baseline-file=baseline-results.json --current-file=final-results.json
```

### 4.3 Create Performance Report

```markdown
# Performance Optimization Report

## Test Environment
- WooCommerce Version: [Version]
- WordPress Version: [Version]
- PHP Version: [Version]
- Test Product: [Product ID] with [X] variations

## Baseline Results
- Average Time (for_display=false): X.XXXs
- Average Time (for_display=true): X.XXXs
- Memory Usage: X KB
- Cache Hit Rate: X%

## Post-Optimization Results
- Average Time (for_display=false): X.XXXs
- Average Time (for_display=true): X.XXXs
- Memory Usage: X KB
- Cache Hit Rate: X%

## Improvements
- Time Improvement: X%
- Memory Improvement: X%
- Cache Efficiency: X%

## Recommendations
[Based on results]
```

## Phase 5: Production Testing

### 5.1 Test with Real Products

```bash
# Test with existing variable products
wp wc-perf test-performance --product-id=456 --iterations=10
wp wc-perf test-performance --product-id=789 --iterations=10
```

### 5.2 Test Different Scenarios

```bash
# Test with taxable products
wp wc-perf create-test-product --variations=50 --taxable=true
wp wc-perf test-performance --iterations=10

# Test with sale prices
wp wc-perf create-test-product --variations=50 --sale-prices=true
wp wc-perf test-performance --iterations=10
```

### 5.3 Load Testing

```bash
# Run multiple concurrent tests
for i in {1..5}; do
    wp wc-perf test-performance --iterations=10 --output-format=json > load-test-$i.json &
done
wait

# Analyze load test results
wp wc-perf compare-results --baseline-file=baseline-results.json --current-file=load-test-1.json
```

## Phase 6: Monitoring and Maintenance

### 6.1 Set Up Continuous Monitoring

```bash
# Create a monitoring script
cat > monitor-performance.sh << 'EOF'
#!/bin/bash
while true; do
    wp wc-perf monitor --duration=60 >> performance-monitor.log
    sleep 300  # Wait 5 minutes between monitoring sessions
done
EOF

chmod +x monitor-performance.sh
```

### 6.2 Set Up Alerts

```bash
# Create alert script for performance degradation
cat > performance-alert.sh << 'EOF'
#!/bin/bash
THRESHOLD=1.0  # 1 second threshold
CURRENT_TIME=$(wp wc-perf test-performance --iterations=1 --output-format=json | jq -r '.results.for_display_false.avg_time')

if (( $(echo "$CURRENT_TIME > $THRESHOLD" | bc -l) )); then
    echo "Performance alert: Current time $CURRENT_TIME exceeds threshold $THRESHOLD"
    # Send alert (email, Slack, etc.)
fi
EOF

chmod +x performance-alert.sh
```

## Expected Results

### Before Optimization
- Separate cache entries for `$for_display = true` and `$for_display = false`
- Duplicate calculations when prices are identical
- Higher memory usage
- Slower variation selection

### After Optimization
- Unified cache for identical price scenarios
- 50% reduction in cache storage for applicable products
- Faster price calculations
- Lower memory usage
- Improved user experience

## Troubleshooting

### Common Issues

1. **No Performance Improvement**
   - Check if prices are actually identical
   - Verify optimization conditions are met
   - Look for other performance bottlenecks

2. **Cache Issues**
   - Clear all caches between tests
   - Check cache key generation
   - Verify transient storage

3. **Inconsistent Results**
   - Run tests multiple times
   - Check for external factors
   - Verify test environment consistency

## Cleanup

```bash
# Clean up test data
wp wc-perf cleanup --all

# Remove test files
rm baseline-results.json current-results.json final-results.json
rm performance-monitor.log
rm monitor-performance.sh performance-alert.sh
```

This comprehensive testing workflow will help you measure the performance improvements of your variable product price optimization changes both from the UI and using WP CLI.