<?php

namespace App\Tests\Configuration;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testLocalDotenvDoesNotOverrideExternalProcessEnvironment(): void
    {
        $root = dirname(__DIR__, 2);
        $temporary = $root.'/.ai/.tmp/bootstrap-'.bin2hex(random_bytes(6));
        mkdir($temporary.'/config', 0777, true);
        mkdir($temporary.'/vendor');
        mkdir($temporary.'/.ai/creds', 0777, true);
        copy($root.'/config/bootstrap.php', $temporary.'/config/bootstrap.php');
        file_put_contents($temporary.'/vendor/autoload.php', '<?php require '.var_export($root.'/vendor/autoload.php', true).';');
        file_put_contents($temporary.'/.env.example', "APP_ENV=dev\nAPP_DEBUG=0\n");
        file_put_contents($temporary.'/.env', "APP_ENV=dev\nAPP_DEBUG=1\n");
        file_put_contents($temporary.'/.env.local', "APP_DEBUG=1\n");
        file_put_contents($temporary.'/.ai/creds/prod.env', "DATABASE_USER=agent-profile-only\n");
        $code = 'require '.var_export($temporary.'/config/bootstrap.php', true).'; echo ($_SERVER["APP_ENV"] === "prod" && $_SERVER["APP_DEBUG"] === "0" && !isset($_SERVER["DATABASE_USER"])) ? "preserved" : "overridden";';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'SystemRoot' => getenv('SystemRoot')]);
        try
        {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertSame('preserved', $output);
        }
        finally
        {
            foreach (['config/bootstrap.php', 'vendor/autoload.php', '.env.example', '.env', '.env.local', '.ai/creds/prod.env'] as $file)
            {
                unlink($temporary.'/'.$file);
            }
            rmdir($temporary.'/config');
            rmdir($temporary.'/vendor');
            rmdir($temporary.'/.ai/creds');
            rmdir($temporary.'/.ai');
            rmdir($temporary);
        }
    }
}
