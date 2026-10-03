<?php

namespace App\Tests\Frontend;

use App\Twig\ViteExtension;
use PHPUnit\Framework\TestCase;

final class ViteExtensionTest extends TestCase
{
    public function testUnbuiltFrontendKeepsServerFallbackAvailable(): void
    {
        self::assertNull((new ViteExtension(dirname(__DIR__, 2).'/.ai/.tmp/nonexistent-build'))->entry());
    }

    public function testManifestResolvesHashedAssetsWithoutExposingServerConfiguration(): void
    {
        $root = dirname(__DIR__, 2).'/.ai/.tmp/vite-test-'.bin2hex(random_bytes(6));
        mkdir($root.'/public/build/.vite', 0777, true);
        file_put_contents($root.'/public/build/.vite/manifest.json', json_encode(['assets/main.js' => ['file' => 'assets/app-123.js', 'css' => ['assets/app-456.css']]]));
        try
        {
            self::assertSame(['script' => '/build/assets/app-123.js', 'styles' => ['/build/assets/app-456.css']], (new ViteExtension($root))->entry());
        }
        finally
        {
            unlink($root.'/public/build/.vite/manifest.json');
            rmdir($root.'/public/build/.vite');
            rmdir($root.'/public/build');
            rmdir($root.'/public');
            rmdir($root);
        }
    }
}
