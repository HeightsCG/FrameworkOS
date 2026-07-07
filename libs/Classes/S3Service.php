<?php
/**
 * Thin wrapper around AWS S3 for user uploads (profile images, etc.).
 * Reads s3_* keys from app.ini (current environment). Uploads are no-ops with a
 * clear error until the bucket/region/credentials are configured.
 */
class S3Service {

    private static function cfg($key): string
    {
        $config = Main::get_config();
        $env    = Main::get_environment();
        return (string) ($config[$env][$key] ?? '');
    }

    public static function bucket(): string { return self::cfg('s3_bucket'); }
    public static function region(): string { return self::cfg('s3_region'); }

    /**
     * Configured once a bucket + region are set. Credentials are optional here:
     * if s3_key/s3_secret are provided they're used, otherwise the AWS SDK falls
     * back to its default credential chain (env vars, ~/.aws profile, IAM role).
     */
    public static function configured(): bool
    {
        return self::bucket() !== '' && self::region() !== '';
    }

    private static function client(): \Aws\S3\S3Client
    {
        $args = array(
            'version' => 'latest',
            'region'  => self::region(),
        );
        if (self::cfg('s3_key') !== '' && self::cfg('s3_secret') !== '') {
            $args['credentials'] = array(
                'key'    => self::cfg('s3_key'),
                'secret' => self::cfg('s3_secret'),
            );
        }
        return new \Aws\S3\S3Client($args);
    }

    /**
     * Upload a local file to S3 under $key and return its public URL, or '' on
     * failure. Content type is set so the object serves correctly in a browser.
     */
    public static function upload_file($key, $source_path, $content_type): string
    {
        if (!self::configured()) {
            error_log('[s3] upload attempted but S3 is not configured');
            return '';
        }
        try {
            self::client()->putObject(array(
                'Bucket'      => self::bucket(),
                'Key'         => $key,
                'SourceFile'  => $source_path,
                'ContentType' => $content_type,
            ));
        } catch (\Throwable $e) {
            error_log('[s3] upload failed: ' . $e->getMessage());
            return '';
        }
        return 'https://' . self::bucket() . '.s3.' . self::region() . '.amazonaws.com/' . $key;
    }

    /** Delete an object given a URL previously returned by upload_file(). */
    public static function delete_by_url($url): bool
    {
        if (!self::configured() || $url === '') {
            return false;
        }
        $prefix = 'https://' . self::bucket() . '.s3.' . self::region() . '.amazonaws.com/';
        if (strpos($url, $prefix) !== 0) {
            return false;
        }
        $key = substr($url, strlen($prefix));
        try {
            self::client()->deleteObject(array('Bucket' => self::bucket(), 'Key' => $key));
        } catch (\Throwable $e) {
            error_log('[s3] delete failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /* -----------------------------------------------------------------------
     * Content Studio additions (additive; the public-upload methods above are
     * unchanged). Media is stored PRIVATE and served via short-lived presigned
     * URLs after a server-side entitlement check — never a permanent public URL.
     * --------------------------------------------------------------------- */

    /**
     * Upload a local file to S3 under $key as a PRIVATE object. Returns true on
     * success, false on failure. No URL is returned: private objects are only
     * ever reached through presigned_get_url() after an entitlement check.
     */
    public static function put_private($key, $source_path, $content_type): bool
    {
        if (!self::configured()) {
            error_log('[s3] private upload attempted but S3 is not configured');
            return false;
        }
        try {
            self::client()->putObject(array(
                'Bucket'      => self::bucket(),
                'Key'         => $key,
                'SourceFile'  => $source_path,
                'ContentType' => $content_type,
            ));
        } catch (\Throwable $e) {
            error_log('[s3] private upload failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /** Store raw bytes (already in memory, e.g. a GD-encoded rendition) privately. */
    public static function put_private_bytes($key, $bytes, $content_type): bool
    {
        if (!self::configured()) {
            error_log('[s3] private upload attempted but S3 is not configured');
            return false;
        }
        try {
            self::client()->putObject(array(
                'Bucket'      => self::bucket(),
                'Key'         => $key,
                'Body'        => $bytes,
                'ContentType' => $content_type,
            ));
        } catch (\Throwable $e) {
            error_log('[s3] private bytes upload failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Mint a short-lived presigned GET URL for a private object. $ttl is seconds
     * (default 10 min). Returns '' if S3 isn't configured or $key is empty. The
     * CALLER is responsible for the entitlement check before calling this.
     */
    public static function presigned_get_url($key, $ttl = 600): string
    {
        if (!self::configured() || (string) $key === '') {
            return '';
        }
        try {
            $cmd = self::client()->getCommand('GetObject', array(
                'Bucket' => self::bucket(),
                'Key'    => $key,
            ));
            $request = self::client()->createPresignedRequest($cmd, '+' . (int) $ttl . ' seconds');
            return (string) $request->getUri();
        } catch (\Throwable $e) {
            error_log('[s3] presign failed: ' . $e->getMessage());
            return '';
        }
    }

    /** Delete a private object by its raw key. */
    public static function delete_key($key): bool
    {
        if (!self::configured() || (string) $key === '') {
            return false;
        }
        try {
            self::client()->deleteObject(array('Bucket' => self::bucket(), 'Key' => $key));
        } catch (\Throwable $e) {
            error_log('[s3] delete_key failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /* ---- Resumable multipart upload (large videos) ---- */

    /** Begin a multipart upload; returns the S3 UploadId or '' on failure. */
    public static function create_multipart($key, $content_type): string
    {
        if (!self::configured()) {
            return '';
        }
        try {
            $res = self::client()->createMultipartUpload(array(
                'Bucket'      => self::bucket(),
                'Key'         => $key,
                'ContentType' => $content_type,
            ));
            return (string) ($res['UploadId'] ?? '');
        } catch (\Throwable $e) {
            error_log('[s3] create_multipart failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Upload one part. $part_number is 1-based. Returns the part ETag (needed to
     * complete the upload) or '' on failure.
     */
    public static function upload_part($key, $upload_id, $part_number, $body): string
    {
        if (!self::configured()) {
            return '';
        }
        try {
            $res = self::client()->uploadPart(array(
                'Bucket'     => self::bucket(),
                'Key'        => $key,
                'UploadId'   => $upload_id,
                'PartNumber' => (int) $part_number,
                'Body'       => $body,
            ));
            return (string) ($res['ETag'] ?? '');
        } catch (\Throwable $e) {
            error_log('[s3] upload_part failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Finish a multipart upload. $parts is an array of ['PartNumber'=>n,'ETag'=>e]
     * in ascending part order. Returns true on success.
     */
    public static function complete_multipart($key, $upload_id, $parts): bool
    {
        if (!self::configured()) {
            return false;
        }
        try {
            self::client()->completeMultipartUpload(array(
                'Bucket'          => self::bucket(),
                'Key'             => $key,
                'UploadId'        => $upload_id,
                'MultipartUpload' => array('Parts' => array_values($parts)),
            ));
        } catch (\Throwable $e) {
            error_log('[s3] complete_multipart failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /** Abort a multipart upload, freeing any uploaded parts. */
    public static function abort_multipart($key, $upload_id): bool
    {
        if (!self::configured()) {
            return false;
        }
        try {
            self::client()->abortMultipartUpload(array(
                'Bucket'   => self::bucket(),
                'Key'      => $key,
                'UploadId' => $upload_id,
            ));
        } catch (\Throwable $e) {
            error_log('[s3] abort_multipart failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

}
