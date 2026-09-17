<?php

namespace Tests\Unit\Services\Image;

use App\Services\Image\ImageWriter;
use App\Values\ImageWritingConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

use function Tests\test_path;

class ImageWriterTest extends TestCase
{
    #[Test]
    public function doesNotUpscaleImagesNarrowerThanTheMaxWidth(): void
    {
        $source = test_path('fixtures/cover.png');
        $sourceWidth = Image::fromPath($source)->width();

        $destination = sys_get_temp_dir() . '/' . Str::uuid() . '.img';

        (new ImageWriter())->write($destination, $source, ImageWritingConfig::make(maxWidth: $sourceWidth * 2));

        // Re-encode before measuring: the written format may be one that getimagesize() can't read,
        // even when the image driver is perfectly able to encode it.
        self::assertSame($sourceWidth, Image::fromPath($destination)->toPng()->width());

        File::delete($destination);
    }

    #[Test]
    public function aFailedFetchNamesItsCause(): void
    {
        Http::fake(['https://example.com/cover.jpg' => Http::response('', 429)]);

        $destination = sys_get_temp_dir() . '/' . Str::uuid() . '.img';

        try {
            (new ImageWriter())->write($destination, 'https://example.com/cover.jpg');
            self::fail('A 429 should not produce an image');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('https://example.com/cover.jpg', $e->getMessage());
            self::assertStringContainsString('429', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
    }
}
