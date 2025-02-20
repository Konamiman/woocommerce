/**
 * External dependencies
 */
import { Text } from '@woocommerce/experimental';
import interpolateComponents from '@automattic/interpolate-components';
import { Link } from '@woocommerce/components';
import { getAdminLink } from '@woocommerce/settings';
import { recordEvent } from '@woocommerce/tracks';

interface TextProps {
	/**
	 * HTML element to use for the Text component. Uses `span` by default.
	 */
	as?: string;
	className?: string;
}

interface TrackedLinkProps {
	textProps?: TextProps;
	/**
	 * The complete translatable string that includes {{Link}} and {{/Link}} placeholders
	 * Example: "Visit the {{Link}}Official WooCommerce Marketplace{{/Link}} to find more tax solutions"
	 */
	message: string;
	eventName?: string;
	targetUrl: string;
	/**
	 * Optional callback function to be called when the link is clicked
	 * If provided, this will be called instead of the default recordEvent behavior
	 */
	onClickCallback?: () => void;
}

/**
 * A component that renders a link with tracking capabilities.
 */
export const TrackedLink: React.FC< TrackedLinkProps > = ( {
	textProps,
	message,
	eventName = '',
	targetUrl,
	onClickCallback,
} ) => (
	<Text { ...textProps }>
		{ interpolateComponents( {
			mixedString: message,
			components: {
				Link: (
					<Link
						onClick={ () => {
							if ( onClickCallback ) {
								onClickCallback();
							} else {
								recordEvent( eventName );
							}
							window.location.href = getAdminLink( targetUrl );
							return false;
						} }
						href=""
						type="wc-admin"
					/>
				),
			},
		} ) }
	</Text>
);
