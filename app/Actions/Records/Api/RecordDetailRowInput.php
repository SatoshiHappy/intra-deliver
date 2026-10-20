<?php

namespace App\Actions\Records\Api;

/**
 * 明細の行の入力(docs の 設計/業務データの入れ物/項目と規則/README.md 6 節): 既にある行は id を持ち、新しい行は持たない。
 */
final readonly class RecordDetailRowInput
{
    /**
     * @param  int|null  $id  既にある行のレコードの id(null = 新しい行)
     * @param  array<array-key, mixed>  $values  明細のモジュールの項目のコード → 値
     */
    public function __construct(
        public ?int $id = null,
        public array $values = [],
    ) {}
}
