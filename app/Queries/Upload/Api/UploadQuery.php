<?php

namespace App\Queries\Upload\Api;

use App\Models\Upload\Upload;
use Illuminate\Support\Facades\Storage;

/**
 * 台帳の行の読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節)。主体の判定をしない。
 */
class UploadQuery
{
    /**
     * @param  list<int>  $uploadIds
     * @return array<int, UploadFile> id → 行(無い id は入らない)
     */
    public function find(array $uploadIds): array
    {
        $files = [];

        foreach (Upload::query()->whereKey($uploadIds)->orderBy('id')->get() as $upload) {
            $dueOn = $upload->purge_due_on?->toDateString();
            $files[$upload->id] = new UploadFile($upload->id, $upload->size, $upload->mime, $dueOn);
        }

        return $files;
    }

    /**
     * 実体のファイルの絶対パス(ディスク local = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 4 節)。
     * 行が無い・期限で実体を消した行は例外にしない(処理中にレコードが消された・期限の削除が走ったときに起こる予期する結果 = 同じ 2 節)。
     * 呼び手が別のプロセスに渡して開くので、ファイルの有無は見ない(開く時に分かる)。
     *
     * @return string|null 実体の絶対パス。台帳の行が無い・期限で実体を消した行は null
     */
    public function pathOf(int $uploadId): ?string
    {
        $upload = Upload::query()->find($uploadId);

        if ($upload === null || $upload->purged_at !== null) {
            return null;
        }

        return Storage::disk('local')->path($upload->path);
    }
}
