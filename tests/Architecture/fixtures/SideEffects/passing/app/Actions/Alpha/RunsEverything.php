<?php

namespace App\Actions\Alpha;

use App\Models\Alpha\AlphaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 業務処理(台帳②の場所)からすべての副作用
 */
final class RunsEverything
{
    public function handle(AlphaItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $item->save();
            AlphaItem::create(['name' => 'x']);
        });
        SendReport::dispatch();
        Mail::raw('body', fn (): null => null);
        Storage::put('report.txt', 'body');
        Http::get('https://example.com');
        Process::run('ls');
        (new \SplFileObject('/tmp/report.txt', 'w'))->fwrite('body');
    }
}
