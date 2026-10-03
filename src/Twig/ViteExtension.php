<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ViteExtension extends AbstractExtension
{
    public function __construct(private string $projectDir)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('vite_entry', [$this, 'entry'])];
    }

    public function entry(): ?array
    {
        $path = $this->projectDir.'/public/build/.vite/manifest.json';
        if (!is_file($path))
        {
            return null;
        }
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $entry = $manifest['assets/main.js'];
        return ['script' => '/build/'.$entry['file'], 'styles' => array_map(static fn (string $file): string => '/build/'.$file, $entry['css'] ?? [])];
    }
}
