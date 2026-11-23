<?php

namespace App\Tests\Client;

use App\Phantasos\MediaStore;
use App\Processor\AudioProcessor;
use App\Processor\ImageProcessor;
use App\Processor\PdfProcessor;
use App\Processor\VideoProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The client's accepted-type lists must match what this server's processors take. */
class AcceptedTypesTest extends KernelTestCase
{
    public function testListsMatchTheProcessors(): void
    {
        $container = static::getContainer();
        $expected  = [
            'IMAGE_TYPES' => $container->get(ImageProcessor::class)->getSupportedMimeTypes(),
            'PDF_TYPES'   => $container->get(PdfProcessor::class)->getSupportedMimeTypes(),
            'AUDIO_TYPES' => $container->get(AudioProcessor::class)->getSupportedMimeTypes(),
            'VIDEO_TYPES' => $container->get(VideoProcessor::class)->getSupportedMimeTypes(),
        ];
        foreach ($expected as $constant => $types) {
            $actual = constant(MediaStore::class . '::' . $constant);
            sort($types);
            sort($actual);
            self::assertSame($types, $actual, "MediaStore::$constant is out of step with the processor");
        }
        self::assertCount(count(array_merge(...array_values($expected))), MediaStore::ACCEPTED_TYPES);
    }
}
