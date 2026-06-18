<?php
/**
 * WP CLI Commands for Variable Product Price Performance Testing
 * 
 * Add this to your wp-cli.yml or load as a plugin
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class WC_Performance_Test_Command extends WP_CLI_Command {
    
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
     * 
     * ## OPTIONS
     * 
     * [--variations=<count>]
     * : Number of variations to create (default: 100)
     * 
     * [--taxable]
     * : Make product taxable (default: false)
     * 
     * [--sale-prices]
     * : Include sale prices (default: true)
     * 
     * ## EXAMPLES
     * 
     *     wp wc-perf create-test-product --variations=50
     *     wp wc-perf create-test-product --variations=200 --taxable
     * 
     * @when before_wp_load
     */
    public function create_test_product( $args, $assoc_args ) {
        $variation_count = isset( $assoc_args['variations'] ) ? intval( $assoc_args['variations'] ) : 100;
        $taxable = isset( $assoc_args['taxable'] ) ? true : false;
        $sale_prices = isset( $assoc_args['sale-prices'] ) ? true : true;
        
        WP_CLI::log( "Creating test variable product with {$variation_count} variations..." );
        
        // Create variable product
        $product = new WC_Product_Variable();
        $product->set_name( 'Performance Test Variable Product - ' . date( 'Y-m-d H:i:s' ) );
        $product->set_sku( 'perf-test-var-' . time() );
        $product->set_regular_price( 50 );
        $product->set_status( 'publish' );
        
        if ( $taxable ) {
            $product->set_tax_class( 'standard' );
        } else {
            $product->set_tax_class( '' );
        }
        
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
        
        $progress = \WP_CLI\Utils\make_progress_bar( 'Creating variations', $variation_count );
        
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
                    
                    $base_price = 10 + ($variations_created * 0.5);
                    $variation->set_regular_price( $base_price );
                    
                    if ( $sale_prices && $variations_created % 3 === 0 ) {
                        $variation->set_sale_price( $base_price * 0.8 );
                    }
                    
                    $variation->set_manage_stock( true );
                    $variation->set_stock_quantity( 10 );
                    $variation->save();
                    $variations_created++;
                    $progress->tick();
                }
            }
        }
        
        $progress->finish();
        
        WP_CLI::success( "Created test product ID: {$this->test_product_id} with {$variations_created} variations" );
        
        // Store product ID for other commands
        update_option( 'wc_perf_test_product_id', $this->test_product_id );
        
        return $this->test_product_id;
    }
    
    /**
     * Run performance tests on variable product price calculations
     * 
     * ## OPTIONS
     * 
     * [--iterations=<count>]
     * : Number of test iterations (default: 10)
     * 
     * [--product-id=<id>]
     * : Product ID to test (default: last created test product)
     * 
     * [--clear-cache]
     * : Clear caches before each test (default: true)
     * 
     * [--output-format=<format>]
     * : Output format (table, json, csv) (default: table)
     * 
     * ## EXAMPLES
     * 
     *     wp wc-perf test-performance --iterations=20
     *     wp wc-perf test-performance --product-id=123 --iterations=5
     *     wp wc-perf test-performance --output-format=json
     * 
     * @when before_wp_load
     */
    public function test_performance( $args, $assoc_args ) {
        $iterations = isset( $assoc_args['iterations'] ) ? intval( $assoc_args['iterations'] ) : 10;
        $product_id = isset( $assoc_args['product-id'] ) ? intval( $assoc_args['product-id'] ) : get_option( 'wc_perf_test_product_id' );
        $clear_cache = isset( $assoc_args['clear-cache'] ) ? $assoc_args['clear-cache'] : true;
        $output_format = isset( $assoc_args['output-format'] ) ? $assoc_args['output-format'] : 'table';
        
        if ( ! $product_id ) {
            WP_CLI::error( 'No product ID provided. Create a test product first or specify --product-id' );
        }
        
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            WP_CLI::error( "Product with ID {$product_id} not found" );
        }
        
        WP_CLI::log( "Running performance tests on product ID: {$product_id}" );
        WP_CLI::log( "Iterations: {$iterations}, Clear cache: " . ($clear_cache ? 'yes' : 'no') );
        
        $results = array();
        $for_display_values = array( false, true );
        
        foreach ( $for_display_values as $for_display ) {
            WP_CLI::log( "Testing for_display = " . ($for_display ? 'true' : 'false') );
            
            $times = array();
            $memory_usage = array();
            $cache_hits = 0;
            
            $progress = \WP_CLI\Utils\make_progress_bar( 'Running tests', $iterations );
            
            for ( $i = 0; $i < $iterations; $i++ ) {
                if ( $clear_cache ) {
                    $this->clear_test_caches( $product_id );
                }
                
                $start_time = microtime( true );
                $start_memory = memory_get_usage( true );
                
                $prices = $product->get_variation_prices( $for_display );
                
                $end_time = microtime( true );
                $end_memory = memory_get_usage( true );
                
                $times[] = $end_time - $start_time;
                $memory_usage[] = $end_memory - $start_memory;
                
                // Check if result was cached
                $cache_key = 'wc_var_prices_' . $product_id;
                $cached = get_transient( $cache_key );
                if ( $cached ) {
                    $cache_hits++;
                }
                
                $progress->tick();
            }
            
            $progress->finish();
            
            $results[ $for_display ? 'for_display_true' : 'for_display_false' ] = array(
                'times' => $times,
                'memory_usage' => $memory_usage,
                'avg_time' => array_sum( $times ) / count( $times ),
                'min_time' => min( $times ),
                'max_time' => max( $times ),
                'avg_memory' => array_sum( $memory_usage ) / count( $memory_usage ),
                'min_memory' => min( $memory_usage ),
                'max_memory' => max( $memory_usage ),
                'cache_hits' => $cache_hits,
                'cache_hit_rate' => ($cache_hits / $iterations) * 100,
            );
        }
        
        // Compare results
        $prices_false = $product->get_variation_prices( false );
        $prices_true = $product->get_variation_prices( true );
        $identical = $this->are_prices_identical( $prices_false, $prices_true );
        
        // Output results
        $this->output_results( $results, $identical, $output_format );
        
        // Save results to log
        $this->log_results( $results, $identical, $product_id );
    }
    
    /**
     * Compare performance before and after optimization
     * 
     * ## OPTIONS
     * 
     * [--baseline-file=<file>]
     * : Path to baseline results file (JSON format)
     * 
     * [--current-file=<file>]
     * : Path to current results file (JSON format)
     * 
     * ## EXAMPLES
     * 
     *     wp wc-perf compare-results --baseline-file=baseline.json --current-file=current.json
     * 
     * @when before_wp_load
     */
    public function compare_results( $args, $assoc_args ) {
        $baseline_file = isset( $assoc_args['baseline-file'] ) ? $assoc_args['baseline-file'] : null;
        $current_file = isset( $assoc_args['current-file'] ) ? $assoc_args['current-file'] : null;
        
        if ( ! $baseline_file || ! file_exists( $baseline_file ) ) {
            WP_CLI::error( 'Baseline file not found or not specified' );
        }
        
        if ( ! $current_file || ! file_exists( $current_file ) ) {
            WP_CLI::error( 'Current file not found or not specified' );
        }
        
        $baseline = json_decode( file_get_contents( $baseline_file ), true );
        $current = json_decode( file_get_contents( $current_file ), true );
        
        if ( ! $baseline || ! $current ) {
            WP_CLI::error( 'Invalid JSON files' );
        }
        
        WP_CLI::log( "Comparing performance results..." );
        
        $comparison = array();
        
        foreach ( $baseline as $test_type => $baseline_data ) {
            if ( isset( $current[ $test_type ] ) ) {
                $current_data = $current[ $test_type ];
                
                $time_improvement = (($baseline_data['avg_time'] - $current_data['avg_time']) / $baseline_data['avg_time']) * 100;
                $memory_improvement = (($baseline_data['avg_memory'] - $current_data['avg_memory']) / $baseline_data['avg_memory']) * 100;
                
                $comparison[ $test_type ] = array(
                    'baseline_time' => $baseline_data['avg_time'],
                    'current_time' => $current_data['avg_time'],
                    'time_improvement' => $time_improvement,
                    'baseline_memory' => $baseline_data['avg_memory'],
                    'current_memory' => $current_data['avg_memory'],
                    'memory_improvement' => $memory_improvement,
                );
            }
        }
        
        // Output comparison table
        $this->output_comparison( $comparison );
    }
    
    /**
     * Monitor real-time performance of variable product price calculations
     * 
     * ## OPTIONS
     * 
     * [--duration=<seconds>]
     * : Duration to monitor in seconds (default: 60)
     * 
     * [--product-id=<id>]
     * : Product ID to monitor (default: last created test product)
     * 
     * ## EXAMPLES
     * 
     *     wp wc-perf monitor --duration=120
     *     wp wc-perf monitor --product-id=123 --duration=300
     * 
     * @when before_wp_load
     */
    public function monitor( $args, $assoc_args ) {
        $duration = isset( $assoc_args['duration'] ) ? intval( $assoc_args['duration'] ) : 60;
        $product_id = isset( $assoc_args['product-id'] ) ? intval( $assoc_args['product-id'] ) : get_option( 'wc_perf_test_product_id' );
        
        if ( ! $product_id ) {
            WP_CLI::error( 'No product ID provided. Create a test product first or specify --product-id' );
        }
        
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            WP_CLI::error( "Product with ID {$product_id} not found" );
        }
        
        WP_CLI::log( "Monitoring performance for {$duration} seconds..." );
        WP_CLI::log( "Press Ctrl+C to stop monitoring" );
        
        $start_time = time();
        $measurements = array();
        
        while ( (time() - $start_time) < $duration ) {
            $measurement = array(
                'timestamp' => time(),
                'for_display_false' => array(),
                'for_display_true' => array(),
            );
            
            // Test for_display = false
            $start = microtime( true );
            $prices_false = $product->get_variation_prices( false );
            $measurement['for_display_false']['time'] = microtime( true ) - $start;
            $measurement['for_display_false']['memory'] = memory_get_usage( true );
            
            // Test for_display = true
            $start = microtime( true );
            $prices_true = $product->get_variation_prices( true );
            $measurement['for_display_true']['time'] = microtime( true ) - $start;
            $measurement['for_display_true']['memory'] = memory_get_usage( true );
            
            $measurements[] = $measurement;
            
            // Check if prices are identical
            $identical = $this->are_prices_identical( $prices_false, $prices_true );
            if ( $identical ) {
                WP_CLI::log( "[" . date( 'H:i:s' ) . "] Prices identical - optimization opportunity detected!" );
            }
            
            sleep( 1 );
        }
        
        // Analyze measurements
        $this->analyze_monitoring_data( $measurements );
    }
    
    /**
     * Clean up test data
     * 
     * ## OPTIONS
     * 
     * [--product-id=<id>]
     * : Product ID to clean up (default: last created test product)
     * 
     * [--all]
     * : Clean up all test products
     * 
     * ## EXAMPLES
     * 
     *     wp wc-perf cleanup
     *     wp wc-perf cleanup --product-id=123
     *     wp wc-perf cleanup --all
     * 
     * @when before_wp_load
     */
    public function cleanup( $args, $assoc_args ) {
        $product_id = isset( $assoc_args['product-id'] ) ? intval( $assoc_args['product-id'] ) : get_option( 'wc_perf_test_product_id' );
        $all = isset( $assoc_args['all'] ) ? true : false;
        
        if ( $all ) {
            // Find all test products
            $test_products = get_posts( array(
                'post_type' => 'product',
                'meta_query' => array(
                    array(
                        'key' => '_sku',
                        'value' => 'perf-test-var-',
                        'compare' => 'LIKE'
                    )
                ),
                'posts_per_page' => -1,
                'fields' => 'ids'
            ) );
            
            foreach ( $test_products as $pid ) {
                wp_delete_post( $pid, true );
                $this->clear_test_caches( $pid );
                WP_CLI::log( "Deleted test product ID: {$pid}" );
            }
            
            WP_CLI::success( "Cleaned up " . count( $test_products ) . " test products" );
        } else {
            if ( ! $product_id ) {
                WP_CLI::error( 'No product ID provided. Create a test product first or specify --product-id' );
            }
            
            wp_delete_post( $product_id, true );
            $this->clear_test_caches( $product_id );
            delete_option( 'wc_perf_test_product_id' );
            
            WP_CLI::success( "Cleaned up test product ID: {$product_id}" );
        }
    }
    
    // Helper methods
    
    private function clear_test_caches( $product_id ) {
        delete_transient( 'wc_var_prices_' . $product_id );
        wp_cache_delete( 'product-' . $product_id, 'products' );
    }
    
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
    
    private function output_results( $results, $identical, $format ) {
        if ( $format === 'json' ) {
            $output = array(
                'results' => $results,
                'prices_identical' => $identical,
                'timestamp' => current_time( 'Y-m-d H:i:s' )
            );
            WP_CLI::log( json_encode( $output, JSON_PRETTY_PRINT ) );
        } else {
            // Table format
            $table_data = array();
            
            foreach ( $results as $test_type => $data ) {
                $table_data[] = array(
                    'Test Type' => str_replace( '_', ' ', $test_type ),
                    'Avg Time (s)' => number_format( $data['avg_time'], 6 ),
                    'Min Time (s)' => number_format( $data['min_time'], 6 ),
                    'Max Time (s)' => number_format( $data['max_time'], 6 ),
                    'Avg Memory (KB)' => number_format( $data['avg_memory'] / 1024, 2 ),
                    'Cache Hit Rate (%)' => number_format( $data['cache_hit_rate'], 2 ),
                );
            }
            
            WP_CLI\Utils\format_items( 'table', $table_data, array(
                'Test Type', 'Avg Time (s)', 'Min Time (s)', 'Max Time (s)', 'Avg Memory (KB)', 'Cache Hit Rate (%)'
            ) );
            
            WP_CLI::log( "\nPrices Identical: " . ($identical ? 'YES' : 'NO') );
            WP_CLI::log( "Optimization Potential: " . ($identical ? 'HIGH' : 'LOW') );
        }
    }
    
    private function output_comparison( $comparison ) {
        $table_data = array();
        
        foreach ( $comparison as $test_type => $data ) {
            $table_data[] = array(
                'Test Type' => str_replace( '_', ' ', $test_type ),
                'Baseline Time (s)' => number_format( $data['baseline_time'], 6 ),
                'Current Time (s)' => number_format( $data['current_time'], 6 ),
                'Time Improvement (%)' => number_format( $data['time_improvement'], 2 ),
                'Baseline Memory (KB)' => number_format( $data['baseline_memory'] / 1024, 2 ),
                'Current Memory (KB)' => number_format( $data['current_memory'] / 1024, 2 ),
                'Memory Improvement (%)' => number_format( $data['memory_improvement'], 2 ),
            );
        }
        
        WP_CLI\Utils\format_items( 'table', $table_data, array(
            'Test Type', 'Baseline Time (s)', 'Current Time (s)', 'Time Improvement (%)',
            'Baseline Memory (KB)', 'Current Memory (KB)', 'Memory Improvement (%)'
        ) );
    }
    
    private function analyze_monitoring_data( $measurements ) {
        $analysis = array(
            'total_measurements' => count( $measurements ),
            'for_display_false' => array(),
            'for_display_true' => array(),
        );
        
        foreach ( array( 'for_display_false', 'for_display_true' ) as $type ) {
            $times = array_column( $measurements, $type );
            $times = array_column( $times, 'time' );
            
            $analysis[ $type ] = array(
                'avg_time' => array_sum( $times ) / count( $times ),
                'min_time' => min( $times ),
                'max_time' => max( $times ),
            );
        }
        
        WP_CLI::log( "\nMonitoring Analysis:" );
        WP_CLI::log( "Total measurements: " . $analysis['total_measurements'] );
        WP_CLI::log( "for_display=false - Avg: " . number_format( $analysis['for_display_false']['avg_time'], 6 ) . "s" );
        WP_CLI::log( "for_display=true - Avg: " . number_format( $analysis['for_display_true']['avg_time'], 6 ) . "s" );
    }
    
    private function log_results( $results, $identical, $product_id ) {
        $log_entry = array(
            'timestamp' => current_time( 'Y-m-d H:i:s' ),
            'product_id' => $product_id,
            'results' => $results,
            'prices_identical' => $identical,
        );
        
        file_put_contents( $this->log_file, json_encode( $log_entry ) . "\n", FILE_APPEND | LOCK_EX );
        WP_CLI::log( "Results logged to: {$this->log_file}" );
    }
}

// Register the command
WP_CLI::add_command( 'wc-perf', 'WC_Performance_Test_Command' );