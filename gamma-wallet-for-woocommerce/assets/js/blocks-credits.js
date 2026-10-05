/*
 * "Use Store Credits with Gamma" in the block-based checkout. Plain script, no build step: it uses
 * the globals WooCommerce Blocks and WordPress provide.
 */
( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! wcSettings ) {
		return;
	}
	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var data = wcSettings.getSetting( 'gamma_wallet_credits_data', {} );
	var title = decode( data.title || 'Use Store Credits with Gamma' );

	function Content() {
		return el( 'p', null, decode( data.description || '' ) );
	}

	registry.registerPaymentMethod( {
		name: 'gamma_wallet_credits',
		label: el(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '0.5em' } },
			data.icon ? el( 'img', { src: data.icon, alt: '', width: 24, height: 24, style: { width: '24px', height: '24px' } } ) : null,
			title
		),
		ariaLabel: title,
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
