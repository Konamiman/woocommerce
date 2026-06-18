<?php
/**
 * Performance Testing Setup for Variable Product Price Optimization
 * 
 * This file sets up the testing environment and provides measurement tools
 * for testing the wc_var_prices_ transient optimization.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Variable_Product_Performance_Test {
    
    private $test_results = array();
    private $test_product_id = null;
    private $log_file = '';
    
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/debug/wc-performance-test.log';
        $this->ensure_log_directory();
    }
    
    private function ensure_log_directory() {
        $log_dir = dirname( $this->log_file );
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
    }
    
    /**
     * Create a test variable product with many variations
     */
    public function create_test_product( $variation_count = 100 ) {
        // Create variable product
        $product = new WC_Product_Variable();
        $product->set_name( 'Performance Test Variable Product' );
        $product->set_sku( 'perf-test-var-' . time() );
        $product->set_regular_price( 50 );
        $product->set_status( 'publish' );
        $product->save();
        
        $this->test_product_id = $product->get_id();
        
        // Create attributes
        $attributes = array();
        $attribute_names = array( 'Color', 'Size', 'Material' );
        
        foreach ( $attribute_names as $attr_name ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name( $attr_name );
            $attribute->set_options( array( 'Option 1', 'Option 2', 'Option 3', 'Option 4', 'Option 5' ) );
            $attribute->set_visible( true );
            $attribute->set_variation( true );
            $attributes[] = $attribute;
        }
        
        $product->set_attributes( $attributes );
        $product->save();
        
        // Create variations
        $variation_count = min( $variation_count, 125 ); // Max 5^3 = 125 combinations
        $variations_created = 0;
        
        for ( $i = 0; $i < 5 && $variations_created < $variation_count; $i++ ) {
            for ( $j = 0; $j < 5 && $variations_created < $variation_count; $j++ ) {
                for ( $k = 0; $k < 5 && $variations_created < $variation_count; $k++ ) {
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id( $this->test_product_id );
                    $variation->set_attributes( array(
                        'pa_color' => 'option-' . ($i + 1),
                        'pa_size' => 'option-' . ($j + 1),
                        'pa_material' => 'option-' . ($k + 1),
                    ) );
                    $variation->set_regular_price( 10 + ($variations_created * 0.5) );
                    $variation->set_sale_price( 8 + ($variations_created * 0.4) );
                    $variation->set_manage_stock( true );
                    $variation->set_stock_quantity( 10 );
                    $variation->save();
                    $variations_created++;
                }
            }
        }
        
        $this->log( "Created test product ID: {$this->test_product_id} with {$variations_created} variations" );
        return $this->test_product_id;
    }
    
    /**
     * Clear all caches related to the test product
     */
    public function clear_test_caches() {
        if ( $this->test_product_id ) {
            delete_transient( 'wc_var_prices_' . $this->test_product_id );
            wp_cache_delete( 'product-' . $this->test_product_id, 'products' );
            $this->log( "Cleared caches for product ID: {$this->test_product_id}" );
        }
    }
    
    /**
     * Measure performance of get_variation_prices calls
     */
    public function measure_performance( $iterations = 10, $for_display_values = array( false, true ) ) {
        if ( ! $this->test_product_id ) {
            throw new Exception( 'No test product created. Call create_test_product() first.' );
        }
        
        $product = wc_get_product( $this->test_product_id );
        if ( ! $product ) {
            throw new Exception( 'Test product not found.' );
        }
        
        $results = array();
        
        foreach ( $for_display_values as $for_display ) {
            $times = array();
            $memory_usage = array();
            
            for ( $i = 0; $i < $iterations; $i++ ) {
                // Clear cache before each test
                $this->clear_test_caches();
                
                // Measure time and memory
                $start_time = microtime( true );
                $start_memory = memory_get_usage( true );
                
                $prices = $product->get_variation_prices( $for_display );
                
                $end_time = microtime( true );
                $end_memory = memory_get_usage( true );
                
                $times[] = $end_time - $start_time;
                $memory_usage[] = $end_memory - $start_memory;
                
                // Log cache status
                $cache_key = 'wc_var_prices_' . $this->test_product_id;
                $cached = get_transient( $cache_key );
                $this->log( "Iteration {$i}, for_display: " . ($for_display ? 'true' : 'false') . 
                           ", time: " . number_format( $times[$i], 6 ) . 
                           "s, memory: " . number_format( $memory_usage[$i] / 1024, 2 ) . 
                           "KB, cached: " . ($cached ? 'yes' : 'no') );
            }
            
            $results[ $for_display ? 'for_display_true' : 'for_display_false' ] = array(
                'times' => $times,
                'memory_usage' => $memory_usage,
                'avg_time' => array_sum( $times ) / count( $times ),
                'min_time' => min( $times ),
                'max_time' => max( $times ),
                'avg_memory' => array_sum( $memory_usage ) / count( $memory_usage ),
                'min_memory' => min( $memory_usage ),
                'max_memory' => max( $memory_usage ),
            );
        }
        
        $this->test_results = $results;
        return $results;
    }
    
    /**
     * Compare results and detect if prices are identical
     */
    public function compare_results() {
        if ( empty( $this->test_results ) ) {
            throw new Exception( 'No test results available. Call measure_performance() first.' );
        }
        
        $product = wc_get_product( $this->test_product_id );
        $prices_false = $product->get_variation_prices( false );
        $prices_true = $product->get_variation_prices( true );
        
        $identical = $this->are_prices_identical( $prices_false, $prices_true );
        
        $comparison = array(
            'prices_identical' => $identical,
            'performance_improvement' => array(),
        );
        
        if ( $identical ) {
            $comparison['performance_improvement'] = array(
                'time_saved_percent' => 0, // Would be calculated with optimization
                'memory_saved_percent' => 0,
                'cache_efficiency' => 'Both calculations return identical results - optimization possible',
            );
        }
        
        return $comparison;
    }
    
    /**
     * Check if two price arrays are identical
     */
    private function are_prices_identical( $prices1, $prices2 ) {
        if ( count( $prices1 ) !== count( $prices2 ) ) {
            return false;
        }
        
        foreach ( $prices1 as $key => $values1 ) {
            if ( ! isset( $prices2[ $key ] ) ) {
                return false;
            }
            
            $values2 = $prices2[ $key ];
            if ( count( $values1 ) !== count( $values2 ) ) {
                return false;
            }
            
            foreach ( $values1 as $variation_id => $price1 ) {
                if ( ! isset( $values2[ $variation_id ] ) ) {
                    return false;
                }
                
                $price2 = $values2[ $variation_id ];
                if ( abs( floatval( $price1 ) - floatval( $price2 ) ) > 0.01 ) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Generate performance report
     */
    public function generate_report() {
        if ( empty( $this->test_results ) ) {
            throw new Exception( 'No test results available.' );
        }
        
        $report = "\n" . str_repeat( '=', 80 ) . "\n";
        $report .= "WOOCOMMERCE VARIABLE PRODUCT PRICE PERFORMANCE TEST REPORT\n";
        $report .= str_repeat( '=', 80 ) . "\n";
        $report .= "Test Product ID: {$this->test_product_id}\n";
        $report .= "Test Time: " . current_time( 'Y-m-d H:i:s' ) . "\n\n";
        
        foreach ( $this->test_results as $test_type => $results ) {
            $display_type = str_replace( '_', ' ', $test_type );
            $report .= "{$display_type}:\n";
            $report .= "  Average Time: " . number_format( $results['avg_time'], 6 ) . " seconds\n";
            $report .= "  Min Time: " . number_format( $results['min_time'], 6 ) . " seconds\n";
            $report .= "  Max Time: " . number_format( $results['max_time'], 6 ) . " seconds\n";
            $report .= "  Average Memory: " . number_format( $results['avg_memory'] / 1024, 2 ) . " KB\n";
            $report .= "  Min Memory: " . number_format( $results['min_memory'] / 1024, 2 ) . " KB\n";
            $report .= "  Max Memory: " . number_format( $results['max_memory'] / 1024, 2 ) . " KB\n\n";
        }
        
        $comparison = $this->compare_results();
        $report .= "OPTIMIZATION ANALYSIS:\n";
        $report .= "  Prices Identical: " . ($comparison['prices_identical'] ? 'YES' : 'NO') . "\n";
        $report .= "  Optimization Potential: " . ($comparison['prices_identical'] ? 'HIGH' : 'LOW') . "\n\n";
        
        if ( $comparison['prices_identical'] ) {
            $report .= "RECOMMENDATION: Implement unified caching for this product type.\n";
            $report .= "Expected benefits: 50% cache reduction, faster retrieval, lower memory usage.\n";
        } else {
            $report .= "RECOMMENDATION: Current caching strategy is optimal for this product type.\n";
        }
        
        $report .= str_repeat( '=', 80 ) . "\n";
        
        $this->log( $report );
        return $report;
    }
    
    /**
     * Log message to file
     */
    private function log( $message ) {
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $log_entry = "[{$timestamp}] {$message}\n";
        file_put_contents( $this->log_file, $log_entry, FILE_APPEND | LOCK_EX );
    }
    
    /**
     * Clean up test data
     */
    public function cleanup() {
        if ( $this->test_product_id ) {
            wp_delete_post( $this->test_product_id, true );
            $this->clear_test_caches();
            $this->log( "Cleaned up test product ID: {$this->test_product_id}" );
        }
    }
    
    /**
     * Get test product ID
     */
    public function get_test_product_id() {
        return $this->test_product_id;
    }
    
    /**
     * Get log file path
     */
    public function get_log_file() {
        return $this->log_file;
    }
}