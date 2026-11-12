<?php

namespace Tests\Feature;

use Tests\Support\UsesR2TestBucket;

/** The review-photo suite re-run with the private disk on the S3-protocol bucket: moderation must cut off the URL. */
class R2ReviewPhotoTest extends ReviewPhotoTest
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

    // These assert files on the local disk, which is not where R2 uploads go.
    public function test_upload_uses_file_content_and_rejects_over_size_and_staging_limit(): void
    {
        $this->markTestSkipped('Asserts local-disk contents.');
    }

    public function test_review_and_photo_links_rollback_together_then_retry_with_the_same_ids(): void
    {
        $this->markTestSkipped('Asserts local-disk contents.');
    }
}
