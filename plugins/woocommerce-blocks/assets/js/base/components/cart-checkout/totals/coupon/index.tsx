/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import Button from '@woocommerce/base-components/button';
import LoadingMask from '@woocommerce/base-components/loading-mask';
import { withInstanceId } from '@wordpress/compose';
import {
	ValidatedTextInput,
	ValidationInputError,
} from '@woocommerce/blocks-components';
import { useSelect } from '@wordpress/data';
import { VALIDATION_STORE_KEY } from '@woocommerce/block-data';
import classnames from 'classnames';
import type { MouseEvent, MouseEventHandler } from 'react';

/**
 * Internal dependencies
 */
import './style.scss';

export interface TotalsCouponProps {
	/**
	 * Instance id of the input
	 */
	instanceId: string;
	/**
	 * Whether the component is in a loading state
	 */
	isLoading?: boolean;
	/**
	 * Whether the coupon form is hidden
	 */
	displayCouponForm?: boolean;
	/**
	 * Submit handler
	 */
	onSubmit?: ( couponValue: string ) => Promise< boolean > | undefined;
}

export const TotalsCoupon = ( {
	instanceId,
	isLoading = false,
	onSubmit,
	displayCouponForm = false,
}: TotalsCouponProps ): JSX.Element => {
	const [ couponValue, setCouponValue ] = useState( '' );
	const [ isCouponFormHidden, setIsCouponFormHidden ] = useState(
		! displayCouponForm
	);
	const textInputId = `wc-block-components-totals-coupon__input-${ instanceId }`;
	const formWrapperClass = classnames(
		'wc-block-components-totals-coupon__content',
		{
			'screen-reader-text': isCouponFormHidden,
		}
	);
	const { validationErrorId } = useSelect( ( select ) => {
		const store = select( VALIDATION_STORE_KEY );
		return {
			validationErrorId: store.getValidationErrorId( textInputId ),
		};
	} );
	const handleCouponAnchorClick: MouseEventHandler< HTMLAnchorElement > = (
		e: MouseEvent< HTMLAnchorElement >
	) => {
		e.preventDefault();
		setIsCouponFormHidden( false );
	};
	const handleCouponSubmit: MouseEventHandler< HTMLButtonElement > = (
		e: MouseEvent< HTMLButtonElement >
	) => {
		e.preventDefault();
		if ( typeof onSubmit !== 'undefined' ) {
			onSubmit( couponValue )?.then( ( result ) => {
				if ( result ) {
					setCouponValue( '' );
					setIsCouponFormHidden( true );
				}
			} );
		} else {
			setCouponValue( '' );
			setIsCouponFormHidden( true );
		}
	};

	return (
		<div className="wc-block-components-totals-coupon">
			{ isCouponFormHidden ? (
				<a
					role="button"
					href="#wc-block-components-totals-coupon__form"
					className="wc-block-components-totals-coupon-link"
					aria-label={ __( 'Add a coupon', 'woocommerce' ) }
					onClick={ handleCouponAnchorClick }
				>
					{ __( 'Add a coupon', 'woocommerce' ) }
				</a>
			) : (
				<LoadingMask
					screenReaderLabel={ __(
						'Applying coupon…',
						'woocommerce'
					) }
					isLoading={ isLoading }
					showSpinner={ false }
				>
					<div className={ formWrapperClass }>
						<form
							className="wc-block-components-totals-coupon__form"
							id="wc-block-components-totals-coupon__form"
						>
							<ValidatedTextInput
								id={ textInputId }
								errorId="coupon"
								className="wc-block-components-totals-coupon__input"
								label={ __( 'Enter code', 'woocommerce' ) }
								value={ couponValue }
								ariaDescribedBy={ validationErrorId }
								onChange={ ( newCouponValue ) => {
									setCouponValue( newCouponValue );
								} }
								focusOnMount={ true }
								validateOnMount={ false }
								showError={ false }
							/>
							<Button
								className="wc-block-components-totals-coupon__button"
								disabled={ isLoading || ! couponValue }
								showSpinner={ isLoading }
								onClick={ handleCouponSubmit }
								type="submit"
							>
								{ __( 'Apply', 'woocommerce' ) }
							</Button>
						</form>
						<ValidationInputError
							propertyName="coupon"
							elementId={ textInputId }
						/>
					</div>
				</LoadingMask>
			) }
		</div>
	);
};

export default withInstanceId( TotalsCoupon );