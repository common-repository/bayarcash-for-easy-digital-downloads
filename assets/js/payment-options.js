jQuery(document).ready(function($) {
    const firstOption = $(".bayarcash-payment-option input[type=radio]").first();
    if (firstOption.length) {
        firstOption.prop('checked', true).trigger('change');
    }
});