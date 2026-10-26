<?php

namespace App\Controller;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_STAFF')]
class HelpController extends AbstractController
{
    #[Route('/help/{page}', name: 'help_show', methods: ['GET'])]
    public function show(string $page, KernelInterface $kernel): Response
    {
        $base = $kernel->getProjectDir() . '/docs/help/';

        // Sanitize: allow only alphanumeric + underscores (valid route name characters)
        $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($page));

        $path = $base . $safe . '.md';
        if (!is_file($path)) {
            $path = $base . '_default.md';
        }

        $markdown = is_file($path)
            ? (file_get_contents($path) ?: '')
            : '_No help is available for this page yet._';

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = $converter->convert($markdown)->getContent();

        return $this->render('help/show.html.twig', [
            'content' => $html,
        ]);
    }
}
