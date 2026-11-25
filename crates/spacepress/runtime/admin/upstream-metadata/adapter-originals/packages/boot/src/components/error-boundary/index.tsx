import { Component, type ReactNode } from 'react';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { Page } from '@zero-admin/admin-toolkit';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

interface Props {
	children: ReactNode;
}

interface State {
	hasError: boolean;
	error?: Error;
}

export class ErrorBoundary extends Component< Props, State > {
	constructor( props: Props ) {
		super( props );
		this.state = { hasError: false };
	}

	static getDerivedStateFromError( error: Error ): State {
		return { hasError: true, error };
	}

	componentDidCatch( error: Error, errorInfo: React.ErrorInfo ) {
		// Log error for debugging
		// eslint-disable-next-line no-console
		console.error( `Error in surface:`, error, errorInfo );
	}

	render() {
		if ( this.state.hasError ) {
			return (
				<Page title={ __( 'Something went wrong', 'zero-admin' ) }>
					<Stack direction="column" gap={ 4 } align="flex-start">
						<div style={ { width: '100%' } }>
							<Notice status="error" isDismissible={ false }>
								{ __(
									`An error occurred while loading the content.`,
									'zero-admin'
								) }
							</Notice>
						</div>

						{ process.env.NODE_ENV === 'development' &&
							this.state.error && (
								<details
									style={ {
										marginTop: '1rem',
										textAlign: 'left',
									} }
								>
									<summary>
										{ __( 'Error Details', 'zero-admin' ) }
									</summary>
									<pre
										style={ {
											background: '#f5f5f5',
											padding: '1rem',
											borderRadius: '4px',
											fontSize: '12px',
											overflow: 'auto',
											maxHeight: '200px',
										} }
									>
										{ this.state.error.stack }
									</pre>
								</details>
							) }
						<Button
							variant="primary"
							onClick={ () => {
								this.setState( {
									hasError: false,
									error: undefined,
								} );
							} }
						>
							{ __( 'Try Again', 'zero-admin' ) }
						</Button>
					</Stack>
				</Page>
			);
		}

		return this.props.children;
	}
}
