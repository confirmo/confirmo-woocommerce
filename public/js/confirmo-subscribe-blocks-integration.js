const settings = window.wc.wcSettings.getSetting('confirmo_subscribe_data', {});
const label =
    window.wp.htmlEntities.decodeEntities(settings.title) ||
    window.wp.i18n.__('Subscribe with crypto (Confirmo)', 'confirmo-for-woocommerce');

const Content = () => {
    return window.wp.htmlEntities.decodeEntities(settings.description || '');
};

const Confirmo_Subscribe_Block_Gateway = {
    name: 'confirmo_subscribe',
    label: label,
    content: window.wp.element.createElement(Content, null),
    edit: window.wp.element.createElement(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(Confirmo_Subscribe_Block_Gateway);
