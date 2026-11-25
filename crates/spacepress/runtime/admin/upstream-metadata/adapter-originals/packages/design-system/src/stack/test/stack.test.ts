import { getNormalizedGap } from '../stack';

describe( 'getNormalizedGap', () => {
	it( 'should return the gap as a CSS calculation when the gap is a positive number', () => {
		const result = getNormalizedGap( 1 );

		expect( result ).toBe( 'calc( 1 * var( --wpds-dimension-base ) )' );
	} );

	it( 'should return the gap as a literal value when the gap is a string', () => {
		const result = getNormalizedGap( '10px' );

		expect( result ).toBe( '10px' );
	} );
} );
