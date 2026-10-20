<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 共有からトランザクション・通知・ファイルの保存・プロセスの起動
 */
final class RunsEffects
{
    public function transaction(): void
    {
        DB::transaction(fn (): null => null);
    }

    public function mail(): void
    {
        Mail::raw('body', fn (): null => null);
    }

    public function store(): void
    {
        Storage::put('report.txt', 'body');
        file_put_contents('/tmp/report.txt', 'body');
    }

    public function run(): void
    {
        Process::run('ls');
        exec('ls');
        $output = `ls`;
    }
}
