"use strict";
/* A page left open outlives its session, and the request token goes with it. The API then answers csrf_expired with a
   fresh token: store it and send the same request once more, so the person never sees the error. Loaded after api.data.js. */
$.ajaxPrefilter(function (options, original) {
    if (!options.type || options.type.toUpperCase() !== 'POST' || original.csrf_retried || typeof options.success !== 'function') { return; }
    var success = options.success;
    options.success = function (data, status, xhr) {
        var obj = null;
        if (typeof data === 'string' && data.indexOf('csrf_expired') !== -1) {
            try { obj = JSON.parse(data); } catch (e) { obj = null; }
        } else if (data && typeof data === 'object' && data.csrf_expired) {
            obj = data;
        }
        if (!obj || !obj.csrf_expired || !obj.csrf_token) { return success.apply(this, arguments); }

        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) { meta.setAttribute('content', obj.csrf_token); }
        document.querySelectorAll('input[name="csrf_token"]').forEach(function (el) { el.value = obj.csrf_token; });

        var body = original.data;
        if (body instanceof FormData) {
            if (body.has('csrf_token')) { body.set('csrf_token', obj.csrf_token); }
        } else if (typeof body === 'string') {
            body = body.replace(/(^|&)csrf_token=[^&]*/, '$1csrf_token=' + encodeURIComponent(obj.csrf_token));
        } else if (body && typeof body === 'object' && 'csrf_token' in body) {
            body.csrf_token = obj.csrf_token;
        }
        $.ajax($.extend({}, original, { data: body, success: success, csrf_retried: true }));
    };
});
