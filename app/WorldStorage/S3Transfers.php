<?php

namespace App\WorldStorage;

use Aws\S3\S3Client;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Throwable;

/**
 * Pre-signed S3 links for a VPS (#5347): a GET for the world a start downloads, and for a
 * save a multipart upload (started here with the app's keys) whose parts, completion and
 * read-back the VPS does with links alone. The links expire after $ttlSeconds, which callers
 * set a little above the job's timeout.
 *
 * In the testing and e2e environments (WorldStorage::isFake()) no request is made: links
 * point at fake-s3.invalid, and started/aborted uploads are recorded in the cache.
 */
class S3Transfers
{
    public const FAKE_HOST = 'fake-s3.invalid';

    private const FAKE_KEY = 'fake-s3-uploads';

    public function __construct(
        private readonly WorldStorage $storage,
        private readonly Repository $cache,
    ) {}

    public function downloadUrl(StorageSettings $settings, string $path, int $ttlSeconds): string
    {
        $this->checkS3($settings);

        if ($this->storage->isFake()) {
            return $this->fakeUrl($settings, $path, 'get');
        }

        $client = $this->client($settings);
        $command = $client->getCommand('GetObject', ['Bucket' => $settings->bucket, 'Key' => $settings->objectKey($path)]);

        return (string) $client->createPresignedRequest($command, "+{$ttlSeconds} seconds")->getUri();
    }

    /**
     * Start a multipart upload at $path and sign links for up to $maxParts parts.
     *
     * @throws Throwable when the storage refuses to start it (message is the provider's)
     */
    public function startUpload(StorageSettings $settings, string $path, int $ttlSeconds, int $partBytes, int $maxParts): S3Upload
    {
        $this->checkS3($settings);

        if ($partBytes < 5 * 1024 * 1024 || $maxParts < 1 || $maxParts > 10000) {
            throw new InvalidArgumentException('S3 parts must be at least 5 MB, and an upload has at most 10,000 of them.');
        }

        if ($this->storage->isFake()) {
            $uploadId = 'fake-upload-'.bin2hex(random_bytes(4));
            $this->record('started', $path, $uploadId);

            return new S3Upload(
                path: $path,
                uploadId: $uploadId,
                partUrls: array_map(fn (int $n) => $this->fakeUrl($settings, $path, "part{$n}"), range(1, $maxParts)),
                partBytes: $partBytes,
                completeUrl: $this->fakeUrl($settings, $path, 'complete'),
                readBackUrl: $this->fakeUrl($settings, $path, 'get'),
            );
        }

        $client = $this->client($settings);
        $key = $settings->objectKey($path);
        $base = ['Bucket' => $settings->bucket, 'Key' => $key];
        $uploadId = (string) $client->createMultipartUpload([...$base, 'ContentType' => 'application/zip'])->get('UploadId');
        $expires = "+{$ttlSeconds} seconds";
        $sign = fn (string $name, array $args): string => (string) $client
            ->createPresignedRequest($client->getCommand($name, [...$base, ...$args]), $expires)->getUri();

        $parts = [];

        for ($n = 1; $n <= $maxParts; $n++) {
            $parts[] = $sign('UploadPart', ['UploadId' => $uploadId, 'PartNumber' => $n]);
        }

        return new S3Upload(
            path: $path,
            uploadId: $uploadId,
            partUrls: $parts,
            partBytes: $partBytes,
            completeUrl: $sign('CompleteMultipartUpload', ['UploadId' => $uploadId]),
            readBackUrl: $sign('GetObject', []),
        );
    }

    /**
     * A save that didn't count: drop the unfinished parts, and the object if the VPS finished
     * the upload before the check failed. Never throws: the sweep-free leftovers are logged by
     * the caller, and S3's own lifecycle rules can clean the rest.
     */
    public function abort(StorageSettings $settings, S3Upload $upload): ?string
    {
        if ($this->storage->isFake()) {
            $this->record('aborted', $upload->path, $upload->uploadId);

            return null;
        }

        $problem = null;
        $client = $this->client($settings);
        $base = ['Bucket' => $settings->bucket, 'Key' => $settings->objectKey($upload->path)];

        try {
            $client->abortMultipartUpload([...$base, 'UploadId' => $upload->uploadId]);
        } catch (Throwable $e) {
            // Already completed (NoSuchUpload) is the usual case here.
            $problem = $e->getMessage();
        }

        try {
            $client->deleteObject($base);
        } catch (Throwable $e) {
            $problem = $e->getMessage();
        }

        return $problem;
    }

    /**
     * @return list<array{action: string, path: string, upload_id: string}>
     */
    public function fakeLog(): array
    {
        /** @var list<array{action: string, path: string, upload_id: string}> */
        return $this->cache->get(self::FAKE_KEY, []);
    }

    private function record(string $action, string $path, string $uploadId): void
    {
        $this->cache->forever(self::FAKE_KEY, [...$this->fakeLog(), ['action' => $action, 'path' => $path, 'upload_id' => $uploadId]]);
    }

    private function fakeUrl(StorageSettings $settings, string $path, string $what): string
    {
        return 'https://'.self::FAKE_HOST.'/'.$settings->bucket.'/'.$settings->objectKey($path).'?mvm='.$what.'&X-Amz-Signature=fake';
    }

    private function client(StorageSettings $settings): S3Client
    {
        return new S3Client($settings->s3ClientConfig());
    }

    private function checkS3(StorageSettings $settings): void
    {
        if (! $settings->isS3()) {
            throw new InvalidArgumentException('Pre-signed links are for S3 world storage only.');
        }
    }
}
