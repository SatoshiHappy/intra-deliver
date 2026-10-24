<?php

namespace App\Actions\Upload;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;

/**
 * 後から消す実体の削除(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節の「後から消す実体の削除」)。
 * disk の report でなくここで報告するのは、読み・書きの失敗まで報告しないため。
 */
class DeleteStoredFiles
{
    /**
     * @param  list<string>  $paths  disk 内のパス。無い実体は黙って通す
     * @return int 消せなかった実体の数
     */
    public function execute(array $paths): int
    {
        $driver = Storage::disk('local')->getDriver();
        $failed = 0;

        foreach ($paths as $path) {
            try {
                $driver->delete($path);
            } catch (UnableToDeleteFile $exception) {
                report($exception);
                $failed++;
            }
        }

        return $failed;
    }
}
