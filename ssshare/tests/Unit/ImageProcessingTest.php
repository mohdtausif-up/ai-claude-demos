<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Image\GifSanitizer;
use App\Services\Image\MetadataInspector;
use App\Services\ImageProcessingService;
use Tests\TestCase;

/** Acceptance: metadata removed and verified; dangerous signatures and spoofed MIME types rejected. */
final class ImageProcessingTest extends TestCase
{
    private ImageProcessingService $images;

    public function setUp(): void
    {
        parent::setUp();
        $this->images = new ImageProcessingService();
    }

    public function testPngIsReencodedAndTextChunksRemoved(): void
    {
        $png = $this->png();
        // Insert a tEXt chunk after IHDR (8 + 25 bytes).
        $data = "Author\0Jane Doe";
        $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
        $dirty = substr($png, 0, 33) . $chunk . substr($png, 33);
        $this->assertSame(['Text chunks'], MetadataInspector::find($dirty, 'png'));

        $out = $this->images->process($dirty);
        $this->assertSame('image/png', $out['mime']);
        $this->assertSame([40, 30], [$out['width'], $out['height']]);
        $this->assertSame(['Text chunks'], $out['removed_metadata']);
        $this->assertSame([], MetadataInspector::find($out['bytes'], 'png'));
        $this->assertNotContains('Jane Doe', $out['bytes']);
        $this->assertTrue($out['thumb'] !== null, 'Thumbnail generated');
    }

    public function testJpegExifAndGpsAreStrippedAndVerified(): void
    {
        $dirty = $this->withExif($this->jpeg());
        // The GD-made fixture also carries a "CREATOR: gd-jpeg" comment.
        $this->assertSame(['EXIF', 'GPS location', 'Comment'], MetadataInspector::find($dirty, 'jpeg'));

        $out = $this->images->process($dirty);
        $this->assertSame(['EXIF', 'GPS location', 'Comment'], $out['removed_metadata']);
        $this->assertSame([], MetadataInspector::find($out['bytes'], 'jpeg'), 'Output carries no metadata');
        $this->assertNotContains('Exif', $out['bytes']);
        $this->assertNotContains('gd-jpeg', $out['bytes'], 'GD creator comment removed');
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($out['bytes']));
        $this->assertFalse(isset($exif['GPSLatitudeRef']), 'exif_read_data finds no GPS data');
    }

    public function testJpegOrientationIsNormalised(): void
    {
        // Orientation 6 = rotate 90° clockwise to display; a 40x20 image becomes 20x40.
        $out = $this->images->process($this->withExif($this->jpeg(40, 20), 6));
        $this->assertSame([20, 40], [$out['width'], $out['height']]);
    }

    public function testWebpRoundTrip(): void
    {
        $im = imagecreatetruecolor(16, 16);
        ob_start();
        imagewebp($im);
        $out = $this->images->process((string) ob_get_clean());
        $this->assertSame('image/webp', $out['mime']);
        $this->assertSame(16, $out['width']);
    }

    public function testAnimatedGifKeepsFramesAndLosesCommentsXmpAndTrailingData(): void
    {
        $gif = $this->animatedGif('TRAILING-POLYGLOT-DATA');
        $out = $this->images->process($gif);
        $this->assertSame('image/gif', $out['mime']);
        $this->assertSame(2, $out['frames'], 'Animation preserved');
        $this->assertNotContains('Secret notes', $out['bytes']);
        $this->assertNotContains('XMP DataXMP', $out['bytes']);
        $this->assertNotContains('TRAILING', $out['bytes']);
        $this->assertContains('NETSCAPE2.0', $out['bytes'], 'Loop extension kept');
        $this->assertSame(2, GifSanitizer::frameCount($out['bytes']));
    }

    public function testPhpPayloadDisguisedAsPngIsRejected(): void
    {
        $this->assertHttpError('dangerous_file', fn () => $this->images->process("<?php system(\$_GET['c']); ?>"), 415);
    }

    public function testPolyglotWithEmbeddedPhpIsRejected(): void
    {
        $this->assertHttpError('malware_detected', fn () => $this->images->process($this->png() . '<?php echo 1; ?>'), 422);
    }

    public function testSvgHtmlExecutablesAndPdfAreRejected(): void
    {
        $cases = [
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>',
            '<!DOCTYPE html><script>alert(1)</script>',
            "MZ\x90\x00\x03" . str_repeat("\0", 60),
            "\x7FELF\x02\x01\x01",
            '%PDF-1.7 fake',
            "PK\x03\x04zip",
        ];
        foreach ($cases as $bytes) {
            $this->assertHttpError('dangerous_file', fn () => $this->images->process($bytes), 415);
        }
    }

    public function testUnknownBytesAreUnsupported(): void
    {
        $this->assertHttpError('unsupported_type', fn () => $this->images->process(random_bytes(256) . 'x'), 415);
    }

    public function testTruncatedImageIsUndecodable(): void
    {
        $this->assertHttpError('undecodable', fn () => $this->images->process(substr($this->jpeg(), 0, 40)), 415);
    }

    public function testDecompressionBombRejectedBeforeDecoding(): void
    {
        // Valid PNG header claiming 20000 x 20000 pixels with almost no data.
        $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1A\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $this->assertHttpError('image_too_large', fn () => $this->images->process($png), 422);
    }

    public function testOversizedFileRejected(): void
    {
        \App\Support\Config::set('app.uploads.max_file_bytes', 1000);
        try {
            $this->assertHttpError('file_too_large', fn () => $this->images->process($this->png(200, 200) . str_repeat("\0", 2000)), 413);
        } finally {
            \App\Support\Config::set('app.uploads.max_file_bytes', 25 * 1024 * 1024);
        }
    }

    public function testDetectTypeIgnoresClientMime(): void
    {
        $this->assertSame('jpeg', ImageProcessingService::detectType($this->jpeg()));
        $this->assertSame('png', ImageProcessingService::detectType($this->png()));
        $this->assertSame('gif', ImageProcessingService::detectType($this->animatedGif()));
        $this->assertSame(null, ImageProcessingService::detectType('GIF8'));
    }
}
