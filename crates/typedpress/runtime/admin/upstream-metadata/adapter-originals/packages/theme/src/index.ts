import { unlock } from '@zero-admin/private-apis';
import { privateApis } from '@wordpress/theme';
import type { ReactNode } from 'react';

interface ThemeProviderProps {
	children: ReactNode;
	color?: {
		bg?: string;
		primary?: string;
	};
	isRoot?: boolean;
}

interface UseThemeProviderStylesOptions {
	color?: {
		bg?: string;
		primary?: string;
	};
}

interface UseThemeProviderStylesResult {
	themeProviderStyles: Record< string, string > | null;
}

interface UnlockedApis {
	ThemeProvider: React.ComponentType< ThemeProviderProps >;
	useThemeProviderStyles: (
		options?: UseThemeProviderStylesOptions
	) => UseThemeProviderStylesResult;
}

const unlockedApis: UnlockedApis = unlock( privateApis );

export const { ThemeProvider, useThemeProviderStyles } = unlockedApis;
