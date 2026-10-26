<?php

namespace App\Twig;

use App\Repository\MediaRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly MediaRepository $mediaRepository,
    ) {
    }

    private const AVATAR_COLORS = [
        '#7a5af8', // primary purple
        '#6d28d9', // deep purple
        '#8b5cf6', // medium purple
        '#5b21b6', // rich purple
        '#9333ea', // violet
        '#a78bfa', // soft purple
        '#7c3aed', // indigo-purple
        '#4c1d95', // dark purple
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('initials', [$this, 'getInitials']),
            new TwigFunction('avatar_color', [$this, 'getAvatarColor']),
            new TwigFunction('processing_problem_count', [$this->mediaRepository, 'countProblems']),
        ];
    }

    public function getInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [''];
        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        $last = mb_strtoupper(mb_substr(end($parts), 0, 1));

        if (count($parts) === 1) {
            return $first;
        }

        return $first . $last;
    }

    public function getAvatarColor(string $name): string
    {
        $hash = crc32($name);
        $index = abs($hash) % count(self::AVATAR_COLORS);

        return self::AVATAR_COLORS[$index];
    }
}
