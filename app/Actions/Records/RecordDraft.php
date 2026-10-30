<?php

namespace App\Actions\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;

/**
 * 保存する 1 つのレコード(親か明細の行)の作業中の値(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 3〜9)。
 * 入力の値 input(4 で正規化)と、合わせた値 values(5 で作り、6 で正規化)を、項目のコードで持つ。参照の値は相手の id
 * (型「ユーザーと組織」は相手の {type, id})。
 * input・values を書くのは {@see RecordValidator} だけ(書き手を 1 か所にするため。業務処理の内部の型で、公開の口に出さない)。
 */
final class RecordDraft
{
    /** @var array<string, mixed> 入力の値(項目のコード → 正規の形の値) */
    public array $input = [];

    /** @var array<string, mixed> 合わせた値(明細を除く項目のコード → 値) */
    public array $values = [];

    /**
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール(明細の行なら明細のモジュール)
     * @param  Record|null  $record  今の行(null = 新しく作る。参照の行 recordReferences を読み込んでおく)
     * @param  string  $keyPrefix  エラーのキーの前置き(親は ''、明細の行は 'details.<コード>.<添字>.')
     * @param  array<array-key, mixed>  $rawValues  入力の値(前後の空白を除いたもの)
     * @param  int|null  $parentRecordId  明細の行の親のレコード(参照で指せない相手)
     */
    public function __construct(
        public readonly RecordModule $module,
        public readonly ?Record $record,
        public readonly string $keyPrefix = '',
        public readonly array $rawValues = [],
        public readonly int $sortKey = 0,
        public readonly ?int $parentRecordId = null,
    ) {}

    /**
     * 項目のエラーのキー(10 節)。
     */
    public function key(string $code): string
    {
        return $this->keyPrefix.'values.'.$code;
    }
}
