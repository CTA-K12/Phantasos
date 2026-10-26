<?php

namespace App\Tests\Unit\Twig;

use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;

class AppExtensionTest extends TestCase
{
    private AppExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new AppExtension($this->createStub(\App\Repository\MediaRepository::class));
    }

    // ─────────────────────────────────────────────────────────────
    // getInitials()
    // ─────────────────────────────────────────────────────────────

    public function testInitialsTwoWords(): void
    {
        $this->assertSame('JS', $this->ext->getInitials('Jordan Staff'));
    }

    public function testInitialsOneWord(): void
    {
        $this->assertSame('J', $this->ext->getInitials('Jordan'));
    }

    public function testInitialsThreeWordsUsesFirstAndLast(): void
    {
        // Three words → first letter of first word + first letter of last word
        $this->assertSame('JS', $this->ext->getInitials('Jordan Michael Staff'));
    }

    public function testInitialsUppercasesLetters(): void
    {
        $this->assertSame('JS', $this->ext->getInitials('jordan staff'));
    }

    public function testInitialsHandlesExtraWhitespace(): void
    {
        $this->assertSame('JS', $this->ext->getInitials('  Jordan   Staff  '));
    }

    // ─────────────────────────────────────────────────────────────
    // getAvatarColor()
    // ─────────────────────────────────────────────────────────────

    public function testAvatarColorReturnsHexString(): void
    {
        $color = $this->ext->getAvatarColor('Jordan Staff');

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $color);
    }

    public function testAvatarColorIsDeterministic(): void
    {
        $color1 = $this->ext->getAvatarColor('Jordan Staff');
        $color2 = $this->ext->getAvatarColor('Jordan Staff');

        $this->assertSame($color1, $color2);
    }

    public function testAvatarColorDiffersForDifferentNames(): void
    {
        // Two different names should (almost certainly) yield different colors
        $color1 = $this->ext->getAvatarColor('Alice Adams');
        $color2 = $this->ext->getAvatarColor('Bob Baker');

        // Not guaranteed to differ, but true for these specific inputs
        $this->assertNotSame($color1, $color2);
    }
}
