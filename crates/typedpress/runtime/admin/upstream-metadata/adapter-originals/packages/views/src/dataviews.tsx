import type React from 'react';
import { DataViews as CoreDataViews } from '@zero-admin/dataviews';
import './style.scss';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';

type DataViewsProps< T > = Parameters< typeof CoreDataViews< T > >[ 0 ] & {
	viewActions?: React.ReactNode;
	toolbarActions?: React.ReactNode;
	tabs?: React.ReactNode;
	showSearch?: boolean;
	showLayoutSwitcher?: boolean;
};

export function DataViews< Item >( {
	viewActions,
	toolbarActions,
	tabs,
	showSearch = true,
	showLayoutSwitcher = false,
	...props
}: DataViewsProps< Item > ) {
	const { view } = props;
	return (
		// @ts-expect-error React doesn't to like generics.
		<CoreDataViews { ...props }>
			<Stack
				// Not great but reusing the same class as Core to avoid recreating CSS
				className="zero-admin-dataviews__view-actions"
				direction="row"
				justify="space-between"
				align="center"
				// Add bottom spacing below the tabs to separate them from grid view content.
				style={ view.type === 'grid' ? { marginBottom: '20px' } : {} }
			>
				<Stack direction="row" align="center" gap={ 2 }>
					{ tabs }
				</Stack>
				<Stack direction="row" align="center" gap={ 2 }>
					{ viewActions }
					{ showSearch && <CoreDataViews.Search /> }
					{ toolbarActions }
					<CoreDataViews.FiltersToggle />
					{ showLayoutSwitcher && <CoreDataViews.LayoutSwitcher /> }
					{ <CoreDataViews.ViewConfig /> }
				</Stack>
			</Stack>
			<div className="zero-admin-dataviews-filters__container">
				<CoreDataViews.Filters />
			</div>
			<CoreDataViews.Layout />
			<CoreDataViews.Footer />
		</CoreDataViews>
	);
}
