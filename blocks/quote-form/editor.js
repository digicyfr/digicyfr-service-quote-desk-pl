( function ( blocks, components, element, labels ) {
	'use strict';
	var el = element.createElement;
	blocks.registerBlockType( 'digicyfr/service-quote-form', {
		title: labels.title,
		description: labels.description,
		icon: 'email-alt2',
		category: 'widgets',
		edit: function () {
			return el( components.Placeholder, { icon: 'email-alt2', label: labels.title }, el( 'p', null, labels.preview ) );
		},
		save: function () { return null; }
	} );
} )( wp.blocks, wp.components, wp.element, digicyfrQuoteDeskLabels );
