function appendLogo() {
    var imageUrl = confirmoParams.imageUrl;
    jQuery('.payment_method_confirmo label img.confirmo-logo').remove(); // Remove existing logo if present
    jQuery('.payment_method_confirmo label').append('<img class="confirmo-logo" src="' + imageUrl + '" alt="Confirmo logo" />');
}

jQuery(document).ready(function($) {
    appendLogo(); // Append logo on document ready
});

jQuery(document.body).on('updated_checkout', function() {
    appendLogo(); // Append logo after checkout update
});
