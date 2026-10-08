# Creator custom domains: go-live steps

Same pattern as VIP: CLS gets its own small Caddy gateway (`gateway.creatorlinkstudio.com`) with on-demand TLS.
Creators point their domain at it, Caddy asks CLS whether the domain is real before issuing a certificate, and
forwards the traffic to CLS with the visitor's hostname in `X-Forwarded-Host`. VIP's gateway is not touched.

Do the steps in order.

---

## Part A — CLS app (production)

**A1. Database.** Run before deploying the code:
```
mysql -h 127.0.0.1 --protocol=TCP -u casivo contentos < sql/2026-09-28_creator_domains.sql
```

**A2. app.ini, `[global]`** (the same key VIP uses):
```
domain_gateway = "gateway.creatorlinkstudio.com"
```

**A3. Deploy the code.**

**A4. Check the certificate endpoint** Caddy will ask:
```
curl -s -w " %{http_code}\n" "https://www.creatorlinkstudio.com/domain-check?domain=nope.example"   # unknown host 404
```

**A5. Daily DNS re-check cron** on the CLS server:
```
17 4 * * *  APPLICATION_ENV=production php /path/to/framework/cron/domains.php >> /tmp/cls-domains.log 2>&1
```

---

## Part B — The gateway server (AWS, us-east-2)

**B1. Launch an EC2 instance:** Ubuntu 24.04, `t4g.nano` (or `t4g.micro`), same region as the app.
Security group: inbound TCP 80 and 443 from `0.0.0.0/0`, TCP 22 from your IP only.

**B2. Give it a fixed address:** EC2 → Elastic IPs → Allocate → Associate with the new instance.
(An instance's default public IP changes whenever it's stopped and started; an Elastic IP doesn't.)

**B3. Install Caddy** (ssh in as `ubuntu`):
```
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install -y caddy
```

**B4. Caddyfile.** `sudo nano /etc/caddy/Caddyfile`, replace everything with:
```
{
    email <an address that gets Let's Encrypt expiry notices>

    on_demand_tls {
        ask https://www.creatorlinkstudio.com/domain-check
    }
}

https:// {
    tls {
        on_demand
    }

    reverse_proxy https://www.creatorlinkstudio.com {
        header_up Host www.creatorlinkstudio.com
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto https
    }
}
```

**B5. Validate and reload:**
```
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl reload caddy
```

---

## Part C — DNS (Route 53, creatorlinkstudio.com zone)

**C1.** Create:
```
gateway.creatorlinkstudio.com   A   <the Elastic IP from B2>
```
**C2.** Check it: `dig +short gateway.creatorlinkstudio.com` should print the Elastic IP.

---

## Part D — End-to-end test with a real domain

**D1.** On a Studio creator account, go to Settings → Custom Domain, enter a domain you control (e.g. `test.danglauber.com`), and click **Add Domain**.

**D2.** At that domain's DNS host, add the two records the page shows:
- `TXT  _cls-verify.test` = the token shown
- `CNAME test` → `gateway.creatorlinkstudio.com`

**D3.** Wait a few minutes, then click **Check DNS**. Both records should show Found, with the status Connected.

**D4.** Open `https://test.danglauber.com`. The first visit takes a few seconds while Caddy gets the certificate. You should see the creator's page. Then check:
- **Sign In** → sign in on creatorlinkstudio.com → you land back on `test.danglauber.com` signed in.
- `https://www.creatorlinkstudio.com/@<handle>` in a private window → redirects to `test.danglauber.com`.

**D5.** Remove the test domain in Settings. Caddy stops issuing certificates for it straight away, because `/domain-check` now says no.

If D4 shows the platform home page instead of the creator's page, the app didn't trust the forwarded hostname.
`trusted_proxies` in `app.ini` must cover the load balancer's private addresses (the default private ranges do).

---

## Optional hardening (VIP doesn't do this)

Both apps trust `X-Forwarded-Host` from their load balancer, so a request sent straight to the load balancer
can claim any customer domain. To close that for CLS:

1. `openssl rand -hex 32`
2. `app.ini [global]`: `domain_gateway_secret = "<value>"`
3. On the gateway:
   ```
   echo '<value>' | sudo tee /etc/caddy/cls-secret > /dev/null
   sudo chown caddy:caddy /etc/caddy/cls-secret && sudo chmod 600 /etc/caddy/cls-secret
   ```
   and add to the `reverse_proxy` block: `header_up X-CLS-Gateway {file./etc/caddy/cls-secret}` (needs Caddy 2.8 or later; B3 installs the current version). Then reload Caddy.

Add the Caddy line and the app.ini key together: with the key set, requests without the header aren't treated as custom domains.

---

## How it behaves

- `lexivaughn.com/`, `/events/<id>` and `/services/<id>` show the creator's page; `www.` redirects to whichever address is the main one.
- Any other path (settings, wallet, inbox) goes to the platform, still signed in.
- Sign In always happens on creatorlinkstudio.com; a one-time 60-second token (`/_handoff`) carries the session back.
- Downgrading off Studio turns the domain off immediately: pages fall back to `/@handle` and `/domain-check` stops approving certificates. Nothing is deleted.
