( function () {
	'use strict';

	var form = document.getElementById( 'pglp-redirect-form' );

	if ( ! form ) {
		return;
	}

	// Give the browser a moment to paint the message before leaving the page.
	window.setTimeout( function () {
		form.submit();
	}, 150 );
} )();
