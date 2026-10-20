<?php

namespace App\Queries\Upload\Api;

use App\Models\Upload\Upload;

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
}
