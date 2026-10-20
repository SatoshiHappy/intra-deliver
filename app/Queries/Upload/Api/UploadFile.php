<?php

namespace App\Queries\Upload\Api;

/**
 * 台帳の行 1 つ(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節の UploadQuery)。
 */
final readonly class UploadFile
{
    /**
     * @param  string  $mime  中身から推した型
     * @param  string|null  $purgeDueOn  期限で実体を消した時の期限の日(Y-m-d)。消していなければ null
     */
    public function __construct(
        public int $id,
        public int $size,
        public string $mime,
        public ?string $purgeDueOn,
    ) {}
}
