// Classic wp-admin's entry for a declared screen (lib/screens.php): mount it on the page's element.
import { mountClassic } from '@typedpress-admin/screens';

const element = document.getElementById( 'typedpress-admin-screen' );
if ( element ) {
	mountClassic( element, { id: element.dataset.screenId } );
}
