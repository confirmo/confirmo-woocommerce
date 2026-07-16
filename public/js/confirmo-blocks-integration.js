const settings = window.wc.wcSettings.getSetting('confirmo_data', {});
const label = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('Confirmo', 'confirmo-payment-gateway');
const Content = () => {
    return window.wp.htmlEntities.decodeEntities(settings.description || '');
};
const Confirmo_Block_Gateway = {
    name: 'confirmo',
    label: label,
    content: window.wp.element.createElement(Content, null),
    edit: window.wp.element.createElement(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Confirmo_Block_Gateway);

