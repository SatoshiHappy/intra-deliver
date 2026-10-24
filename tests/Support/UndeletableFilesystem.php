<?php

namespace Tests\Support;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;

/**
 * 決めたパスの実体だけ消せない Flysystem のファイルシステム(読み・書き・ほかの実体の削除は本物の adapter のまま)。
 * 実行するユーザーの権限(root かどうか)によらず、消せないときの動きを作るために、削除で UnableToDeleteFile を投げる。
 */
final class UndeletableFilesystem extends Filesystem
{
    /**
     * @param  Closure(string): bool  $fails  消せないパスなら true
     */
    public function __construct(FilesystemAdapter $adapter, private readonly Closure $fails)
    {
        parent::__construct($adapter);
    }

    public function delete(string $location): void
    {
        if (($this->fails)($location)) {
            throw UnableToDeleteFile::atLocation($location, 'テストで消せなくした');
        }

        parent::delete($location);
    }
}
