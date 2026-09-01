( function ( wc, wp ) {
	'use strict';

	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
		return;
	}

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = wc.wcSettings.getSetting;
	var createElement = wp.element.createElement;
	var decodeEntities = wp.htmlEntities.decodeEntities;
	var __ = wp.i18n.__;

	var settings = getSetting( 'pglp_liqpay_data', {} );
	var defaultTitle = __( 'LiqPay', 'vitaliihura-checkout-for-liqpay' );
	var title = decodeEntities( settings.title || defaultTitle );

	function Description() {
		var children = [];
		var text = decodeEntities( settings.description || '' );

		if ( text ) {
			children.push( createElement( 'p', { key: 'text' }, text ) );
		}

		if ( settings.testMode ) {
			children.push(
				createElement(
					'p',
					{ key: 'test', className: 'pglp-test-notice' },
					decodeEntities( settings.testModeNotice || '' )
				)
			);
		}

		return children.length ? createElement( 'div', { className: 'pglp-description' }, children ) : null;
	}

	function Label( props ) {
		var components = props.components;
		var label = createElement( components.PaymentMethodLabel, { text: title } );

		if ( ! settings.iconUrl ) {
			return label;
		}

		// The block checkout lays the label out in a column of its own, so the row is set here
		// rather than in a stylesheet that would have to load on every checkout.
		return createElement(
			'span',
			{
				className: 'pglp-label',
				style: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' },
			},
			label,
			createElement( 'img', {
				src: settings.iconUrl,
				alt: title,
				className: 'pglp-label__icon',
				style: { maxHeight: '24px', width: 'auto' },
			} )
		);
	}

	registerPaymentMethod( {
		name: 'pglp_liqpay',
		paymentMethodId: 'pglp_liqpay',
		gatewayId: 'pglp_liqpay',
		label: createElement( Label ),
		content: createElement( Description ),
		edit: createElement( Description ),
		ariaLabel: title,
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
			showSavedCards: !! settings.showSavedCards,
			showSaveOption: !! settings.showSavedCards,
		},
	} );
} )( window.wc, window.wp );
