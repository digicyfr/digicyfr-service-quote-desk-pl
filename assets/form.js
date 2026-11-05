( function () {
	'use strict';
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.matches( '.digicyfr-quote-desk form' ) ) { return; }
		var email = form.querySelector( '[name="email"]' );
		var phone = form.querySelector( '[name="phone"]' );
		if ( ! email || ! phone ) { return; }
		email.setCustomValidity( '' );
		if ( ! email.value.trim() && ! phone.value.trim() ) {
			var hint = form.querySelector( '[data-contact-hint]' );
			email.setCustomValidity( hint ? hint.textContent.trim() : 'Provide an email or phone number.' );
			email.reportValidity();
			event.preventDefault();
		}
	} );
	document.addEventListener( 'input', function ( event ) {
		var form = event.target.closest( '.digicyfr-quote-desk form' );
		if ( form && ( event.target.name === 'email' || event.target.name === 'phone' ) ) {
			var email = form.querySelector( '[name="email"]' );
			if ( email ) { email.setCustomValidity( '' ); }
		}
	} );
} )();
