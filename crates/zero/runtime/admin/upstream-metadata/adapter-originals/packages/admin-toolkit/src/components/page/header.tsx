import clsx from 'clsx';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { __experimentalHeading as Heading } from '@wordpress/components';
import { SidebarToggleSlot } from './sidebar-toggle-slot';

export default function Header( {
	breadcrumbs,
	badges,
	title,
	subTitle,
	actions,
	tabs,
	showSidebarToggle = true,
}: {
	breadcrumbs?: React.ReactNode;
	badges?: React.ReactNode;
	title?: React.ReactNode;
	subTitle: React.ReactNode;
	actions?: React.ReactNode;
	tabs?: React.ReactNode;
	showSidebarToggle?: boolean;
} ) {
	return (
		<Stack
			direction="column"
			className={ clsx( 'zero-admin-page__header', {
				'has-tabs': tabs,
			} ) }
			render={ <header /> }
		>
			<Stack
				direction="row"
				className="zero-admin-page__header-title"
				justify="space-between"
				gap={ 2 }
				align="center"
			>
				<Stack direction="row" gap={ 2 } wrap="wrap" align="center">
					{ showSidebarToggle && (
						<SidebarToggleSlot
							bubblesVirtually
							className="zero-admin-page__sidebar-toggle-slot"
						/>
					) }
					{ title && (
						<Heading
							level={ 1 }
							size="15px"
							lineHeight="32px"
							truncate
						>
							{ title }
						</Heading>
					) }
					{ breadcrumbs }
					{ badges }
				</Stack>
				<Stack direction="row" gap={ 2 } align="center">
					{ actions }
				</Stack>
			</Stack>
			{ subTitle && (
				<p className="zero-admin-page__header-subtitle">{ subTitle }</p>
			) }
			{ tabs }
		</Stack>
	);
}
