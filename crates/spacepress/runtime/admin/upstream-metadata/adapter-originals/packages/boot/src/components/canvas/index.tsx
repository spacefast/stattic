import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { privateApis as routePrivateApis } from '@wordpress/route';
import { useEffect, useState } from '@wordpress/element';
import { ProgressBar } from '@wordpress/components';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { unlock } from '@zero-admin/admin-toolkit';
import type {
	FrontPageCanvas,
	PostCanvas,
	RouteCanvas,
} from '../../store/types';
import Editor from '../editor';
import EditorPreview from '../editor-preview';
import FrontEndPreview from '../front-end-preview';
import { canvasLoader } from '../app/canvas-loader';

// unlock() returns `any` — useLoaderData is untyped until @wordpress/route exports proper types.
// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment
const { useLoaderData } = unlock( routePrivateApis );

function CanvasEditor( canvas: PostCanvas | FrontPageCanvas ) {
	const postType = 'postType' in canvas ? canvas.postType : undefined;
	const postId = 'postId' in canvas ? canvas.postId : undefined;

	// Resolve homepage when no postType/postId provided
	const homePage = useSelect(
		( select ) => {
			// Only resolve homepage when both postType and postId are missing
			if ( postType || postId ) {
				return null;
			}
			const { getHomePage } = unlock( select( coreStore ) );
			return getHomePage();
		},
		[ postType, postId ]
	);

	// Use provided postType/postId, or fall back to homepage resolution
	const resolvedPostType = postType || homePage?.postType;
	const resolvedPostId = postId || homePage?.postId;

	// Resolve template ID from post type/ID
	const templateId = useSelect(
		( select ) => {
			if ( ! resolvedPostType || ! resolvedPostId ) {
				return undefined;
			}
			if ( resolvedPostType === 'wp_template' ) {
				return resolvedPostId;
			}
			// Use private API to get template ID for this post
			return unlock( select( coreStore ) ).getTemplateId(
				resolvedPostType,
				resolvedPostId
			);
		},
		[ resolvedPostType, resolvedPostId ]
	);

	return (
		<Editor
			postType={ resolvedPostType }
			postId={ resolvedPostId }
			templateId={ templateId }
			isPreview={ canvas.isPreview }
			editLink={ canvas.editLink }
		/>
	);
}

function DefaultCanvas(
	props: Exclude< RouteCanvas, undefined > & {
		template?: Record< string, unknown >[];
	}
) {
	if ( 'url' in props ) {
		return <FrontEndPreview url={ props.url } />;
	}

	if (
		props.template &&
		props.template.length > 0 &&
		'postType' in props &&
		'postId' in props
	) {
		// REFACTOR: This is a temporary solution to support the editor preview not tied to a postId/templateId.
		// We should use the `Editor` component from `@wordpress/editor` package instead when it's ready to accept template as a prop.
		return (
			<EditorPreview
				template={ props.template }
				postType={ props.postType }
				postId={ props.postId }
			/>
		);
	}

	return <CanvasEditor { ...props } />;
}

const getCanvasData = async (
	canvas: RouteCanvas,
	canvasLoaded?: Promise< void >
) => {
	// Await the canvasLoader promise that was started during route loading.
	// Falls back to calling canvasLoader directly if no promise was provided
	// (e.g., for routes that don't go through the standard loader path).
	await ( canvasLoaded ?? canvasLoader( canvas ) );
	const template =
		canvas && 'getTemplate' in canvas
			? await canvas.getTemplate?.()
			: undefined;
	return {
		template,
	};
};

type CanvasLoaderData = {
	canvas?: RouteCanvas;
	canvasLoaded?: Promise< void >;
};

// useLoaderData returns `any` — all values destructured from loader data are untyped.
/* eslint-disable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-argument */
export default function Canvas( {
	currentRouteId,
}: {
	currentRouteId: string;
} ) {
	const { canvas, canvasLoaded } = ( useLoaderData( {
		from: currentRouteId,
	} ) ?? {} ) as CanvasLoaderData;

	const [ canvasData, setCanvasData ] = useState< {
		isLoaded: boolean;
		template: Record< string, unknown >[] | undefined;
	} >( {
		isLoaded: false,
		template: [],
	} );

	useEffect( () => {
		let ignore = false;
		if ( canvas ) {
			getCanvasData( canvas, canvasLoaded )
				.then( ( data ) => {
					if ( ! ignore ) {
						setCanvasData( {
							isLoaded: true,
							template: data.template,
						} );
					}
				} )
				.catch( () => {
					// Canvas loading failed (network error, missing data,
					// etc.). Mark as loaded so the user isn't stuck on an
					// infinite progress bar.
					if ( ! ignore ) {
						setCanvasData( {
							isLoaded: true,
							template: undefined,
						} );
					}
				} );
		}
		return () => {
			ignore = true;
		};
	}, [ canvas, canvasLoaded ] );

	const { component, ...canvasProps } = ( canvas ?? {} ) as {
		component?: typeof DefaultCanvas;
	};

	const CanvasComponent = component ?? DefaultCanvas;

	if ( ! canvasData.isLoaded ) {
		return (
			<Stack
				direction="column"
				align="center"
				justify="center"
				style={ { height: '100%', width: '100%' } }
				gap={ 2 }
			>
				<ProgressBar />
			</Stack>
		);
	}

	return (
		<CanvasComponent
			{ ...canvasProps }
			template={ canvasData.template ?? [] }
		/>
	);
}
/* eslint-enable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-argument */
