<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Points the private media disk at a real S3-compatible bucket (MinIO in the test project) under a throwaway prefix. */
trait UsesR2TestBucket
{
    private ?string $r2Prefix = null;

    protected function useR2(): void
    {
        if (! getenv('R2_TEST_ENDPOINT')) {
            $this->markTestSkipped('R2_TEST_ENDPOINT is not set; start the test bucket with docker-compose.r2-test.yml.');
        }
        $this->r2Prefix = 'phpunit/'.Str::uuid();
        config([
            'media.private_disk' => 'r2',
            'filesystems.disks.r2' => ['driver' => 's3', 'key' => getenv('R2_TEST_KEY'), 'secret' => getenv('R2_TEST_SECRET'), 'region' => 'auto', 'bucket' => getenv('R2_TEST_BUCKET'),
                'endpoint' => getenv('R2_TEST_ENDPOINT'), 'root' => $this->r2Prefix, 'use_path_style_endpoint' => true, 'throw' => true, 'report' => false],
        ]);
        Storage::forgetDisk('r2');
    }

    protected function cleanR2(): void
    {
        if ($this->r2Prefix === null) return;
        try {
            Storage::disk('r2')->deleteDirectory('');
        } catch (\Throwable) {
            // Leftover test objects live under a unique prefix and are removed with the test volume.
        }
        $this->r2Prefix = null;
    }
}
