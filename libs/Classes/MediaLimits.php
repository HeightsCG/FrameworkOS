<?php
/** Upload size policy shared by the media API (studio_media_types, chunked uploads). */
class MediaLimits {
    const MAX_IMAGE_BYTES = 15 * 1024 * 1024;
    const MAX_VIDEO_BYTES = 4 * 1024 * 1024 * 1024;
    const CHUNK_SIZE      = 8 * 1024 * 1024;
}
