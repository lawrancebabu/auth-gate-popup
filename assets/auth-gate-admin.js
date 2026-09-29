(function ($) {
    'use strict';

    var i18n = window.AuthGatePopupAdmin || {};

    $(document).on('click', '.agp-upload-image', function (event) {
        event.preventDefault();

        var frame = wp.media({
            title: i18n.frameTitle || 'Select popup image',
            button: {
                text: i18n.frameButton || 'Use this image',
            },
            multiple: false,
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#agp-promo-image').val(attachment.url);
            $('.agp-image-preview').attr('src', attachment.url);
        });

        frame.open();
    });
})(jQuery);
