$(document).ready(function() {
    $('#acctBtn').on('click', function (e) {
        var open = $('#acctMenu').toggleClass('is-open').hasClass('is-open');
        $(this).toggleClass('is-active', open).attr('aria-expanded', open);
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#acctMenu, #acctBtn').length) {
            $('#acctMenu').removeClass('is-open');
            $('#acctBtn').removeClass('is-active').attr('aria-expanded', false);
        }
    });

    $(document).on('click', '.app-logout', function (e) {
        ApiDataSvc.apiCall('post', 'logout', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                window.location.reload();
            } else {
                toastr.error(o.message);
            }
        });
    });

    // Global loading bar — shows on every AJAX request so users see activity.
    var $loadbar = $('<div id="app-loadbar"></div>').appendTo('body');
    $(document).ajaxStart(function () {
        $loadbar.removeClass('is-done').addClass('is-active');
    });
    $(document).ajaxStop(function () {
        $loadbar.removeClass('is-active').addClass('is-done');
    });

});