<?php

namespace App\Service\Processing;

use Symfony\Component\Process\Process;

/**
 * Thin wrapper over the ffmpeg/ffprobe binaries (no PHP bindings). Arguments are passed
 * as arrays, never through a shell, so file names cannot inject options.
 */
class Ffmpeg
{
    public function __construct(
        private readonly string $ffmpegBinary = 'ffmpeg',
        private readonly string $ffprobeBinary = 'ffprobe',
        private readonly int $timeout = 14400,
        private readonly int $threads = 0,
    ) {
    }

    /** @return array{duration: float, hasVideo: bool, hasAudio: bool} */
    public function probe(string $path): array
    {
        $process = new Process([
            $this->ffprobeBinary, '-v', 'error', '-print_format', 'json',
            '-show_entries', 'format=duration:stream=codec_type', $path,
        ]);
        $process->setTimeout(120);
        $process->mustRun();

        /** @var array{format?: array{duration?: string}, streams?: list<array{codec_type?: string}>} $data */
        $data  = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $types = array_column($data['streams'] ?? [], 'codec_type');

        return [
            'duration' => (float) ($data['format']['duration'] ?? 0),
            'hasVideo' => in_array('video', $types, true),
            'hasAudio' => in_array('audio', $types, true),
        ];
    }

    /**
     * Decodes a still image GD can't read (HEIC/HEIF, TIFF) to PNG: the primary image of a HEIC
     * (tile grids reassembled), the first page of a TIFF, with the file's rotation applied.
     * Large images are scaled to fit $maxSide, which is plenty for the renditions and keeps a
     * 48-megapixel phone photo from filling the worker's memory in GD.
     */
    public function decodeImage(string $input, string $output, int $maxSide = 2048): void
    {
        $this->stillToPng($input, $output, []);

        // HEIC tile grids are assembled by ffmpeg's own filter graph, which can't take a scale
        // filter as well, so large images are scaled in a second pass over the PNG.
        $size = @getimagesize($output);
        if (is_array($size) && max($size[0], $size[1]) > $maxSide) {
            $scaled = $output . '.scaled.png';
            try {
                $this->stillToPng($output, $scaled, [
                    '-vf', sprintf("scale='min(%1\$d,iw)':'min(%1\$d,ih)':force_original_aspect_ratio=decrease", $maxSide),
                ]);
                rename($scaled, $output);
            } finally {
                @unlink($scaled);
            }
        }
    }

    /** @param list<string> $filters */
    private function stillToPng(string $input, string $output, array $filters): void
    {
        $process = new Process([
            $this->ffmpegBinary, '-hide_banner', '-nostdin', '-loglevel', 'error', '-y',
            '-i', $input,
            '-frames:v', '1', '-update', '1',
            ...$filters,
            '-f', 'image2', '-c:v', 'png',
            $output,
        ]);
        $process->setTimeout(300);
        $process->mustRun();
    }

    /**
     * Runs one encode. $onProgress receives 0–100 based on ffmpeg's -progress output.
     *
     * @param list<string>          $args       everything after the input file
     * @param callable(float): void $onProgress
     */
    public function encode(string $input, array $args, float $duration, ?callable $onProgress = null): void
    {
        $command = [
            $this->ffmpegBinary, '-hide_banner', '-nostdin', '-loglevel', 'error', '-y',
            '-threads', (string) $this->threads,
            '-i', $input,
            ...$args,
            '-progress', 'pipe:1',
        ];
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->mustRun(function (string $type, string $buffer) use ($duration, $onProgress): void {
            if ($type !== Process::OUT || $onProgress === null || $duration <= 0) {
                return;
            }
            if (preg_match_all('/^out_time_us=(\d+)$/m', $buffer, $m) > 0) {
                $seconds = (int) end($m[1]) / 1_000_000;
                $onProgress(min(100.0, $seconds / $duration * 100));
            }
        });
    }
}
