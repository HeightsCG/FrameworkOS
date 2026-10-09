<?php
/*
 * HeyCatch analytics (https://heycatch.ai/agents.md): the <head> markup. Settings and the on/off switch live in
 * libs/Classes/HeyCatch.php (set HeyCatch::ENABLED = false to discontinue; removal steps are in that file's docblock).
 *
 * Included first in every <head>, before google_analytics.php (that file strips ?signed_in=google from the URL,
 * and the sign-up check below has to see it). No build step here, so the SDK is the esm.sh module pinned to the
 * npm `latest` version. `requestBatching: false` because every navigation is a full page load. Autocapture covers
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
      import { analytics } from 'https://esm.sh/@heycatch/sdk@<?php echo HeyCatch::SDK_VERSION; ?>';

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
        verify_email: function (o) {   // email confirmed = the sign-up is complete; identify first so the event joins that person
          if (o.user_id) { analytics.setIdentity(String(o.user_id), o.email ? { email: o.email } : {}); }
          return ['signup_completed', { method: 'email' }];
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
