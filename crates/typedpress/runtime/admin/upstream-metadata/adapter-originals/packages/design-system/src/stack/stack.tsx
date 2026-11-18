import { type CSSProperties, forwardRef } from 'react';
import { useRender, mergeProps } from '@base-ui/react';
import clsx from 'clsx';
import { type StackProps } from './types';
import styles from './style.module.css';

/**
 * Normalizes the gap value. When given a positive number, it will be converted
 * to a CSS calculation. When given a string, it will be returned as is.
 *
 * @param gap The gap value to normalize.
 *
 * @return The normalized gap value.
 */
export const getNormalizedGap = ( gap: number | CSSProperties[ 'gap' ] ) =>
	typeof gap === 'number'
		? `calc( ${ gap } * var( --wpds-dimension-base ) )`
		: gap;

/**
 * A flexible layout component using CSS Flexbox for consistent spacing and alignment.
 * Built on design tokens for predictable spacing values.
 */
export const Stack = forwardRef< HTMLDivElement, StackProps >( function Stack(
	{
		direction,
		gap = 'initial',
		align = 'initial',
		justify = 'initial',
		wrap,
		render,
		...props
	},
	ref
) {
	const className = clsx(
		styles.stack,
		styles[ `is-direction-${ direction }` ],
		styles[ `is-${ wrap }` ]
	);

	const style: CSSProperties = {
		'--wp-ui-stack-gap': getNormalizedGap( gap ),
		'--wp-ui-stack-align': align,
		'--wp-ui-stack-justify': justify,
	};

	const element = useRender( {
		render,
		ref,
		props: mergeProps< 'div' >( { style, className }, props ),
	} );

	return element;
} );
