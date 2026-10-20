<?php

namespace App\Support;

use SplFileInfo;
use SplFileObject;

/**
 * 共有から SplFileObject で開く・書く(開くモードに依らず保存に数える)と、get_headers の HTTP
 */
final class WritesWithSplFile
{
    public function write(): void
    {
        $file = new SplFileObject('/tmp/report.txt', 'w');
        $file->fwrite('body');
    }

    public function open(SplFileInfo $info): SplFileObject
    {
        return $info->openFile('r');
    }

    /**
     * @return array<int|string, mixed>|false
     */
    public function headers(): array|false
    {
        return get_headers('https://example.com');
    }
}
