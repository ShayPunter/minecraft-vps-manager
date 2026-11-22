<?php

namespace App\WorldStorage;

/**
 * A multipart upload the app started for one world save (#5347), and the pre-signed links
 * the VPS uses to finish it. The links are the only thing the VPS gets: no S3 keys.
 */
final readonly class S3Upload
{
    /**
     * @param  list<string>  $partUrls  PUT links for parts 1..n, in order
     */
    public function __construct(
        public string $path,
        public string $uploadId,
        public array $partUrls,
        public int $partBytes,
        public string $completeUrl,
        public string $readBackUrl,
    ) {}
}
