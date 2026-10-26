<?php

namespace App\Service\Processing;

use App\Entity\Media;
use App\Enum\MediaStatus;
use App\Repository\MediaRepository;

/**
 * Tracks processing progress across phases (one per rendition step) and writes
 * `processing_percentage` at most once per interval, like the legacy StatusManager.
 */
class ProgressReporter
{
    private int $phases       = 1;
    private int $currentPhase = 0;
    private float $phasePercent = 0.0;
    private ?float $lastWrite   = null;

    public function __construct(
        private readonly Media $media,
        private readonly MediaRepository $mediaRepository,
        private readonly float $interval = 1.0,
    ) {
    }

    public function setPhases(int $phases): void
    {
        $this->phases = max(1, $phases);
    }

    public function startPhase(): void
    {
        if ($this->currentPhase === 0) {
            $this->media->setStatus(MediaStatus::Processing);
            $this->write();
        }
        $this->currentPhase++;
        $this->phasePercent = 0.0;
    }

    public function setPhasePercentage(float $percent): void
    {
        $this->phasePercent = max(0.0, min(100.0, $percent));
        $now = microtime(true);
        if ($this->lastWrite === null || $now - $this->lastWrite >= $this->interval) {
            $this->write();
        }
    }

    public function endPhase(): void
    {
        $this->phasePercent = 100.0;
        $this->write();
    }

    public function getOverallPercentage(): float
    {
        $done = max(0, $this->currentPhase - 1) * 100 + $this->phasePercent;
        return round($done / $this->phases, 2);
    }

    private function write(): void
    {
        $this->media->setProcessingPercentage($this->getOverallPercentage());
        $this->mediaRepository->flush();
        $this->lastWrite = microtime(true);
    }
}
