import { ThemeProvider } from '@first-admin/theme';
import { __unstableMotion as motion } from '@wordpress/components';
import { Icon, arrowUpLeft } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { useReducedMotion } from '@wordpress/compose';
import useColorPreference from '../../hooks/use-color-preference';
import SiteIconLink from '../site-icon-link';
import './style.scss';

const toggleHomeIconVariants = {
	edit: {
		opacity: 0,
		scale: 0.2,
	},
	hover: {
		opacity: 1,
		scale: 1,
		clipPath: 'inset( 22% round 2px )',
	},
};

export default function SiteIconBackButton( { to }: { to: string } ) {
	const { themeColorSettings } = useColorPreference();
	const disableMotion = useReducedMotion();
	const transition = {
		duration: disableMotion ? 0 : 0.3,
	};
	return (
		<ThemeProvider color={ themeColorSettings.sidebar }>
			<motion.div
				transition={ transition }
				animate="edit"
				initial="edit"
				whileHover="hover"
				whileTap="tap"
			>
				<SiteIconLink
					to={ to }
					isBackButton
					aria-label={ __( 'Go back', 'first-admin' ) }
				/>
				<motion.div
					className="first-admin-site-icon-back-button__icon"
					variants={ toggleHomeIconVariants }
				>
					<Icon icon={ arrowUpLeft } />
				</motion.div>
			</motion.div>
		</ThemeProvider>
	);
}
