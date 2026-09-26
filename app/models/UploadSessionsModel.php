<?php
/**
 * Resumable upload sessions (Content Studio) for large video files uploaded via
 * S3 multipart. A session records the S3 UploadId and the parts received so far,
 * so an interrupted upload resumes from the next part instead of restarting.
 * Creator-scoped.
 */
class UploadSessionsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function create($creator_id, $filename, $mime, $bytes_total, $storage_key, $s3_upload_id, $client_token, $asset_id){
        $now = date('Y-m-d H:i:s');
        return parent::insert('upload_sessions', array(
            'creator_id'   => (int) $creator_id,
            'filename'     => (string) $filename,
            'mime'         => (string) $mime,
            'bytes_total'  => (int) $bytes_total,
            'storage_key'  => (string) $storage_key,
            's3_upload_id' => (string) $s3_upload_id,
            'client_token' => (string) $client_token,
            'asset_id'     => (int) $asset_id,
            'parts_json'   => json_encode(array()),
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ));
    }

    public function get_one($creator_id, $id){
        $rows = parent::select(
            "SELECT * FROM upload_sessions WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Find an active session by the browser's file fingerprint, for resume. */
    public function get_active_by_token($creator_id, $client_token){
        $rows = parent::select(
            "SELECT * FROM upload_sessions
             WHERE creator_id = :c AND client_token = :t AND status = 'active'
             ORDER BY id DESC LIMIT 1",
            array('c' => (int) $creator_id, 't' => (string) $client_token)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Decode the recorded parts array ([{PartNumber,ETag,Size}, ...]). */
    public function parts(array $session){
        $parts = json_decode((string) ($session['parts_json'] ?? '[]'), true);
        return is_array($parts) ? $parts : array();
    }

    /** Record a newly-uploaded part and advance bytes_received. */
    public function add_part($creator_id, $id, $part_number, $etag, $part_size){
        $session = $this->get_one($creator_id, $id);
        if (!$session) { return false; }
        $parts = $this->parts($session);
        // Replace any existing entry for this part number (re-upload safety).
        $parts = array_values(array_filter($parts, function ($p) use ($part_number) {
            return (int) $p['PartNumber'] !== (int) $part_number;
        }));
        $parts[] = array('PartNumber' => (int) $part_number, 'ETag' => (string) $etag, 'Size' => (int) $part_size);
        usort($parts, function ($a, $b) { return $a['PartNumber'] <=> $b['PartNumber']; });
        $received = 0;
        foreach ($parts as $p) { $received += (int) $p['Size']; }
        return parent::update('upload_sessions',
            array('parts_json' => json_encode($parts), 'bytes_received' => $received, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function mark_completed($creator_id, $id, $asset_id){
        return parent::update('upload_sessions',
            array('status' => 'completed', 'asset_id' => (int) $asset_id, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function mark_aborted($creator_id, $id){
        return parent::update('upload_sessions',
            array('status' => 'aborted', 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Uploads started but untouched for $hours (the browser was closed): their S3 parts cost storage until aborted. */
    public function stale_active($hours = 24, $limit = 100){
        $limit = max(1, min(500, (int) $limit));
        return (array) parent::select(
            "SELECT * FROM upload_sessions WHERE status = 'active' AND updated_at < :cut ORDER BY id LIMIT $limit",
            array('cut' => date('Y-m-d H:i:s', time() - (int) $hours * 3600)));
    }
}
