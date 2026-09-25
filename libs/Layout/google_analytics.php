    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-KQBG52F6SZ"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-KQBG52F6SZ');

      // CLSTrack('sign_up', {...}): one place every page sends GA events through (a no-op if gtag is blocked).
      window.CLSTrack = function (name, params) { try { if (window.gtag) { gtag('event', name, params || {}); } } catch (e) {} };

      // Key actions → GA events, read from the API responses (one listener, so no page handler has to change).
      // Only successful calls count; the event names are GA's recommended ones where one exists.
      document.addEventListener('DOMContentLoaded', function () {
        if (!window.jQuery) { return; }
        var map = {
          register: function () { return ['sign_up', { method: 'email' }]; },
          login: function (o) { return o.mfa_required ? null : ['login', { method: 'email' }]; },
          mfa_verify: function () { return ['login', { method: 'email' }]; },
          become_creator: function () { return ['become_creator', {}]; },
          confirm_credit_purchase: function (o) { return ['purchase', { currency: 'USD', value: (o.value_cents || 0) / 100, items: [{ item_name: 'Credits' }] }]; },
          billing_change_plan: function (o, d) { return o.status === 'succeeded' || o.status === 'scheduled' ? ['creator_plan_change', { plan: d.plan || '' }] : null; },
          subscribe_plan: function (o) { return o.url ? ['begin_checkout', { item_category: 'membership' }] : null; },
          join_free_plan: function () { return ['join_membership', { tier: 'free' }]; },
          ppv_unlock: function () { return ['unlock_content', { content_type: 'post' }]; },
          bundle_unlock: function () { return ['unlock_content', { content_type: 'bundle' }]; },
          message_unlock: function () { return ['unlock_content', { content_type: 'message' }]; },
          service_purchase: function () { return ['purchase_service', {}]; },
          event_register: function () { return ['register_event', {}]; }
        };
        jQuery(document).ajaxSuccess(function (e, xhr, s) {
          var m = /\/api\/([a-z_]+)(?:[?#]|$)/.exec(s.url || ''); if (!m || !map[m[1]]) { return; }
          var o = null; try { o = JSON.parse(xhr.responseText); } catch (err) { return; }
          if (!o || !o.success) { return; }
          var d = {}; try { d = Object.fromEntries(new URLSearchParams(typeof s.data === 'string' ? s.data : '')); } catch (err) {}
          var ev = map[m[1]](o, d); if (ev) { window.CLSTrack(ev[0], ev[1]); }
        });
      });

      // First-touch attribution: the visitor's first UTM tags / gclid / referrer / landing page, kept 90 days.
      // registerAction copies it onto the new account (user_accounts.acq_*).
      (function () {
        try {
          if (/(?:^|; )cls_ft=/.test(document.cookie)) { return; }
          var q = new URLSearchParams(location.search), ref = document.referrer || '';
          if (ref && new URL(ref).hostname === location.hostname) { ref = ''; }
          var ft = { s: q.get('utm_source') || '', m: q.get('utm_medium') || '', c: q.get('utm_campaign') || '', t: q.get('utm_term') || '',
                     n: q.get('utm_content') || '', g: q.get('gclid') || '', r: ref.slice(0, 255), l: (location.pathname + location.search).slice(0, 255),
                     at: new Date().toISOString() };
          document.cookie = 'cls_ft=' + encodeURIComponent(JSON.stringify(ft)) + '; path=/; max-age=' + (90 * 86400) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        } catch (e) {}
      })();
    </script>

    <!-- Microsoft Clarity (session recordings + heatmaps), linked to Bing Webmaster Tools -->
    <script>
    (function(c,l,a,r,i,t,y){
        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
        t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i+"?ref=bwt";
        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
    })(window, document, "clarity", "script", "ymy03mkngt");
    </script>
