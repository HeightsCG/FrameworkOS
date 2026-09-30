<?php
/**
 * Record an event's CLS Video call and turn the recording into a Library video the creator can sell (a pay-per-view
 * post, a bundle). LiveKit's recorder (Egress) does the recording and uploads the MP4 to S3 under recordings/; this
 * class starts and stops it and, through the queue job recording_watch (RecordingWatchJob), waits for the file and
 * makes the Library video, the same way an uploaded video becomes one (poster, duration, plan storage).
 *
 * Creator and Studio can record; one recording per call at a time, up to LIMITS minutes each (then it stops itself).
 * Everyone in the call sees that it's being recorded (the room metadata carries "recording", see LiveControl).
 */
class LiveRecording {

    const LIMITS = array('studio' => 240, 'creator' => 120);   // minutes per recording, by plan
    const WATCH_SECONDS = 60;                                 // how often the watcher checks a running recording

    /** Minutes one recording may run on this creator's plan (0 = their plan can't record). */
    public static function limit_minutes(array $owner): int
    {
        if (!Plan::can_use_creator_features($owner)) { return 0; }
        return Plan::tier($owner) === 'studio' ? self::LIMITS['studio'] : self::LIMITS['creator'];
    }

    /** The recording running in a room, as the pages see it: started (Unix time), or 0. */
    public static function running_since($room): int
    {
        $r = (new LiveRecordingsModel())->recording_now($room);   // Stop ends the badge at once, while the file is still being finished
        return $r ? (int) strtotime((string) $r['started_at'] . ' UTC') : 0;
    }

    /** Host pressed Record. Returns ['ok'] or ['ok' => false, 'message']. */
    public static function start($room, array $ev, array $owner, $started_by): array
    {
        $no = function ($m) { return array('ok' => false, 'message' => $m); };
        $minutes = self::limit_minutes($owner);
        if ($minutes <= 0) { return $no('Recording is included on the Creator and Studio plans.'); }
        $m = new LiveRecordingsModel();
        if ($m->recording_now($room)) { return $no('This call is already being recorded.'); }
        $cid = (int) $ev['creator_id'];
        $key = 'recordings/' . $cid . '/' . $room . '-' . gmdate('Ymd-His') . '.mp4';
        $egress = LiveKit::start_recording($room, $key);
        if ($egress === '') { return $no('Recording is busy right now. Try again in a few minutes.'); }
        $id = $m->add(array('room' => $room, 'event_id' => (int) $ev['id'], 'creator_id' => $cid, 'started_by' => (int) $started_by,
                            'egress_id' => $egress, 's3_key' => $key, 'status' => 'recording', 'limit_minutes' => $minutes));
        self::watch($id, self::WATCH_SECONDS);
        return array('ok' => true, 'id' => $id, 'minutes' => $minutes);
    }

    /** Host pressed Stop (or the time limit was reached). The watcher makes the Library video once the file is in. */
    public static function stop($room): array
    {
        $m = new LiveRecordingsModel();
        $r = $m->recording_now($room);
        if (!$r) { return array('ok' => false, 'message' => 'This call isn’t being recorded.'); }
        if ($m->mark_stopping((int) $r['id']) && !LiveKit::stop_recording((string) $r['egress_id'])) {
            error_log('[recording] stop failed for egress ' . $r['egress_id'] . ' (it ends by itself when the room empties)');
        }
        self::watch((int) $r['id'], 5);
        return array('ok' => true);
    }

    public static function watch($id, $in_seconds): void
    {
        (new DatabaseJobQueue())->dispatch('recording_watch', array('id' => (int) $id), null, gmdate('Y-m-d H:i:s', time() + (int) $in_seconds));
    }

    /**
     * The watcher: stop at the time limit, wait while the recorder is still writing, then make the Library video
     * (or record why it failed). Runs again every minute until the recording is done.
     */
    public static function tick($id): string
    {
        $m = new LiveRecordingsModel();
        $r = $m->get($id);
        if (!$r || !in_array((string) $r['status'], array('recording', 'stopping'), true)) { return 'done'; }
        $started = strtotime((string) $r['started_at'] . ' UTC');
        $info = LiveKit::recording_info((string) $r['egress_id']);
        if ($info === null) {
            if (time() - $started > ((int) $r['limit_minutes'] + 180) * 60) { self::fail($r, 'The video server couldn’t be reached to collect the recording.'); return 'gave up'; }
            self::watch($id, self::WATCH_SECONDS);
            return 'server unreachable, retrying';
        }
        if (in_array($info['status'], array('EGRESS_STARTING', 'EGRESS_ACTIVE', 'EGRESS_ENDING'), true)) {
            if ((string) $r['status'] === 'recording' && time() - $started >= (int) $r['limit_minutes'] * 60) {
                self::stop((string) $r['room']);
                self::push_state((string) $r['room']);
                return 'time limit reached, stopping';
            }
            self::watch($id, (string) $r['status'] === 'stopping' ? 15 : self::WATCH_SECONDS);
            return 'still ' . strtolower(str_replace('EGRESS_', '', $info['status']));
        }
        // Finished (stopped, room emptied, or the recorder hit its own limit): whatever file there is becomes the video.
        if (!$m->claim_processing($id)) { return 'already claimed'; }
        self::push_state((string) $r['room']);
        // Nothing was uploaded (the recorder failed, or saved nowhere we can reach): say why instead of trying to copy it.
        if ($info['status'] !== 'EGRESS_COMPLETE' && $info['bytes'] <= 0) {
            error_log('[recording] ' . $r['egress_id'] . ' ' . $info['status'] . ': ' . $info['error']);
            self::fail($r, 'The recording couldn’t be saved' . ($info['error'] !== '' ? ' (' . mb_substr($info['error'], 0, 150) . ')' : '') . '. Contact support if it happens again.');
            return 'failed';
        }
        return self::make_video($m->get($id), $info);
    }

