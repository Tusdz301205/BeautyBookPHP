<?php

namespace Tests\Feature;

use Tests\Support\UsesR2TestBucket;

/** The result-photo suite re-run with the private disk on the S3-protocol bucket: revoking consent must cut off the URL. */
class R2ResultPhotoTest extends ResultPhotoTest
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

    public function test_external_provenance_requires_typed_private_evidence_in_the_same_scope(): void
    {
        $this->markTestSkipped('Deletes a file from the local disk to simulate loss.');
    }
}
