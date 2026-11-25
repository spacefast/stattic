// Classic wp-admin's entry for a declared screen (lib/screens.php): mount it on the page's element.
import { mountClassic } from '@spacepress-admin/screens';

const element = document.getElementById( 'spacepress-admin-screen' );
if ( element ) {
	mountClassic( element, { id: element.dataset.screenId } );
}
