<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Services\Media\PrivateMediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesR2TestBucket;
use Tests\TestCase;

/** Storage layer against a real S3-protocol server. Real Cloudflare R2 is not reachable from this environment. */
class R2PrivateMediaTest extends TestCase
{
    use UsesR2TestBucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useR2();
    }

    protected function tearDown(): void
    {
        $this->cleanR2();
        parent::tearDown();
    }

    public function test_private_bytes_round_trip_through_the_bucket_and_stay_unreadable_without_credentials(): void
    {
        Storage::fake('local');
        $bytes = "\x89PNG\r\n\x1a\n".random_bytes(64);
        $file = UploadedFile::fake()->createWithContent('photo.png', $bytes);
        $disk = PrivateMediaDisk::put($file, 'review-photos/round-trip.png');
        $this->assertSame('r2', $disk);
        $media = new MediaFile(['disk' => 'r2', 'path' => 'review-photos/round-trip.png']);
        $this->assertTrue(PrivateMediaDisk::exists($media));
        $this->assertSame($bytes, PrivateMediaDisk::read($media));
        $this->assertFalse(Storage::disk('local')->exists('review-photos/round-trip.png'), 'nothing is written to the local disk');

        $missing = new MediaFile(['disk' => 'r2', 'path' => 'review-photos/never-uploaded.png']);
        $this->assertFalse(PrivateMediaDisk::exists($missing));
        $this->assertNull(PrivateMediaDisk::read($missing));

        // The bucket is private: the same object address without a signature is refused by the server.
        $anonymous = Http::get(rtrim(getenv('R2_TEST_ENDPOINT'), '/').'/'.getenv('R2_TEST_BUCKET').'/'.config('filesystems.disks.r2.root').'/review-photos/round-trip.png');
        $this->assertSame(403, $anonymous->status());

        PrivateMediaDisk::delete('r2', 'review-photos/round-trip.png');
        $this->assertFalse(PrivateMediaDisk::exists($media));
    }

    public function test_verified_reads_serve_only_the_recorded_size_and_hash_and_never_read_far_beyond_them(): void
    {
        Storage::fake('local');
        $bytes = "PNG

".random_bytes(256);
        PrivateMediaDisk::put(UploadedFile::fake()->createWithContent('v.png', $bytes), 'chat-images/verified.png');
        $media = new MediaFile(['disk' => 'r2', 'path' => 'chat-images/verified.png', 'file_size' => strlen($bytes)]);
        $hash = hash('sha256', $bytes);
        $this->assertSame($bytes, PrivateMediaDisk::readVerified($media, strlen($bytes), $hash));

        $refused = function (callable $read): int {
            try {
                $read();
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                return $e->getStatusCode();
            }

            return 0;
        };
        // Replaced objects: other bytes of the same length, a longer one (1 MB), a shorter one.
        foreach (['swapped' => strrev($bytes), 'longer' => $bytes.random_bytes(1024 * 1024), 'shorter' => substr($bytes, 0, -1)] as $case => $replacement) {
            Storage::disk('r2')->put('chat-images/verified.png', $replacement);
            $this->assertSame(404, $refused(fn () => PrivateMediaDisk::readVerified($media, strlen($bytes), $hash)), $case);
        }
        Storage::disk('r2')->put('chat-images/verified.png', $bytes);
        // A reference that does not describe the object, or is malformed, is refused before any object is read.
        $this->assertSame(404, $refused(fn () => PrivateMediaDisk::readVerified($media, strlen($bytes), hash('sha256', 'other'))));
        $this->assertSame(404, $refused(fn () => PrivateMediaDisk::readVerified($media, strlen($bytes), strtoupper($hash))));
        $this->assertSame(404, $refused(fn () => PrivateMediaDisk::readVerified($media, strlen($bytes) + 1, $hash)));
        $this->assertSame(404, $refused(fn () => PrivateMediaDisk::readVerified(new MediaFile(['disk' => 'r2', 'path' => 'chat-images/verified.png', 'file_size' => 11 * 1024 * 1024]), 11 * 1024 * 1024, $hash)));
        // Gone is null (the caller answers 404); an outage is a 503 that reveals nothing.
        $this->assertNull(PrivateMediaDisk::readVerified(new MediaFile(['disk' => 'r2', 'path' => 'chat-images/never.png', 'file_size' => 10]), 10, $hash));
        config(['filesystems.disks.r2.endpoint' => 'http://127.0.0.1:9', 'filesystems.disks.r2.key' => 'SECRET-KEY-VALUE']);
        Storage::forgetDisk('r2');
        $this->assertSame(503, $refused(fn () => PrivateMediaDisk::readVerified($media, strlen($bytes), $hash)));
    }

    public function test_files_uploaded_before_the_switch_stay_readable_from_the_local_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('review-photos/legacy.png', 'legacy bytes');
        $legacy = new MediaFile(['disk' => 'local', 'path' => 'review-photos/legacy.png']);
        $this->assertSame('r2', PrivateMediaDisk::name());
        $this->assertTrue(PrivateMediaDisk::is('local'));
        $this->assertTrue(PrivateMediaDisk::exists($legacy));
        $this->assertSame('legacy bytes', PrivateMediaDisk::read($legacy));
        $this->assertFalse(PrivateMediaDisk::is('public'));
        $this->assertFalse(PrivateMediaDisk::is('s3'));
    }

    public function test_a_bucket_outage_is_a_503_that_reveals_nothing_and_never_falls_back_to_another_disk(): void
    {
        Storage::fake('local');
        PrivateMediaDisk::put(UploadedFile::fake()->createWithContent('a.png', 'x'), 'review-photos/outage.png');
        $media = new MediaFile(['disk' => 'r2', 'path' => 'review-photos/outage.png']);
        config(['filesystems.disks.r2.endpoint' => 'http://127.0.0.1:9', 'filesystems.disks.r2.key' => 'SECRET-KEY-VALUE']);
        Storage::forgetDisk('r2');
        $calls = [
            fn () => PrivateMediaDisk::read($media),
            fn () => PrivateMediaDisk::exists($media),
            fn () => PrivateMediaDisk::put(UploadedFile::fake()->createWithContent('b.png', 'y'), 'review-photos/b.png'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('An unreachable bucket must not succeed');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
                $this->assertStringNotContainsString('SECRET-KEY-VALUE', $e->getMessage());
                $this->assertStringNotContainsString('127.0.0.1', $e->getMessage());
            }
        }
        $this->assertFalse(Storage::disk('local')->exists('review-photos/b.png'));
    }

    public function test_an_unknown_private_disk_setting_falls_back_to_local(): void
    {
        config(['media.private_disk' => 'anything-else']);
        $this->assertSame('local', PrivateMediaDisk::name());
    }
}
