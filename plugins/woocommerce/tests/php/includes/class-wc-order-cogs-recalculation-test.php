<?php
/**
 * Test COGS recalculation on order completion
 *
 * @package WooCommerce\Tests\Order
 */

use Automattic\WooCommerce\Internal\CostOfGoodsSold\CogsAwareUnitTestSuiteTrait;

/**
 * Class WC_Order_Cogs_Recalculation_Test
 */
class WC_Order_Cogs_Recalculation_Test extends WC_Unit_Test_Case {
	use CogsAwareUnitTestSuiteTrait;

	/**
	 * Test that COGS is recalculated when order transitions to completed status
	 */
	public function test_cogs_recalculation_on_completion() {
		$this->enable_cogs_feature();

		// Create an order with initial COGS value
		$order = new WC_Order();
		$order->set_status( 'pending' );
		$order->save();

		// Add a product with COGS to the order
		$this->add_product_with_cogs_to_order( $order, 50.00, 2 );
		$order->calculate_cogs_total_value();
		$order->save();

		$initial_cogs = $order->get_cogs_total_value();
		$this->assertEquals( 100.00, $initial_cogs );

		// Simulate product cost change (this would happen in real scenario)
		$order_items = $order->get_items();
		$this->assertNotEmpty( $order_items, 'Order should have items after adding product with COGS' );
		$first_item = reset( $order_items );
		$product = $first_item->get_product();
		$product->set_cogs_value( 75.00 ); // Increased cost
		$product->save();

		// Transition order to completed status
		$order->set_status( 'completed' );
		$order->save();

		// Verify COGS was recalculated with new product costs
		$final_cogs = $order->get_cogs_total_value();
		$this->assertEquals( 150.00, $final_cogs ); // 75.00 * 2 = 150.00
	}

	/**
	 * Test that can_transition_to_completed method works correctly
	 */
	public function test_can_transition_to_completed_method() {
		$this->enable_cogs_feature();

		$statuses_that_can_complete = array( 'pending', 'failed', 'cancelled', 'processing', 'on-hold' );
		$statuses_that_cannot_complete = array( 'completed', 'refunded', 'trash' );
		
		foreach ( $statuses_that_can_complete as $status ) {
			$order = new WC_Order();
			$order->set_status( $status );
			$order->save();

			$this->assertTrue( $order->can_transition_to_completed(), "Order with {$status} status should be able to transition to completed" );
		}
		
		foreach ( $statuses_that_cannot_complete as $status ) {
			$order = new WC_Order();
			$order->set_status( $status );
			$order->save();

			$this->assertFalse( $order->can_transition_to_completed(), "Order with {$status} status should NOT be able to transition to completed" );
		}
	}

	/**
	 * Test that get_cogs_total_value_html returns clean HTML without provisional message
	 */
	public function test_get_cogs_total_value_html_returns_clean_html() {
		$this->enable_cogs_feature();

		$order = new WC_Order();
		$order->set_status( 'pending' );
		$order->save();

		$this->add_product_with_cogs_to_order( $order, 50.00, 1 );
		$order->calculate_cogs_total_value();
		$order->save();

		$html = $order->get_cogs_total_value_html();
		// Should only contain the price, not any provisional message
		$this->assertStringNotContainsString( 'This cost value is provisional', $html );
		$this->assertStringNotContainsString( 'it will be updated when the order is completed', $html );
		$this->assertStringNotContainsString( 'dashicons-info', $html );
	}

	/**
	 * Test that COGS recalculation only happens for completed status
	 */
	public function test_cogs_recalculation_only_for_completed_status() {
		$this->enable_cogs_feature();

		$order = new WC_Order();
		$order->set_status( 'pending' );
		$order->save();

		$this->add_product_with_cogs_to_order( $order, 50.00, 1 );
		$order->calculate_cogs_total_value();
		$order->save();

		$initial_cogs = $order->get_cogs_total_value();

		// Change product cost
		$order_items = $order->get_items();
		$this->assertNotEmpty( $order_items, 'Order should have items after adding product with COGS' );
		$first_item = reset( $order_items );
		$product = $first_item->get_product();
		$product->set_cogs_value( 75.00 );
		$product->save();

		// Transition to processing (not completed)
		$order->set_status( 'processing' );
		$order->save();

		// COGS should not be recalculated
		$cogs_after_processing = $order->get_cogs_total_value();
		$this->assertEquals( $initial_cogs, $cogs_after_processing );

		// Now transition to completed
		$order->set_status( 'completed' );
		$order->save();

		// COGS should be recalculated now
		$cogs_after_completion = $order->get_cogs_total_value();
		$this->assertEquals( 75.00, $cogs_after_completion );
	}

	/**
	 * Helper method to add a product with COGS to an order
	 *
	 * @param WC_Order $order The target order.
	 * @param float    $cogs_value The COGS value of the product.
	 * @param int      $quantity The quantity of the order item.
	 */
	private function add_product_with_cogs_to_order( WC_Order $order, float $cogs_value, int $quantity ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_cogs_value( $cogs_value );
		$product->save();
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $quantity );
		$order->add_item( $item );
		$order->save(); // Ensure the order is saved after adding items
	}
}