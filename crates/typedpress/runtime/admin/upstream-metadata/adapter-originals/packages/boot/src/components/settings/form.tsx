import { Button } from '@wordpress/ui';
import type { Form } from '@zero-admin/dataviews';
import { DataForm } from '@zero-admin/dataviews';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore, type Settings } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { fields } from './fields';

export default function Form( { form }: { form: Form } ) {
	const { data, isDirty, isSaving } = useSelect(
		( select ) => ( {
			// @ts-expect-error - getEditedEntityRecord root, site is not typed
			data: select( coreStore ).getEditedEntityRecord(
				'root',
				'site'
			) as Settings | undefined,
			// @ts-expect-error - isSavingEntityRecord root, site is not typed
			isSaving: select( coreStore ).isSavingEntityRecord(
				'root',
				'site'
			),
			isDirty:
				Object.keys(
					// @ts-expect-error - getEntityRecordEdits root, site is not typed
					select( coreStore ).getEntityRecordEdits(
						'root',
						'site'
					) ?? {}
				).length > 0,
		} ),
		[]
	);
	const { saveEditedEntityRecord, editEntityRecord } =
		useDispatch( coreStore );
	const [ errorMessage, setErrorMessage ] = useState< string | undefined >();
	const [ isSuccess, setIsSuccess ] = useState< true | undefined >();
	useEffect( () => {
		if ( isDirty ) {
			setIsSuccess( undefined );
		}
	}, [ isDirty ] );
	if ( ! data ) {
		return null;
	}
	async function onSubmit( event: React.FormEvent< HTMLFormElement > ) {
		event.preventDefault();

		try {
			// @ts-expect-error - saveEditedEntityRecord root, site is not typed
			const result = await saveEditedEntityRecord( 'root', 'site' );
			// saveEntityRecord does not throw when the REST API errors.
			if ( ! result ) {
				throw new Error( 'Failed to save settings' );
			}
		} catch ( error ) {
			if ( error instanceof Error ) {
				setErrorMessage( error.message );
			}
			return;
		}

		setIsSuccess( true );
	}
	let saveButtonLabel: string = __( 'Save', 'zero-admin' );
	if ( isSaving ) {
		saveButtonLabel = __( 'Saving…', 'zero-admin' );
	} else if ( isSuccess && ! isDirty ) {
		saveButtonLabel = __( 'Saved!', 'zero-admin' );
	}
	return (
		<form onSubmit={ onSubmit }>
			<DataForm
				data={ data }
				fields={ fields }
				form={ form }
				onChange={ ( value ) => {
					// @ts-expect-error - editEntityRecord root, site is not typed
					editEntityRecord( 'root', 'site', undefined, value );
				} }
			/>
			<br />
			{ errorMessage && (
				<>
					<Notice status="error" isDismissible={ false }>
						{ errorMessage }
					</Notice>
					<br />
				</>
			) }
			<Button type="submit" disabled={ ! isDirty }>
				{ saveButtonLabel }
			</Button>
		</form>
	);
}
