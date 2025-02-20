/**
 * Internal dependencies
 */
import { STORE_NAME, WC_PRODUCT_SHIPPING_CLASSES_NAMESPACE } from './constants';
import { createCrudDataStore } from '../crud';
import {
	ProductShippingClass,
	ProductShippingClassActions,
	ProductShippingClassSelectors,
} from './types';

export const store = createCrudDataStore<
	ProductShippingClass,
	ProductShippingClassActions,
	ProductShippingClassSelectors
>( {
	storeName: STORE_NAME,
	resourceName: 'ProductShippingClass',
	pluralResourceName: 'ProductShippingClasses',
	namespace: WC_PRODUCT_SHIPPING_CLASSES_NAMESPACE,
} );

export const EXPERIMENTAL_PRODUCT_SHIPPING_CLASSES_STORE_NAME = STORE_NAME;
