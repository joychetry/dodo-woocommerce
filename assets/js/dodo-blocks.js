/**
 * Dodo Payments for the WooCommerce checkout block.
 *
 * No card fields: the block submits the order and follows the redirect that
 * the gateway's process_payment() returns.
 *
 * @package Dodo_Payments
 * @since 1.1.0
 */
(function (wc, wp) {
    'use strict';

    var settings = wc.wcSettings.getSetting('dodo_payments_data', {});
    var el = wp.element.createElement;
    var decode = wp.htmlEntities.decodeEntities;
    var title = decode(settings.title || '') || wp.i18n.__('Dodo Payments', 'dodo-payments-for-woocommerce');

    var Content = function () {
        // Description is admin-set and passed through kses server-side.
        return el('div', { dangerouslySetInnerHTML: { __html: settings.description || '' } });
    };

    var Label = function () {
        return el(
            'span',
            { style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
            title,
            settings.icon ? el('img', { src: settings.icon, alt: '', style: { height: '24px' } }) : null
        );
    };

    wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'dodo_payments',
        label: el(Label),
        ariaLabel: title,
        content: el(Content),
        edit: el(Content),
        canMakePayment: function () {
            return true;
        },
        supports: { features: settings.supports }
    });
})(window.wc, window.wp);
