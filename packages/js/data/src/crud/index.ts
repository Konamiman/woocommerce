/**
 * External dependencies
 */
import { createReduxStore, register } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { createSelectors } from './selectors';
import { createDispatchActions } from './actions';
import defaultControls from '../controls';
import { createResolvers } from './resolvers';
import { createReducer } from './reducer';
import { Item } from '../items';

interface CrudStoreParams<
	ResourceName,
	ResourceNamePlural,
	Actions,
	Selectors,
	Resolvers,
	Controls,
	Reducer
> {
	storeName: string;
	resourceName: ResourceName;
	namespace: string;
	pluralResourceName: ResourceNamePlural;
	storeConfig?: {
		reducer?: Reducer;
		actions?: Actions;
		selectors?: Selectors;
		resolvers?: Resolvers;
		controls?: Controls;
	};
}

export const createCrudDataStore = <
	ResourceType extends Item,
	Actions extends Record< string, ( ...args: unknown[] ) => unknown >,
	Selectors
>( {
	storeName,
	resourceName,
	namespace,
	pluralResourceName,
	storeConfig,
}: CrudStoreParams<
	ResourceName,
	ResourceNamePlural,
	Actions,
	Selectors
> ) => {
	const crudActions = createDispatchActions< ResourceName, ResourceType >( {
		resourceName,
		namespace,
	} );
	const crudResolvers = createResolvers( {
		storeName,
		resourceName,
		pluralResourceName,
		namespace,
	} );

	const crudSelectors = createSelectors( {
		resourceName,
		pluralResourceName,
		namespace,
	} );

	const {
		reducer,
		actions = {},
		selectors = {},
		resolvers = {},
		controls = {},
	} = storeConfig || {};

	const crudReducer = createReducer( reducer );

	const store = createReduxStore< unknown, Actions, Selectors >( storeName, {
		reducer: crudReducer,
		actions: { ...crudActions, ...actions } as Actions,
		selectors: {
			...crudSelectors,
			...selectors,
		} as Selectors,
		resolvers: { ...crudResolvers, ...resolvers },
		controls: {
			...defaultControls,
			...controls,
		},
	} );

	register( store );

	return store;
};
