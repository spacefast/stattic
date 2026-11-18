import {
	createContext,
	useContext,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import type { PropsWithChildren } from 'react';

type EntityValidityContextValue = {
	isEntityValid: boolean;
	setIsEntityValid: ( isEntityValid: boolean ) => void;
};

const EntityValidityContext = createContext<
	EntityValidityContextValue | undefined
>( undefined );

export function EntityValidityProvider( { children }: PropsWithChildren ) {
	const [ isEntityValid, setIsEntityValid ] = useState( true );

	const contextValue = useMemo(
		() => ( {
			isEntityValid,
			setIsEntityValid,
		} ),
		[ isEntityValid ]
	);

	return (
		<EntityValidityContext.Provider value={ contextValue }>
			{ children }
		</EntityValidityContext.Provider>
	);
}

export function useEntityValidity() {
	const context = useContext( EntityValidityContext );
	if ( ! context ) {
		throw new Error(
			'useEntityValidity must be used within EntityValidityProvider.'
		);
	}

	return context;
}

export function useSetEntityValidity( isValid: boolean ) {
	const { setIsEntityValid } = useEntityValidity();

	useEffect( () => {
		setIsEntityValid( isValid );
	}, [ isValid, setIsEntityValid ] );
}
