/**
 * Internal dependencies
 */
import { test as setup } from './fixtures';

async function deactivateWooCommerce( wcbtApi ) {
	try {
		await wcbtApi.fetch(
			'/wp-json/wc-admin-test-helper/live-branches/deactivate/v1',
			{ method: 'GET' }
		);
		console.log( 'WC deactivated.' );
	} catch ( err ) {
		console.error( 'Error deactivating WooCommerce:', err );
	}
}

async function getActivatedWooCommerceVersion( wpApi ) {
	const response = await wpApi.get( './wp-json/wp/v2/plugins', {
		data: { status: 'active' },
	} );
	const plugins = await response.json();
	return plugins.find( ( plugin ) => plugin.name === 'WooCommerce' )?.version;
}

setup( 'Install WC using WC Beta Tester', async ( { wcbtApi, wpApi } ) => {
	setup.skip(
		! process.env.INSTALL_WC,
		'Skipping installing WC using WC Beta Tester; INSTALL_WC not found.'
	);
	console.log( 'INSTALL_WC is enabled. Running installation script...' );

	// Check if WooCommerce is activated and its version
	const activatedWcVersion = await getActivatedWooCommerceVersion( wpApi );

	if ( activatedWcVersion ) {
		console.log(
			`WooCommerce is activated. Version: ${ activatedWcVersion }`
		);
	} else {
		console.log( 'WooCommerce is not activated.' );
	}

	const wcVersion = process.env.WC_VERSION || 'latest';
	let resolvedVersion = '';

	// Install WC
	if ( wcVersion === 'latest' ) {
		const latestResponse = await wcbtApi.fetch(
			'/wp-json/wc-admin-test-helper/live-branches/install/latest/v1',
			{
				method: 'POST',
				data: { include_pre_releases: true },
			}
		);

		if ( ! latestResponse.ok() ) {
			throw new Error(
				`Failed to install latest WC: ${ latestResponse.status() } ${ await latestResponse.text() }`
			);
		}

		resolvedVersion = ( await latestResponse.json() )?.version || '';

		if ( resolvedVersion === activatedWcVersion ) {
			console.log(
				'Skip installing WC: The latest version is already installed and activated.'
			);
			return;
		}
		await deactivateWooCommerce( wcbtApi );

		if ( ! resolvedVersion ) {
			console.error( 'Error: latestResponse.version is undefined.' );
		} else {
			console.log( `Latest version installed: ${ resolvedVersion }` );
		}
	} else {
		if ( wcVersion === activatedWcVersion ) {
			console.log(
				'Skip installing WC: The specified version is already installed and activated.'
			);
			return;
		}
		await deactivateWooCommerce( wcbtApi );

		try {
			const downloadUrl = `https://github.com/woocommerce/woocommerce/releases/download/${ wcVersion }/woocommerce.zip`;
			const installResponse = await wcbtApi.fetch(
				'/wp-json/wc-admin-test-helper/live-branches/install/v1',
				{
					method: 'POST',
					data: {
						pr_name: wcVersion,
						download_url: downloadUrl,
						version: wcVersion,
					},
				}
			);

			if ( ! installResponse.ok() ) {
				throw new Error(
					`Failed to install WC ${ wcVersion }: ${ installResponse.status() } ${ await installResponse.text() }`
				);
			}

			resolvedVersion = wcVersion;
			console.log( `WooCommerce ${ wcVersion } installed.` );
		} catch ( err ) {
			console.error( `Error installing WC version ${ wcVersion }:`, err );
		}
	}

	// Activate WC
	if ( resolvedVersion ) {
		try {
			const activationResponse = await wcbtApi.fetch(
				'/wp-json/wc-admin-test-helper/live-branches/activate/v1',
				{
					method: 'POST',
					data: {
						version: resolvedVersion,
					},
				}
			);

			if ( ! activationResponse.ok() ) {
				throw new Error(
					`Failed to activate WC ${ resolvedVersion }: ${ activationResponse.status() } ${ await activationResponse.text() }`
				);
			}

			console.log( `WooCommerce ${ resolvedVersion } activated.` );
		} catch ( err ) {
			console.error(
				`Error activating WC version ${ resolvedVersion }:`,
				err
			);
		}
	} else {
		console.error(
			'Error: resolvedVersion is undefined. Skipping activation.'
		);
	}

	// Check if WooCommerce is activated and its version
	const finalActivatedWcVersion = await getActivatedWooCommerceVersion(
		wpApi
	);

	if ( finalActivatedWcVersion === resolvedVersion ) {
		console.log( 'Installing with WC Beta Tester is finished.' );
	} else {
		console.error(
			`Expected WC version ${ resolvedVersion } is not installed. Instead: ${ finalActivatedWcVersion }`
		);
	}
} );
