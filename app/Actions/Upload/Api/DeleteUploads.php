<?php

namespace App\Actions\Upload\Api;

use App\Actions\Upload\DeleteStoredFiles;
use App\Models\Upload\Upload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 台帳の行を消し、実体はコミットの後に消す(口の保証の正本 = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の
 * 2 節の表、呼べる場面 = 6 節)。主体は呼び手(認可を見ない)。無い id は無視する。
 * 自分のトランザクションを持ち、呼び手のトランザクションの中では savepoint で含まれる(巻き戻ったら実体は残る)。
 * 消せなかったときの扱い = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節の「後から消す実体の削除」。
 */
class DeleteUploads
{
    public function __construct(private DeleteStoredFiles $files) {}

    /**
     * @param  list<int>  $uploadIds
     *
     * @throws QueryException 持ち主の行が指している id があるとき(外部キーの違反。呼び手の誤り = 先に持ち主の行を消す)
     */
    public function execute(array $uploadIds): void
    {
        DB::transaction(function () use ($uploadIds): void {
            $uploads = Upload::query()->whereKey($uploadIds)->get();
            $paths = array_values($uploads->map(fn (Upload $upload): string => $upload->path)->all());
            Upload::query()->whereKey($uploadIds)->delete();
            DB::afterCommit(fn () => $this->files->execute($paths));
        });
    }
}
