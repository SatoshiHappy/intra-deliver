<?php

namespace Tests\Support;

use App\Models\Records\RecordModule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * 業務モジュールの共有の鍵を取った直後に、その業務モジュールの行を書き換える(鍵の後に読んだ値を使うかを確かめる。docs の
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md の 9.2 の 1)。書き換えは 1 回だけ、鍵の問い合わせと束縛の値
 * (その業務モジュールの鍵)の両方が合うときだけ動く。確かめる呼び出しの直前に呼ぶ(準備の書き込みが同じ鍵を取っても動かない)。
 */
trait ChangesModuleAfterLock
{
    /** 業務モジュールの鍵の起点(App\Actions\Records\LocksRecordModule の RECORD_MODULE_LOCK_BASE と同じ値) */
    private const MODULE_LOCK_BASE = 4_200_000_000_000_000;

    /**
     * @param  array<string, mixed>  $attributes  鍵の後に書く業務モジュールの列
     */
    protected function changeModuleAfterLock(RecordModule $module, array $attributes): void
    {
        $armed = true;
        $key = self::MODULE_LOCK_BASE + $module->id;

        DB::listen(function (QueryExecuted $query) use (&$armed, $key, $module, $attributes): void {
            if (! $armed || ! str_contains($query->sql, 'pg_advisory_xact_lock_shared(')
                || ($query->bindings[0] ?? null) !== $key) {
                return;
            }

            $armed = false;
            RecordModule::query()->whereKey($module->id)->update($attributes);
        });
    }
}
