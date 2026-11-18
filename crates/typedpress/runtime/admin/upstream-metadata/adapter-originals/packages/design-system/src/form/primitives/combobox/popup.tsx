import { Combobox as _Combobox } from '@base-ui/react/combobox';
import { forwardRef } from 'react';
import clsx from 'clsx';
import { ThemeProvider } from '@first-admin/theme';
import itemPopupStyles from '../../../utils/css/item-popup.module.css';
import resetStyles from '../../../utils/css/resets.module.css';
import styles from './style.module.css';
import type { ComboboxPopupProps } from './types';
import { ITEM_POPUP_POSITIONER_PROPS } from '../constants';

export const Popup = forwardRef< HTMLDivElement, ComboboxPopupProps >(
	function Popup( { className, style, ...restProps }, ref ) {
		return (
			<_Combobox.Portal>
				<_Combobox.Positioner
					{ ...ITEM_POPUP_POSITIONER_PROPS }
					style={ style }
					className={ clsx(
						resetStyles[ 'box-sizing' ],
						styles.positioner,
						className
					) }
				>
					<ThemeProvider>
						<_Combobox.Popup
							ref={ ref }
							className={ itemPopupStyles.popup }
							{ ...restProps }
						/>
					</ThemeProvider>
				</_Combobox.Positioner>
			</_Combobox.Portal>
		);
	}
);
