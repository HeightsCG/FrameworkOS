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

}
