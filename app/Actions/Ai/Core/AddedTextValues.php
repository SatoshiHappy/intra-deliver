<?php

namespace App\Actions\Ai\Core;

/**
 * ループが受け取る足す文の値(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「ループの受け取る物」、足す時と所 = 設計/AI/会話の中核.md 13.2)。
 *
 * @phpstan-import-type TemplateValues from \App\Actions\Ai\Api\ConversationItem
 */
final readonly class AddedTextValues
{
    /**
     * @param  string  $firstRunDate  会話の最初の実行の始めの日(東京の日 Y-m-d。送る履歴に要約の境目が無いときの先頭の日付の文の値)
     * @param  TemplateValues  $unknownWrites  結果の分からない書き込みの知らせの値(繋ぎが 設計/AI/エージェントの実行/途中の表示と上限.md 9.8 で組んだ値。空なら足さない)
     * @param  list<string>  $unknownWriteCallIds  その知らせが知らせる呼び出しの id(画面にもモデルにも出さない値 =
     *                                             設計/AI/エージェントの実行/途中の表示と上限.md 9.8)
     */
    public function __construct(
        public string $firstRunDate,
        public array $unknownWrites = [],
        public array $unknownWriteCallIds = [],
    ) {}
}
