# CLS Video: record a call, sell the replay

Status: plan, not built. Written 2026-09-29.

## Goal
A host records a CLS Video event call. When the call ends, the recording shows up in their Library as a normal video.
They sell it the way they sell anything: a pay-per-view post (or put it in a bundle). No new selling flow.

## How it works (one paragraph)
LiveKit has a recording service called **Egress**. It joins the room as a hidden viewer, renders the call the way a
viewer sees it (the grid, or the speaker plus a shared screen), encodes it to MP4 and uploads the file to our S3 bucket.
Our app only starts and stops it (LiveKit's server API, like everything else in CLS Video). A queue job waits for the
file, then turns it into a Library video through the same path as an upload (poster frame, duration, storage limit).

## Part 1: the video server (Daniel, about half a day, written steps go in docs/live-video.md)

1. **Size.** Recording runs a headless Chrome and an encoder: about 2 to 4 vCPUs per recording while it runs. The
   current instance can't carry that next to the calls. Pick one:
   - **Bigger instance (recommended to start):** c6i.large → c6i.xlarge (4 vCPU, 8 GB). About +$60/month. One
     recording at a time alongside normal calls.
   - **Separate recorder box:** a second c6i.xlarge that only records. Calls are never slowed by a recording.
     Better later, when several creators record at once.
2. **S3 access for the recorder.** New IAM user `cls-recorder` with one permission: `s3:PutObject` on
   `arn:aws:s3:::content-os-bucket/recordings/*` (bucket content-os-bucket, us-east-2). Its keys go only on the server
   (egress.yaml), never in app.ini.
3. **Redis.** Egress and LiveKit talk through Redis. Check `grep -A2 redis livekit.yaml` in the LiveKit folder; the
   generator usually set it up. If not, add a `redis` service to docker-compose.yaml and `redis: address: localhost:6379`
   to livekit.yaml.
4. **Add Egress** to the same docker-compose.yaml:
   ```yaml
   egress:
     image: livekit/egress:latest
     restart: unless-stopped
     network_mode: host
     cap_add: [SYS_ADMIN]          # Chrome's sandbox needs it
     environment:
       - EGRESS_CONFIG_FILE=/etc/egress.yaml
     volumes:
       - ./egress.yaml:/etc/egress.yaml
   ```
   and `egress.yaml` next to it:
   ```yaml
   api_key: <same key as livekit.yaml>
   api_secret: <same secret>
   ws_url: ws://localhost:7880
   redis:
     address: localhost:6379
   storage:
     s3:
       access_key: <cls-recorder key>
       secret: <cls-recorder secret>
       region: us-east-2
       bucket: content-os-bucket
   ```
   then `sudo systemctl restart livekit-docker` and check `docker compose logs -f egress` says it's ready.
5. **Dev:** a local Egress in Docker against the scratchpad LiveKit dev server, writing to a `recordings-dev/` prefix,
   so the whole flow is tested before prod.

## Part 2: the app (Claude, about a day)

**Data** (`sql/YYYY-MM-DD_cls_video_recording.sql`): table `live_recordings`:
id, room, event_id, creator_id, egress_id, status (`recording` | `processing` | `ready` | `failed`),
s3_key, asset_id, started_by, started_at, ended_at, duration_sec, error.

**Server calls** (`LiveKit.php`, Twirp `livekit.Egress`):
- `start_recording($room, $key)` → `StartRoomCompositeEgress` {room_name, layout: `speaker`, file_outputs: [{file_type:
  MP4, filepath: `recordings/<creator>/<room>-<time>.mp4`}]} (the S3 details come from egress.yaml).
- `stop_recording($egress_id)` → `StopEgress`.
- `recording_status($egress_id)` → `ListEgress` (status, file size, duration).

**API** (`ApiLiveController`, host only, events only in v1):
- `live_record_start`: one recording per call at a time; refuses when the plan says no (see decisions) or the recorder
  is busy ("Recording is busy right now. Try again in a few minutes.").
- `live_record_stop`.
- Recording state goes into the room metadata (`recording: true, since`), so every page updates at once.

**Everyone knows they're being recorded** (required, not optional):
- A red **Recording** badge with the running time at the top of the call for everyone, the whole time.
- A notice when recording starts ("The host started recording this call"), and in the lobby before joining a call that
  is recording ("This call is being recorded").

**After the call** (queue job `recording_finish`, already how our jobs work):
- Started at Stop (and scheduled from Start as a safety net, in case the host just closes the tab; the recording also
  stops by itself when the room empties).
- Checks the egress status every 30 seconds until the file is complete, then makes the Library video: copy to the
  creator's media folder in S3 (no download to the web server), poster frame and duration with ffmpeg over a signed URL
  (MediaService already does this), counted against their plan's storage.
- Names it "Recording: <event title> (<date>)", sets the event's recording as ready, and notifies the creator:
  "Your recording is ready. Sell it as a pay-per-view post." The link opens a new post in Studio with the video
  already added (small change to the Studio composer: open with an asset preselected).
- Failure (storage full, recorder error): the creator is told why; the file stays in S3 for 7 days so support can recover it.

**Host UI:** a **Record** button in the call bar (host only). Confirm before starting ("Everyone in the call will see it's
being recorded"). While recording it turns into **Stop Recording**. The event's manage page lists its recordings with
their status and a link to the Library item.

**Test plan:** dev Egress + two-browser Playwright call (the harness in scratchpad wr_call.js): start, badge on both
pages, stop, job turns the file into a Library video with a poster, notification sent; plus: host closes the tab (auto
stop), storage full (clean failure), second Start while recording (refused), recorder down (clear error).

## Decisions for Daniel
1. **Server:** bigger instance (recommended to start) or a separate recorder box?
2. **Who can record:** Creator and Studio both? Recording is the costliest thing we run, so a sensible split is Creator =
   up to 2 hours per recording, Studio = up to 4 hours. Storage counts against their plan either way.
3. **Events only in v1?** (Recommended.) Service calls are private 1-to-1 conversations; recording those raises consent
   questions we don't need to answer yet.
4. **Layout:** speaker view (the person talking big, a shared screen when there is one; recommended for replays) or grid?

## Out of scope for v1
Editing/trimming recordings, audio-only recordings, live streaming out to YouTube/Twitch (Egress can do it later),
automatic captions.
