<?php

namespace App\Actions\Records\Api;

use Illuminate\Http\UploadedFile;

/**
 * レコードの登録・更新の入力(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.1。HTTP に依らない値)。
 * 値は 2 節の値(参照は id の整数、選択はコード)。明細は 6 節の行の配列。
 * 添付は同じ呼び出しで付けるもの(1 つの業務処理 = 版 1 つ = docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1 節)。
 */
final readonly class RecordInput
{
    /**
     * @param  array<array-key, mixed>  $values  項目のコード → 値
     * @param  array<array-key, list<RecordDetailRowInput>>  $details  明細の項目のコード → 行(配列の順が行の順)
     * @param  list<UploadedFile>  $attachFiles  この送信で上げるファイル
     * @param  list<RecordUploadInput>  $attachUploads  一時保存から移す上げ済みの実体(件数の上限は置かない)
     */
    public function __construct(
        public array $values = [],
        public array $details = [],
        public array $attachFiles = [],
        public array $attachUploads = [],
    ) {}

    /**
     * 同じ呼び出しで付ける添付(ファイルか上げ済みの実体)があるか。
     */
    public function hasAttachments(): bool
    {
        return $this->attachFiles !== [] || $this->attachUploads !== [];
    }
}
