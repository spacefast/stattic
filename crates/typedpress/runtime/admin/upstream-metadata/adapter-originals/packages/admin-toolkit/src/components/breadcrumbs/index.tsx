import { Link } from '@wordpress/route';
import { __experimentalHeading as Heading } from '@wordpress/components';
import { Link as DSLink } from '@wordpress/ui';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { __ } from '@wordpress/i18n';
import './style.scss';
import type {
	BreadcrumbsProps,
	BreadcrumbItem as BreadcrumbItemType,
} from './types';

const BreadcrumbItem = ( {
	item: { label, to, onClick },
}: {
	item: BreadcrumbItemType;
} ) => {
	if ( ! to ) {
		return (
			<li>
				<Heading level={ 1 } truncate>
					{ label }
				</Heading>
			</li>
		);
	}

	return (
		<li>
			<DSLink
				tone="neutral"
				render={ <Link to={ to } /> }
				onClick={ onClick }
			>
				{ label }
			</DSLink>
		</li>
	);
};

export const Breadcrumbs = ( { items }: BreadcrumbsProps ) => {
	if ( ! items.length ) {
		return null;
	}

	return (
		<nav
			className="zero-admin-breadcrumbs"
			aria-label={ __( 'Breadcrumbs', 'zero-admin' ) }
		>
			<Stack
				className="admin-tookit-breadcrumbs__list"
				render={ <ul /> }
				gap={ 0 }
				justify="flex-start"
				align="center"
			>
				{ items.map( ( item, index ) => (
					<BreadcrumbItem key={ index } item={ item } />
				) ) }
			</Stack>
		</nav>
	);
};

export default Breadcrumbs;
