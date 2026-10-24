<?php

namespace App\Actions\Upload;

use App\Actions\Upload\Api\StoreUploads;
use App\Models\Upload\Upload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;

/**
 * 台帳が持たない実体を消す掃除(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 6 節の 5)。
 * 自分のモジュールのコマンドだけが呼ぶ。
 */
class PruneUploadFiles
{
    /** 台帳を読み直す 1 回のパスの数 */
    public const CHUNK_SIZE = 1_000;

    /** 書いてから消すまでの待ち(秒。ちょうどこの秒数の実体は残す) */
    private const GRACE_SECONDS = 86_400;

    public function __construct(private DeleteStoredFiles $files) {}

    /**
     * @return array{deleted: int, failed: int}
     */
    public function execute(): array
    {
        $deleted = 0;
        $failed = 0;

        foreach (array_chunk($this->oldPaths(), self::CHUNK_SIZE) as $paths) {
            $owned = Upload::query()->whereIn('path', $paths)->whereNull('purged_at')->pluck('path')->all();

            $targets = array_values(array_diff($paths, $owned));
            $stuck = $this->files->execute($targets);
            $deleted += count($targets) - $stuck;
            $failed += $stuck;
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * 書いてから 1 日を過ぎた実体のパス(一覧の時の更新時刻で選ぶ。1 件ずつ引くと、一覧の後に消えた実体で投げられるため)。
     *
     * @return list<string>
     */
    private function oldPaths(): array
    {
        $limit = CarbonImmutable::now()->getTimestamp() - self::GRACE_SECONDS;
        $paths = [];

        foreach (Storage::disk('local')->getDriver()->listContents(StoreUploads::DIRECTORY) as $item) {
            if ($this->isOld($item, $limit)) {
                $paths[] = $item->path();
            }
        }

        return $paths;
    }

    private function isOld(StorageAttributes $item, int $limit): bool
    {
        return $item->isFile() && $item->lastModified() !== null && $item->lastModified() < $limit;
    }
}
