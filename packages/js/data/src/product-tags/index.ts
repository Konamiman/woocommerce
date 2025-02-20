/**
 * Internal dependencies
 */
import { STORE_NAME, WC_PRODUCT_TAGS_NAMESPACE } from './constants';
import { createCrudDataStore } from '../crud';
import { ProductTag, ProductTagActions, ProductTagSelectors } from './types';

export const store = createCrudDataStore<
	ProductTag,
	ProductTagActions,
	ProductTagSelectors
>( {
	storeName: STORE_NAME,
	resourceName: 'ProductTag',
	pluralResourceName: 'ProductTags',
	namespace: WC_PRODUCT_TAGS_NAMESPACE,
} );

export const EXPERIMENTAL_PRODUCT_TAGS_STORE_NAME = STORE_NAME;
