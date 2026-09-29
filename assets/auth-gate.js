(function ($) {
    'use strict';

    function setMessage(message, isSuccess) {
        $('.agp-message')
            .text(message || '')
            .toggleClass('is-success', !!isSuccess)
            .toggleClass('is-visible', !!message);
    }

    function switchTab(tab) {
        $('.agp-tab').removeClass('is-active').attr('aria-selected', 'false');
        $('.agp-tab[data-tab="' + tab + '"]').addClass('is-active').attr('aria-selected', 'true');
        $('.agp-form').removeClass('is-active');
        $('.agp-form[data-form="' + tab + '"]').addClass('is-active');
        setMessage('');
    }

    $(document).on('click', '.agp-tab', function () {
        switchTab($(this).data('tab'));
    });

    $(document).on('click', '.agp-lost-link', function (event) {
        event.preventDefault();
        switchTab('lost-password');
        $('.agp-form[data-form="lost-password"] input:visible:first').trigger('focus');
    });

    $(document).on('click', '.agp-back-login', function () {
        switchTab('login');
        $('.agp-form[data-form="login"] input:visible:first').trigger('focus');
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && $('.agp-overlay').length) {
            event.preventDefault();
            event.stopPropagation();
        }
    });

    $(document).on('submit', '.agp-form', function (event) {
        event.preventDefault();

        var $form = $(this);
        var $button = $form.find('.agp-submit');
        var originalText = $button.text();

        setMessage(AuthGatePopup.messages.working, true);
        $button.prop('disabled', true).text(AuthGatePopup.messages.working);

        $.ajax({
            url: AuthGatePopup.ajaxUrl,
            method: 'POST',
            data: $form.serialize(),
        })
            .done(function (response) {
                if (response && response.success) {
                    setMessage(response.data.message, true);
                    if ($form.data('form') === 'lost-password') {
                        $form[0].reset();
                        return;
                    }

                    window.location.reload();
                    return;
                }

                setMessage(
                    response && response.data && response.data.message
                        ? response.data.message
                        : AuthGatePopup.messages.genericError
                );
            })
            .fail(function (xhr) {
                var message = AuthGatePopup.messages.genericError;

                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }

                setMessage(message);
            })
            .always(function () {
                $button.prop('disabled', false).text(originalText);
            });
    });

    $(function () {
        if (!$('.agp-overlay').length) {
            return;
        }

        $('.agp-hero img').one('error', function () {
            var fallback = $(this).data('fallback-src') || AuthGatePopup.fallbackImageUrl;

            if (fallback && this.src !== fallback) {
                this.src = fallback;
            }
        });

        $('html, body').addClass('agp-locked');
        $('.agp-form.is-active input:visible:first').trigger('focus');
    });
})(jQuery);
