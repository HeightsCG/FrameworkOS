<?php Analytics::pageview(); /* first-party page view (analytics_events), recorded once per rendered page; GA and Clarity below stay as they are */ ?>
<?php echo Analytics::beacon_script(); /* a cacheable page reports its own view after load */ ?>    <!-- Google tag (gtag.js) -->
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      // gtag.js (and the Clarity tag GA pulls in) is requested after the load event, at idle: loaded up front it pushed the
      // home hero's paint from 2.0s to 5.8s in Lighthouse (2026-10-15). dataLayer queues every call until it arrives.
      (function () {
        var go = function () { var s = document.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=G-KQBG52F6SZ'; document.head.appendChild(s); };
        // window.CLS_TAG_DELAY (ms, set by a page before this file; /start uses it): wait that long after load first. 0 elsewhere.
        var idle = function () { setTimeout(function () { (window.requestIdleCallback || function (f) { setTimeout(f, 1); })(go, { timeout: 3000 }); }, window.CLS_TAG_DELAY || 0); };
        if (document.readyState === 'complete') { idle(); } else { window.addEventListener('load', idle, { once: true }); }
      })();

      gtag('config', 'G-KQBG52F6SZ');

      // CLSTrack('sign_up', {...}): one place every page sends GA events through (a no-op if gtag is blocked).
      window.CLSTrack = function (name, params) { try { if (window.gtag) { gtag('event', name, params || {}); } } catch (e) {} };

      // Back from Google sign-in (AccountController::google_callbackAction redirects with ?signed_in=google[&new=1]).
      (function () {
        try {
          var q = new URLSearchParams(location.search);
          if (q.get('signed_in') !== 'google') { return; }
          window.CLSTrack(q.get('new') === '1' ? 'sign_up' : 'login', { method: 'google' });
          q.delete('signed_in'); q.delete('new');
          history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q.toString() : '') + location.hash);
        } catch (e) {}
      })();

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
    (function(c,l,a,r,i){
        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};   // calls queue until the tag has loaded
        // loaded after the load event, at idle (2026-10-12): the tag is never requested while the page is still loading
        var go=function(){var t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i+"?ref=bwt";var y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);};
        var idle=function(){setTimeout(function(){(c.requestIdleCallback||function(f){setTimeout(f,1);})(go,{timeout:4000});},c.CLS_TAG_DELAY||0);};
        if(l.readyState==="complete"){idle();}else{c.addEventListener("load",idle,{once:true});}
    })(window, document, "clarity", "script", "ymy03mkngt");
    </script>

    <!-- TikTok Pixel Code Start -->
    <script>
    !function (w, d, t) {
      w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(
    var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script")
    ;n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};

      // a TikTok ad click's ttclid, kept 30 days so server-side conversions (TikTokEventJob) match the click
      try{var tc=new URLSearchParams(location.search).get("ttclid");if(tc){d.cookie="cls_ttclid="+encodeURIComponent(tc.slice(0,500))+"; path=/; max-age="+(30*86400)+"; SameSite=Lax"+(location.protocol==="https:"?"; Secure":"");}}catch(e){}
      // the pixel script is requested after the load event, at idle, like GA and Clarity above
      var go=function(){if(w.CLS_TTQ_LOADED){return;}w.CLS_TTQ_LOADED=1;ttq.load('DB8ESIBC77U937USUOBG');ttq.page();};
      w.CLSTikTokLoadNow=go;   // a conversion that must not wait for idle (CLSTikTokRegistration) loads the pixel at once; loads once either way
      var idle=function(){setTimeout(function(){(w.requestIdleCallback||function(f){setTimeout(f,1);})(go,{timeout:4000});},w.CLS_TAG_DELAY||0);};
      if(d.readyState==="complete"){idle();}else{w.addEventListener("load",idle,{once:true});}
    }(window, document, 'ttq');

    // CompleteRegistration, the one time an account is created (2026-10-16). Every signup path hands this the server's
    // confirmation: the email signup's /api/register reply, a Google signup's next page (below), an accepted team invite's
    // /api/reset reply. The server gives each account's event out once (UsersModel::claim_tt_registration), and the
    // event id is shared with the Events API copy (TikTokEventJob), so TikTok counts one signup. Email and id arrive hashed.
    window.CLSTikTokRegistration = function (p) {
      if (!p || !p.event_id || !window.ttq) { return; }
      try { if (sessionStorage.getItem('cls_ttreg_' + p.event_id)) { return; } sessionStorage.setItem('cls_ttreg_' + p.event_id, '1'); } catch (e) {}
      var who = {}; if (p.email) { who.email = p.email; } if (p.external_id) { who.external_id = p.external_id; }
      ttq.identify(who);
      if (window.CLSTikTokLoadNow) { window.CLSTikTokLoadNow(); }   // don't wait for idle: the person may leave this page any second
      ttq.track('CompleteRegistration', { content_name: 'creator_signup', value: 0, currency: 'USD' }, { event_id: p.event_id });
    };
<?php
    // A Google signup lands here signed in (AccountController::google_callbackAction set tt_reg_pending): fire it on this page, once.
    $tt_pending = (int) (Session::get('tt_reg_pending') ?? 0);
    if ($tt_pending > 0) {
        Session::destroyValue('tt_reg_pending');
        $tt_reg = ((int) Session::get('user_id') === $tt_pending) ? (new UsersModel())->claim_tt_registration($tt_pending) : null;
        if ($tt_reg) { echo '    window.CLSTikTokRegistration(' . json_encode($tt_reg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ");\n"; }
    }
?>
    </script>
    <!-- TikTok Pixel Code End -->
