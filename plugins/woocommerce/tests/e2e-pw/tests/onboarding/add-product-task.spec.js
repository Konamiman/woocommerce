const { test, expect } = require( '../../fixtures/fixtures' );
const { ADMIN_STATE_PATH } = require( '../../playwright.config' );

const hide_task_list = async ( wcAdminApi, task_list_name ) => {
	const {
		statusText,
		data: { isHidden },
	} = await wcAdminApi.post( 'onboarding/tasks/' + task_list_name + '/hide' );

	expect( statusText ).toEqual( 'OK' );

	return isHidden === true;
};

const show_task_list = async ( wcAdminApi, task_list_name ) => {
	const {
		statusText,
		data: { isHidden },
	} = await wcAdminApi.post(
		'onboarding/tasks/' + task_list_name + '/unhide'
	);

	expect( statusText ).toEqual( 'OK' );

	return isHidden === false;
};

// TODO (E2E Audit): This test should be combined with other WC Homepage setup tests like the tests in activate-and-setup/stats-overview.spec.js into a single spec.
test.describe( 'Add Product Task', () => {
	test.use( { storageState: ADMIN_STATE_PATH } );

	test.beforeAll( async ( { wcAdminApi, api } ) => {
		await wcAdminApi.post( 'onboarding/profile', {
			skipped: true,
		} );
		const products = await api.get( 'products?per_page=50' );
		await api.post( 'products/batch', {
			delete: products.data.map( ( product ) => product.id ),
		} );
	} );

	test.afterAll( async ( { wcAdminApi } ) => {
		await wcAdminApi.post( 'onboarding/profile', {
			skipped: false,
		} );
	} );

	test( 'Add product task displays options for different product types', async ( {
		page,
	} ) => {
		// Navigate to the task list
		await page.goto( 'wp-admin/admin.php?page=wc-admin&task=products' );

		// Verify product type options are displayed
		await expect(
			page.getByRole( 'menuitem', { name: 'Physical product' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'menuitem', { name: 'Variable product' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'menuitem', { name: 'Grouped product' } )
		).toBeVisible();
	} );

	test( 'Products page redirects to add product task when no products exist', async ( {
		page,
	} ) => {
		// Navigate to All Products page
		await page.goto( 'wp-admin/edit.php?post_type=product' );

		// Verify redirect to add product task
		await expect( page ).toHaveURL( /.+task=products/ );
		await expect(
			page.getByRole( 'menuitem', { name: 'Physical product' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'menuitem', { name: 'Variable product' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'menuitem', { name: 'Grouped product' } )
		).toBeVisible();
	} );

	test( 'Products page shows products table when products exist', async ( {
		page,
		api,
	} ) => {
		// Create a test product
		await api.post( 'products', {
			name: 'Test Product',
			type: 'simple',
			regular_price: '10.00',
		} );

		// Navigate to All Products page
		await page.goto( 'wp-admin/edit.php?post_type=product' );

		// Verify products table is visible
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();
		await expect(
			page.getByRole( 'columnheader', { name: 'Name' } )
		).toHaveCount( 2 );
		await expect(
			page.getByRole( 'columnheader', { name: 'SKU' } )
		).toHaveCount( 2 );
		await expect(
			page.getByRole( 'columnheader', { name: 'Price' } )
		).toHaveCount( 2 );
		await expect(
			page.locator( '.wp-list-table > tbody > tr' )
		).toHaveCount( 1 );

		// Clean up - delete test product
		const products = await api.get( 'products' );
		for ( const product of products.data ) {
			await api.delete( `products/${ product.id }`, { force: true } );
		}
	} );

	test( 'Products page redirects to add product task when no products exist and task list is hidden', async ( {
		page,
		wcAdminApi,
	} ) => {
		// Hide the task list
		expect( await hide_task_list( wcAdminApi, 'setup' ) ).toBe( true );

		// Navigate to All Products page
		await page.goto( 'wp-admin/edit.php?post_type=product' );

		// Verify redirect to add product task
		await expect( page ).toHaveURL( /.+task=products/ );
		await expect(
			page.getByRole( 'menuitem', { name: 'Physical product' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'menuitem', { name: 'Variable product' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'menuitem', { name: 'Grouped product' } )
		).toBeVisible();

		// Reset task list to visible
		expect( await show_task_list( wcAdminApi, 'setup' ) ).toBe( true );
	} );
} );
