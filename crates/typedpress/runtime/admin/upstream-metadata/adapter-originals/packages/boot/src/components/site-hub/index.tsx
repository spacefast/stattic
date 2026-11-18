import { IconButton, Link, Stack } from '@wordpress/ui';
import { SiteIconLink } from '@zero-admin/admin-toolkit';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as coreStore } from '@wordpress/core-data';
import { decodeEntities } from '@wordpress/html-entities';
import { search } from '@wordpress/icons';
import { rawShortcut, displayShortcut } from '@wordpress/keycodes';
// @ts-expect-error Commands package is not typed yet.
import { store as commandsStore } from '@wordpress/commands';
import { filterURLForDisplay } from '@wordpress/url';
import type { UnstableBase } from '@wordpress/core-data';
import './style.scss';

const OPEN_COMMAND_BAR_SHORTCUT_INFO = {
	displayShortcut: displayShortcut.primary( 'k' ),
	// TODO: use `ariaKeyShortcut` instead of `rawShortcut` once
	// https://github.com/WordPress/gutenberg/pull/74205 is merged
	ariaKeyShortcut: rawShortcut.primary( 'k' ),
};

function SiteHub() {
	const { homeUrl, siteTitle } = useSelect( ( select ) => {
		const { getEntityRecord } = select( coreStore );
		const _base = getEntityRecord< UnstableBase >(
			'root',
			'__unstableBase'
		);
		return {
			homeUrl: _base?.home,
			siteTitle:
				! _base?.name && !! _base?.url
					? filterURLForDisplay( _base?.url )
					: _base?.name,
		};
	}, [] );

	// eslint-disable-next-line @typescript-eslint/no-unsafe-argument, @typescript-eslint/no-unsafe-type-assertion -- Commands package is not typed yet.
	const { open: openCommandCenter } = useDispatch( commandsStore ) as {
		open: () => void;
	};
	const label = __( 'Open command palette', 'zero-admin' );

	return (
		<div className="zero-admin-site-hub">
			<SiteIconLink
				to="/"
				aria-label={ __( 'Go to the Dashboard', 'zero-admin' ) }
			/>
			<Link
				href={ homeUrl ?? '/' }
				openInNewTab
				className="zero-admin-site-hub__title"
			>
				<div className="zero-admin-site-hub__title-text">
					{ siteTitle && decodeEntities( siteTitle ) }
				</div>
				<div className="zero-admin-site-hub__url">
					{ homeUrl ? filterURLForDisplay( homeUrl ) : '/' }
				</div>
			</Link>
			<Stack direction="row" className="zero-admin-site-hub__actions">
				<IconButton
					variant="minimal"
					tone="neutral"
					icon={ search }
					onClick={ () => openCommandCenter() }
					size="compact"
					label={ label }
					shortcut={ OPEN_COMMAND_BAR_SHORTCUT_INFO }
				/>
			</Stack>
		</div>
	);
}

export default SiteHub;
