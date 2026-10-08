# CLS Video: video server go-live steps

CLS Video runs on our own LiveKit server (open source: https://github.com/livekit/livekit, docs: https://docs.livekit.io).
CLS never carries the video. It hands each ticket holder a short-lived signed pass, and browsers connect straight to
this server, which refuses anyone without a valid pass. Until the three `app.ini` keys in Part C are set, CLS Video
stays hidden everywhere.

Do the steps in order.

---

## Part A: the server (AWS, us-east-2)

**A1. Launch an EC2 instance:** Ubuntu 24.04, `c6i.large` (2 vCPU, 4 GB). A `t3.medium` is fine to start. Put it in the
same region as the app, with a 20 GB disk.

**A2. Give it a fixed address:** EC2 → Elastic IPs → Allocate → Associate with the new instance.

**A3. Security group (inbound):**

| Port | Protocol | Why |
|---|---|---|
| 22 | TCP | SSH, from your IP only |
| 80 | TCP | Certificate issuing (Let's Encrypt) |
| 443 | TCP | Browsers connect here (secure WebSocket) and TURN over TLS |
| 7881 | TCP | Video over TCP when UDP is blocked |
| 3478 | UDP | TURN (calls through strict firewalls) |
| 50000–60000 | UDP | The video itself |

---

## Part B: DNS (Route 53, creatorlinkstudio.com zone)

**B1.** Create two records, both pointing at the Elastic IP from A2:
```
live.creatorlinkstudio.com   A   <Elastic IP>
turn.creatorlinkstudio.com   A   <Elastic IP>
```
**B2.** Check: `dig +short live.creatorlinkstudio.com` and `dig +short turn.creatorlinkstudio.com` both print the IP.

---

## Part C: install LiveKit (ssh in as `ubuntu`)

**C1. Install Docker:**
```
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu && newgrp docker
```

**C2. Generate the config** with LiveKit's official generator. It writes everything, including Caddy for the
certificates and Redis:
```
docker pull livekit/generate
docker run --rm -it -v $PWD:/output livekit/generate
```
Answer:
- Primary domain: `live.creatorlinkstudio.com`
- TURN domain: `turn.creatorlinkstudio.com`
- Latest version, no Egress / Ingress (recording was added later: see Part E)
- Local Redis: yes
- Startup script (cloud-init or shell): choose **Startup Shell Script**

It prints an **API Key** and **API Secret**. Copy both; you need them in C4. It creates a folder named after the domain.

**C3. Run it:**
```
cd live.creatorlinkstudio.com
sudo ./init_script.sh          # installs and starts it as the "livekit-docker" service
systemctl status livekit-docker
```

**C4. app.ini on the CLS app servers, `[global]`:**
```
livekit_url        = "wss://live.creatorlinkstudio.com"
livekit_api_key    = "<API Key from C2>"
livekit_api_secret = "<API Secret from C2>"
```

---

## Part D: check it

**D1.** Open `https://live.creatorlinkstudio.com`. It should answer `OK`. The first visit can take a few seconds
while the certificate is issued.

**D2.** Connection test: https://livekit.io/connection-test. Enter `wss://live.creatorlinkstudio.com`. It needs a
token; ask me for a one-off test token, or skip to D3.

**D3.** A real call:
1. On a Creator or Studio account, create an event with Where → **CLS Video**, starting within 15 minutes, and turn it Live.
2. Register for it from a second (fan) account.
3. Creator: Events → the event → **Start Video**. Fan: the event page → **Join Video**.
4. Both should see and hear each other. Try Share Screen and, as the host, People → Remove From Call.

---

## How it behaves
- **Events:** one call per event, open from 15 minutes before the start until 30 minutes after the end (the host can
  open it any time before the end).
  - **Free events open to everyone:** anyone can join from the event page, registered or not, even without a CLS
    account. Guests type the name others will see. Registering still works as before (emails, headcount).
  - **Paid and members-only events:** ticket holders only.
  - **Call password (optional):** set in the event's Where section. Everyone but the host types it. The host sees it
    in the call lobby to share. It is never emailed or shown on the event page.
- **Services** with Meets On → CLS Video: each booking gets its own private call between the buyer and the creator.
  It is open any time while the booking is paid. Both sides join from the service page (the creator from the booking's ⋯ menu).
- **Hosts** (the creator and their team) can remove someone and mute everyone.
- **Nothing is recorded.**
- **Email:** the event emails' Join button opens the call page. It still needs the fan's login and ticket, so a
  forwarded email doesn't let anyone in.

## Part E: call recording (Egress), added 2026-09-29

Recording uses LiveKit's recorder ("Egress"): it joins a call as a hidden viewer, records what attendees see and uploads
an MP4 to `s3://content-os-bucket/recordings/`. Done on the live server on 2026-09-29:

1. **Bigger server:** `cls-video` changed from c6i.large to **c6i.xlarge** (4 vCPU, 8 GB). A recording uses about
   2 to 4 CPUs while it runs, so this carries one recording at a time next to normal calls.
2. **Upload-only AWS user:** IAM user `cls-recorder` with policy `cls-recorder-upload` (s3:PutObject and
   s3:AbortMultipartUpload on `content-os-bucket/recordings/*`, s3:GetBucketLocation on the bucket). Its keys live only
   in `/opt/livekit/egress.yaml` on the server.
3. **Recorder container:** `egress` service added to `/opt/livekit/docker-compose.yaml` (image `livekit/egress`,
   host network, `cap_add: SYS_ADMIN` for its Chrome), config in `/opt/livekit/egress.yaml` (the LiveKit API key and
   secret, `ws_url: ws://localhost:7880`, Redis `localhost:6379`, and the S3 details nested under `storage:` → `s3:`; a top-level `s3:` is ignored by current recorder versions, which then try to save to a local `/recordings` folder and fail with "mkdir /recordings: permission denied"). The file must be readable by the
   container's user (`chmod 644`; `600` fails with "permission denied"). Backup of the old compose file:
   `docker-compose.yaml.bak-2026-09-29`.
4. **Check:** `docker logs livekit-egress-1 | tail` ends with `service ready` and `cpu available: 4`.

Restart only the recorder (calls unaffected): `cd /opt/livekit && sudo /usr/local/bin/docker-compose -f docker-compose.yaml restart egress`.

## Upkeep
- Update: `cd /opt/livekit && sudo /usr/local/bin/docker-compose -f docker-compose.yaml pull && sudo systemctl restart livekit-docker`
- Logs: `docker compose logs -f livekit`
- Bigger calls or more at once: move to a larger instance. The limit is mostly bandwidth, and one `c6i.large` handles
  many small calls.
