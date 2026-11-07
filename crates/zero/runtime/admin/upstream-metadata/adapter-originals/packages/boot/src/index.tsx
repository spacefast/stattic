export { default as init } from './components/app';
export {
	updateMenuItem,
	setNudges,
	registerDashboardNotice,
	unregisterDashboardNotice,
	updateDashboardNotice,
	registerModal,
	unregisterModal,
	setDashboardContext,
	unregisterSiteHubHelpMenuItem,
} from './api';
export { buildSitemapData, preloadSitemapData } from './utils/sitemap-context';
export { store } from './store';
export type {
	StoreSelectors,
	Nudge,
	DashboardNoticeConfig,
	EntityDependency,
	RegisteredModal,
} from './store/types';
import './view-transitions.scss';
