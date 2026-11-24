<?php

namespace App\Tests\Unit\Service;

use App\Service\Processing\Ffmpeg;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class FfmpegDecodeImageTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phantasos-ffmpeg-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    public function testLargeImagesAreScaledToFitKeepingTheirShape(): void
    {
        $image = imagecreatetruecolor(3000, 1000);
        self::assertNotFalse($image);
        imagepng($image, $input = $this->tmp . '/wide.png');

        (new Ffmpeg())->decodeImage($input, $output = $this->tmp . '/out.png', 2048);

        self::assertSame([2048, 683], array_slice((array) getimagesize($output), 0, 2));
        self::assertFileDoesNotExist($output . '.scaled.png');
    }

    public function testSmallImagesAreLeftAlone(): void
    {
        (new Ffmpeg())->decodeImage(__DIR__ . '/../../fixtures/images/halves.tiff', $output = $this->tmp . '/out.png');

        self::assertSame([120, 60], array_slice((array) getimagesize($output), 0, 2));
    }
}
