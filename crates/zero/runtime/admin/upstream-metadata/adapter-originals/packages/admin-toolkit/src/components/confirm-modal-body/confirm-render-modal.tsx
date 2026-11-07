import { Button } from '@wordpress/ui';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Dialog } from '@zero-admin/design-system';
import type { ActionModal } from '@zero-admin/dataviews';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import styles from './style.module.css';

export type ConfirmModalIntent =
	| 'move-to-trash'
	| 'delete-permanently'
	| 'duplicate'
	| 'restore';

type BaseRenderModalProps< Item > = React.ComponentProps<
	ActionModal< Item >[ 'RenderModal' ]
>;

type ConfirmRenderModalLabelProps< Item > =
	| {
			intent: ConfirmModalIntent;
			onSubmit: (
				items: Item[],
				setIsLoading: ( isLoading: boolean ) => void
			) => Promise< void >;
	  }
	| {
			intent?: undefined;
			confirmLabel: string;
			cancelLabel?: string;
			isSubmitDestructive?: boolean;
			onSubmit: (
				items: Item[],
				setIsLoading: ( isLoading: boolean ) => void
			) => void;
	  };

export type ConfirmRenderModalConfigProps< Item > = Pick<
	BaseRenderModalProps< Item >,
	'onActionPerformed'
> & {
	message: ( items: Item[] ) => string;
} & ConfirmRenderModalLabelProps< Item >;

type ConfirmRenderModalProps< Item > = ConfirmRenderModalConfigProps< Item > &
	Pick< BaseRenderModalProps< Item >, 'closeModal' | 'items' > & {
		modalHeader?: string;
	};

const getDefaultConfirmLabel = ( intent?: ConfirmModalIntent ) => {
	switch ( intent ) {
		case 'move-to-trash':
			return __( 'Move to Trash', 'zero-admin' );
		case 'delete-permanently':
			return __( 'Delete permanently', 'zero-admin' );
		case 'duplicate':
			return __( 'Duplicate', 'zero-admin' );
		case 'restore':
			return __( 'Restore', 'zero-admin' );
		default:
			return __( 'Confirm', 'zero-admin' );
	}
};

export function ConfirmRenderModal< Item >( {
	intent,
	message,
	closeModal,
	onActionPerformed,
	onSubmit,
	items,
	modalHeader,
	...props
}: ConfirmRenderModalProps< Item > ) {
	const [ isLoading, setIsLoading ] = useState( false );
	const confirmLabel =
		( 'confirmLabel' in props && props.confirmLabel ) ||
		getDefaultConfirmLabel( intent );
	const cancelLabel =
		( 'cancelLabel' in props && props.cancelLabel ) ||
		__( 'Cancel', 'zero-admin' );
	const isDestructive =
		( 'isSubmitDestructive' in props && props.isSubmitDestructive ) ||
		intent === 'delete-permanently';

	return (
		<>
			{ modalHeader && (
				<Dialog.Header>
					<h1 className={ styles.heading }>{ modalHeader }</h1>
				</Dialog.Header>
			) }
			<p className={ styles.message }>{ message( items ) }</p>
			<Dialog.Footer>
				<Button
					variant="minimal"
					onClick={ closeModal }
					focusableWhenDisabled
				>
					{ cancelLabel }
				</Button>
				<Button
					className={
						isDestructive
							? styles[ 'destructive-action' ]
							: undefined
					}
					variant="solid"
					loading={ isLoading }
					loadingAnnouncement={ __( 'Processing', 'zero-admin' ) }
					onClick={ async () => {
						await onSubmit( items, setIsLoading );
						if ( onActionPerformed ) {
							onActionPerformed( items );
						}
						if ( closeModal ) {
							closeModal();
						}
						setIsLoading( false );
					} }
					focusableWhenDisabled
				>
					{ confirmLabel }
				</Button>
			</Dialog.Footer>
		</>
	);
}
