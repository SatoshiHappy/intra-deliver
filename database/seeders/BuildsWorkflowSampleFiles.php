<?php

namespace Database\Seeders;

use Illuminate\Http\UploadedFile;

/**
 * {@see WorkflowSeeder} の見本の添付のファイル(docs の 設計/ワークフロー/申請の周辺/初期データ.md の 2 節)。元は `database/seeders/files/` に置く。
 */
trait BuildsWorkflowSampleFiles
{
    /**
     * 画面から送られたファイルと同じ形(元の名前 = 添付の表示名)にする。保存の処理は元を読むだけで動かさない。
     *
     * @param  string  $file  `database/seeders/files/` の中のファイル名
     * @param  string  $displayName  添付の表示名
     */
    private function sampleFile(string $file, string $displayName): UploadedFile
    {
        return new UploadedFile(__DIR__.'/files/'.$file, $displayName, 'application/pdf', null, true);
    }
}
