<?php

namespace App\Actions\Upload\Api;

use App\Models\Upload\Upload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 台帳の行を消し、実体はコミットの後に消す(口の保証の正本 = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の
 * 2 節の表、呼べる場面 = 6 節)。主体は呼び手(認可を見ない)。無い id は無視する。
 * 自分のトランザクションを持ち、呼び手のトランザクションの中では savepoint で含まれる(巻き戻ったら実体は残る)。
 */
class DeleteUploads
{
    /**
     * @param  list<int>  $uploadIds
     *
     * @throws QueryException 持ち主の行が指している id があるとき(外部キーの違反。呼び手の誤り = 先に持ち主の行を消す)
     */
    public function execute(array $uploadIds): void
    {
        DB::transaction(function () use ($uploadIds): void {
            $paths = Upload::query()->whereKey($uploadIds)->pluck('path')->all();
            Upload::query()->whereKey($uploadIds)->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
        });
    }
}