    /** Copy the file into the creator's Library (server-side in S3) and build the poster, like an uploaded video. */
    private static function make_video(array $r, array $info): string
    {
        $cid = (int) $r['creator_id'];
        $rows = (new UsersModel())->get_user_by_id($cid);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$owner) { self::fail($r, 'The creator account is gone.'); return 'failed'; }
        $src = $info['file'] !== '' ? ltrim(preg_replace('#^s3://[^/]+/#', '', $info['file']), '/') : (string) $r['s3_key'];
        $bytes = (int) $info['bytes'];
        $gb = Plan::limit($owner, 'storage_gb');
        $mm = new MediaAssetsModel();
        if ($gb !== null && (int) $gb > 0 && (int) $mm->total_bytes($cid) + $bytes > (int) $gb * 1073741824) {
            self::fail($r, 'Your storage is full, so the recording couldn’t be added to your Library. Free up space or upgrade, then contact support to recover it.');
            return 'storage full';
        }
        $ev = (new EventsModel())->get_one($cid, (int) $r['event_id']);
        $title = $ev ? html_entity_decode((string) $ev['title'], ENT_QUOTES, 'UTF-8') : 'Call';
        $when = self::local_date((string) $r['started_at'], (string) ($owner['content_timezone'] ?? ''));
        $label = 'Recording: ' . mb_substr($title, 0, 40) . ' (' . $when . ')';
        $aid = (int) $mm->add($cid, 'video', $label . '.mp4', 'video/mp4', 'processing');
        if ($aid <= 0) { self::fail($r, 'Could not create the Library item.'); return 'failed'; }
        $key = MediaService::key($cid, $aid, 'original', 'mp4');
        if (!S3Service::copy_private($src, $key, 'video/mp4')) {
            error_log('[recording] ' . $r['egress_id'] . ': copy from ' . $src . ' failed (see [s3] line above)');
            $mm->set_failed($cid, $aid, 'Could not copy the recording');
            self::fail($r, 'The recording couldn’t be copied into your Library.');
            return 'copy failed';
        }
        $url = S3Service::presigned_get_url($key, 1800);
        $probe = MediaService::probe_video($url);
        $res = MediaService::process_video($cid, $aid, $url, $owner);
        if (isset($res['error'])) {
            $mm->set_failed($cid, $aid, $res['error']);
            self::fail($r, 'The recording was saved but its preview couldn’t be made: ' . $res['error']);
            return 'poster failed';
        }
        $duration = (int) ($probe['duration'] ?? 0) ?: (int) $info['duration'];
        $mm->set_ready($cid, $aid, array_merge($res, array('original_key' => $key, 'bytes' => $bytes,
            'duration_sec' => $duration, 'width' => (int) ($probe['width'] ?? 0), 'height' => (int) ($probe['height'] ?? 0))));
        (new LiveRecordingsModel())->set((int) $r['id'], array('status' => 'ready', 'asset_id' => $aid, 'duration_sec' => $duration, 'bytes' => $bytes, 'error' => null));
        S3Service::delete_key($src);   // the Library copy is the one that counts now
        Notify::send($cid, 'events', 'Your recording is ready', '"' . mb_substr($title, 0, 60) . '" is in your Library. Sell it as a pay-per-view post.',
            '/studio?use=' . $aid, 'fa-circle-play');
        return 'ready: asset ' . $aid;
    }

    private static function fail(array $r, $why): void
    {
        (new LiveRecordingsModel())->set((int) $r['id'], array('status' => 'failed', 'error' => mb_substr((string) $why, 0, 255)));
        Notify::send((int) $r['creator_id'], 'events', 'Your recording didn’t save', (string) $why, '/events/manage/' . (int) $r['event_id'], 'fa-triangle-exclamation');
    }

    /** Tell everyone in the call that recording started or stopped (the badge). */
    public static function push_state($room): void
    {
        $row = (new LiveRoomsModel())->get($room);
        if ($row === null) { return; }
        $ev = (new EventsModel())->get_public((int) preg_replace('/^ev-/', '', (string) $room));
        $s = LiveControl::state($room, LiveControl::defaults($ev ?: null));
        LiveKit::set_room_metadata($room, LiveControl::meta($s));
    }

    private static function local_date($utc, $tz): string
    {
        try { return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz !== '' ? $tz : 'America/New_York'))->format('M j, Y'); }
        catch (\Throwable $e) { return gmdate('M j, Y'); }
    }
}
