<?php

namespace App\Tests;

final class UiFixtures
{
    public static function save(string $name, string $html): void
    {
        $directory = dirname(__DIR__).'/.ai/.tmp/ui-fixtures';
        if (!is_dir($directory))
        {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}
