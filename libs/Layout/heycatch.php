<?php
/*
 * HeyCatch analytics (https://heycatch.ai/agents.md): the <head> markup. Settings and the on/off switch live in
 * libs/Classes/HeyCatch.php (set HeyCatch::ENABLED = false to discontinue; removal steps are in that file's docblock).
 *
 * Included first in every <head>, before google_analytics.php (that file strips ?signed_in=google from the URL,
 * and the sign-up check below has to see it). The SDK is self-hosted: public/js/heycatch-sdk-<version>.js is the
 * esm.sh bundle of the pinned npm version (HeyCatch::SDK_VERSION), fetched once and committed, so no third-party host
 * sits in the page load. It is imported after the load event, at idle (Daniel 2026-10-12): nothing of it is requested
 * before the page has finished loading. To update: download https://esm.sh/@heycatch/sdk@<version>/es2022/sdk.bundle.mjs
 * to that path and change SDK_VERSION. `requestBatching: false` because every navigation is a full page load. Autocapture covers
 * pageviews and clicks; only identity and business outcomes are sent by hand, read from the API responses (one
 * listener, same idea as the GA map in google_analytics.php). Page scripts send an outcome of their own through
 * CLSHeyCatch('trackEvent', name, props): calls queue until the module below has run init.
 */
if (!HeyCatch::ENABLED) { return; }
?>
    <!-- HeyCatch analytics -->
    <script>
      window.CLSHeyCatch = function () { (window.CLSHeyCatch.q = window.CLSHeyCatch.q || []).push(arguments); };
      // Back from Google sign-in with a NEW account (AccountController::google_callbackAction adds ?signed_in=google&new=1).
      try { var hc_q = new URLSearchParams(location.search); if (hc_q.get('signed_in') === 'google' && hc_q.get('new') === '1') { window.CLSHeyCatch('trackEvent', 'signup_completed', { method: 'google' }); } } catch (e) {}
    </script>
    <script type="module">
      // after the load event, at idle: the SDK never competes with the page for the network or the main thread
      await new Promise(function (go) {
        var idle = function () { setTimeout(function () { (window.requestIdleCallback || function (f) { setTimeout(f, 1); })(go, { timeout: 4000 }); }, window.CLS_TAG_DELAY || 0); };   // CLS_TAG_DELAY: see google_analytics.php
        if (document.readyState === 'complete') { idle(); } else { window.addEventListener('load', idle, { once: true }); }
      });
      const { analytics } = await import('/js/heycatch-sdk-<?php echo HeyCatch::SDK_VERSION; ?>.js');

      analytics.init({
        projectKey: '<?php echo HeyCatch::PROJECT_KEY; ?>',
        requestBatching: false,
        install: { framework: 'web', agent: 'claude-code' },
      });

      var identity = <?php echo json_encode(HeyCatch::identity(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;
      if (identity) { analytics.setIdentity(identity.id, identity.props, identity.once); }

      // Replace the queue shim with the real thing and send what page scripts queued before this module ran.
      var queued = (window.CLSHeyCatch && window.CLSHeyCatch.q) || [];
      window.CLSHeyCatch = function (method) {
        try { if (typeof analytics[method] === 'function') { return analytics[method].apply(analytics, [].slice.call(arguments, 1)); } } catch (e) {}
      };
      queued.forEach(function (args) { window.CLSHeyCatch.apply(null, args); });

      // Business outcomes the browser witnesses, read from successful /api/<action> responses.
      var map = {
        register: function (o) {   // the account exists and is signed in = the sign-up is complete; identify first so the event joins that person
          if (!o.user_id) { return null; }   // a re-signup of an unverified address only got a fresh link
          analytics.setIdentity(String(o.user_id), o.email ? { email: o.email } : {});
          return ['signup_completed', { method: 'email' }];
        },
        verify_email: function (o) {   // the email link was clicked (sign-up itself completed at register)
          if (o.user_id) { analytics.setIdentity(String(o.user_id), o.email ? { email: o.email } : {}); }
          return ['email_verified', {}];
        },
        logout: function () { analytics.resetIdentity(); return null; },
        become_creator: function () { return ['became_creator', {}]; },
        billing_change_plan: function (o, d) { return (o.status === 'succeeded' || o.status === 'scheduled') ? ['plan_changed', { plan: d.plan || '' }] : null; },
        confirm_credit_purchase: function (o) { return ['credits_purchased', { value: (o.value_cents || 0) / 100, currency: 'USD' }]; },
        join_free_plan: function () { return ['membership_joined', { tier: 'free' }]; },
        ppv_unlock: function () { return ['content_unlocked', { content_type: 'post' }]; },
        bundle_unlock: function () { return ['content_unlocked', { content_type: 'bundle' }]; },
        message_unlock: function () { return ['content_unlocked', { content_type: 'message' }]; },
        service_purchase: function () { return ['service_purchased', {}]; },
        event_register: function () { return ['event_registered', {}]; }
      };
      function listen() {
        if (!window.jQuery) { return; }
        jQuery(document).ajaxSuccess(function (e, xhr, s) {
          var m = /\/api\/([a-z_]+)(?:[?#]|$)/.exec(s.url || ''); if (!m || !map[m[1]]) { return; }
          var o = null; try { o = JSON.parse(xhr.responseText); } catch (err) { return; }
          if (!o || !o.success) { return; }
          var d = {}; try { d = Object.fromEntries(new URLSearchParams(typeof s.data === 'string' ? s.data : '')); } catch (err) {}
          var ev = map[m[1]](o, d); if (ev) { analytics.trackEvent(ev[0], ev[1]); }
        });
      }
      if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', listen); } else { listen(); }
    </script>
