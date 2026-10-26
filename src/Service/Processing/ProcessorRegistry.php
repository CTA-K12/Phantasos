<?php

namespace App\Service\Processing;

use App\Enum\MediaType;
use App\Processor\ProcessorInterface;

class ProcessorRegistry
{
    /** @var array<string, ProcessorInterface> */
    private array $byMime = [];

    /** @var array<string, ProcessorInterface> */
    private array $byType = [];

    /** @param iterable<ProcessorInterface> $processors */
    public function __construct(iterable $processors)
    {
        foreach ($processors as $processor) {
            $this->byType[$processor->getMediaType()->value] = $processor;
            foreach ($processor->getSupportedMimeTypes() as $mime) {
                $this->byMime[$mime] = $processor;
            }
        }
    }

    /** Null means the upload is rejected as unsupported. */
    public function mediaTypeForMime(string $mimeType): ?MediaType
    {
        return ($this->byMime[$mimeType] ?? null)?->getMediaType();
    }

    public function forType(MediaType $type): ?ProcessorInterface
    {
        return $this->byType[$type->value] ?? null;
    }
}
