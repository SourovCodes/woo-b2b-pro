/**
 * Registers the pay-by-invoice payment method with the checkout block.
 *
 * Availability is decided server-side (BlocksSupport::is_active), so this
 * script only renders the label and description.
 */
( function () {
	'use strict';

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var createElement = window.wp.element.createElement;

	var settings = getSetting( 'wb2b_invoice_data', {} );
	var label = decodeEntities( settings.title || 'Pay by invoice' );

	function Content() {
		return createElement( 'div', null, decodeEntities( settings.description || '' ) );
	}

	registerPaymentMethod( {
		name: 'wb2b_invoice',
		label: label,
		ariaLabel: label,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
