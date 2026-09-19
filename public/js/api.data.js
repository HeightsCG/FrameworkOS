"use strict";

var hostname = window.location.hostname;
var protocol = window.location.protocol;
var url = protocol+"//"+hostname+"/api/";

var storage = window.localStorage;

var ApiDataSvc = {
    baseUrl: url,
    apiCall: apiCall
};

// Send CSRF token as header on all AJAX POST requests (covers FormData uploads)
$.ajaxSetup({
    beforeSend: function(xhr, settings) {
        if (settings.type && settings.type.toUpperCase() === 'POST') {
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                xhr.setRequestHeader('X-CSRF-TOKEN', csrfMeta.getAttribute('content'));
            }
        }
    }
});

// Header scroll detection for glassmorphism effect
$(document).ready(function() {
    var header = $('#mainHeader');
    
    $(window).scroll(function() {
        if ($(window).scrollTop() > 50) {
            header.addClass('scrolled');
        } else {
            header.removeClass('scrolled');
        }
    });
});

function apiCall(type, endpoint, body, success, callFail) {

    var url  = this.baseUrl + endpoint;

    // Attach CSRF token to POST requests
    if (type === 'post' && body && typeof body === 'object' && !(body instanceof FormData)) {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            body.csrf_token = csrfMeta.getAttribute('content');
        }
    }

    switch (type) {
        case 'get':
            $.get(url, body, success, callFail);
            break;

        case 'post':
            $.post(url, body, function (data) {
                // Plan / AI-credit refusals get one uniform toast that opens Billing.
                // Pages still get the raw response; their own toastr.error is deduped.
                var o = null;
                try { o = (typeof data === 'string') ? JSON.parse(data) : data; } catch (e) {}
                if (o && o.success === false && (o.need_plan || o.need_upgrade || o.need_credits) && window.toastr) {
                    toastr.options.preventDuplicates = true;
                    toastr.error(o.message, o.need_plan ? 'Choose a plan' : (o.need_credits ? 'Buy AI credits' : 'Upgrade your plan'), {
                        timeOut: 8000, extendedTimeOut: 4000,
                        onclick: function () { window.location.href = '/account/billing'; }
                    });
                }
                if (typeof success === 'function') { success(data); }
            }, callFail);
            break;

        case 'put':
            $.put(url, body, success, callFail);
            break;

        case 'delete':
            $.delete(url, body, success, callFail);
            break;

        default:
            callFailed();
    }

    function callFailed() {
        var conn = checkConnection();
        sweetAlert("Connection Error", "Please try again later..."+conn, "error");
    }
    
    function checkConnection() {
        var online = navigator.onLine;
        return online;
    }

}
