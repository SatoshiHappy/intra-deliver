<?php

namespace App\Actions\Upload\Api;

use App\Models\Upload\Upload;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 実体だけを消し、台帳の行は残す(口の保証の正本 = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の
 * 2 節の表、呼べる場面 = 6 節の 4 の期限の削除だけ)。主体は呼び手(認可を見ない)。行に purged_at(今)と purge_due_on(期限の日)を書くと
 * 台帳の合計から外れ、実体はコミットの後に消す(巻き戻ったら実体は残る)。持ち主の行が指していても消せる(行は残る)。
 * 無い id・既に消した id は無視する。自分のトランザクションを持ち、呼び手のトランザクションの中では savepoint で含まれる。
 */
class PurgeUploads
{
    /**
     * @param  list<int>  $uploadIds
     * @param  DateTimeInterface  $dueOn  期限の日(日付の部分をそのまま書く。時刻・時差は見ない)
     * @return int 実際に purged_at を書いた行の数(既に消した id・無い id は数えない)
     */
    public function execute(array $uploadIds, DateTimeInterface $dueOn): int
    {
        return DB::transaction(function () use ($uploadIds, $dueOn): int {
            // 実体のパスは purged_at を書く前に台帳の path から引く(UploadQuery::pathOf は消した後の行に null を返す)。
            // 行の鍵で、同じ実体を同時に消す呼び手を並べる(後の呼び手は消した行を読んで無視する)
            $uploads = Upload::query()->whereKey($uploadIds)->whereNull('purged_at')->orderBy('id')
                ->lock('for no key update')->get();

            foreach ($uploads as $upload) {
                $upload->update(['purged_at' => now(), 'purge_due_on' => $dueOn->format('Y-m-d')]);
            }

            $paths = $uploads->pluck('path')->all();
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));

            return count($paths);
        });
    }
}
