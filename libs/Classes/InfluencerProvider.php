<?php
/**
 * Hosted inference provider contract for the influencer feature. One class per provider
 * (see InfluencerConfig::PROVIDERS); every method is static and never throws for an
 * expected failure — it returns ['ok' => false, 'error' => provider text, 'error_code' => ...,
 * 'retryable' => bool]. `retryable` decides whether InfluencerJobService moves on to the next
 * provider in the configured fallback list.
 *
 * Request shape ($req), built by InfluencerJobService from the job row:
 *   endpoint, params (fixed inputs from the catalog), prompt, negative_prompt, seed,
 *   num_images, image_size (square|portrait|landscape), aspect_ratio,
 *   loras => [['url' => presigned, 'scale' => float]], image_urls => [presigned...],
 *   image_url (single input), duration, images_data_url, trigger_word, steps
 *
 * Handle shape (persisted on the job): provider, provider_job_id, status_url, response_url, cancel_url.
 *
 * Output shape (fetch_result): outputs => [['kind' => image|video|lora, 'url', 'content_type',
 *   'width', 'height', 'file_size', 'seed']], seed, raw
 */
interface InfluencerProvider {

    /** Provider key as used in InfluencerConfig ('fal'). */
    public static function key(): string;

    /** ['ops' => ['image' => true, 'video' => true, 'training' => true, 'enhance' => true]]. */
    public static function capabilities(): array;

    /** Submit an image job (text-to-image, reference edit, or LoRA inference). Returns ok + handle. */
    public static function generate_image(array $req): array;

    /** Submit an image-to-video job. Returns ok + handle. */
    public static function generate_video(array $req): array;

    /** Submit a LoRA training job. Returns ok + handle. */
    public static function train_model(array $req): array;

    /** ['ok', 'state' => queued|running|completed|failed, 'error', 'error_code', 'retryable', 'raw']. */
    public static function get_job_status(array $handle): array;

    /** Fetch the finished job's outputs (see class doc). */
    public static function fetch_result(array $handle): array;

    /** Best-effort cancel; true when the provider accepted it. */
    public static function cancel(array $handle): bool;

    /** Only outputs hosted by the provider itself may be downloaded into the library. */
    public static function output_url_allowed($url): bool;
}
