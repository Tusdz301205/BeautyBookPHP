<?php

namespace Tests\Unit;

use App\Services\Media\MediaManagementService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MediaContentValidationTest extends TestCase
{
    public function test_real_avif_is_detected_from_content(): void
    {
        $file=UploadedFile::fake()->createWithContent('image.avif',file_get_contents(base_path('tests/Fixtures/valid-avatar.avif')));
        [$mime,$extension,$size]=app(MediaManagementService::class)->validateContent($file);
        $this->assertSame('image/avif',$mime);
        $this->assertSame('avif',$extension);
        $this->assertGreaterThan(0,$size);
    }

    public function test_image_mime_and_extension_spoof_do_not_pass_content_validation(): void
    {
        foreach([
            UploadedFile::fake()->createWithContent('image.avif','not an image'),
            UploadedFile::fake()->createWithContent('image.png',file_get_contents(base_path('tests/Fixtures/valid-avatar.avif'))),
        ] as $file){
            try{app(MediaManagementService::class)->validateContent($file);$this->fail('Spoofed image was accepted.');}
            catch(\DomainException $error){$this->assertNotEmpty($error->getMessage());}
        }
    }
}
