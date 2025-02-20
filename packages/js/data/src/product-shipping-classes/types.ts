/**
 * External dependencies
 */
import { DispatchFromMap } from '@automattic/data-stores';

/**
 * Internal dependencies
 */
import { CrudActions, CrudSelectors } from '../crud/types';
import { BaseQueryParams } from '../types';

export type CustomActions = {
	createProductShippingClass: (
		query: Partial< ProductShippingClass >,
		options: {
			optimisticQueryUpdate?: Partial< ProductShippingClass >;
			optimisticUrlParameters?: IdType[];
		}
	) => ProductShippingClass;
};

export type ProductShippingClass = {
	id: number;
	slug: string;
	name: string;
	description: string;
	count: number;
};

type Query = BaseQueryParams< keyof ProductShippingClass > & {
	context?: string;
	hide_empty?: boolean;
	slug?: string;
	product?: number;
};

type ReadOnlyProperties = 'id';

type MutableProperties = Omit< ProductShippingClass, ReadOnlyProperties >;

export type ProductShippingClassActions = CrudActions<
	'ProductShippingClass',
	ProductShippingClass,
	MutableProperties,
	'name'
> &
	CustomActions;

export type ProductShippingClassSelectors = CrudSelectors<
	'ProductShippingClass',
	'ProductShippingClasses',
	ProductShippingClass,
	Query,
	MutableProperties
>;

export type ActionDispatchers = DispatchFromMap< ProductShippingClassActions >;
