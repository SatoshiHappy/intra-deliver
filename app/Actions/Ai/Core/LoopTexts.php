<?php

namespace App\Actions\Ai\Core;

use App\Enums\Ai\AiInstructionTemplate;

/**
 * ループが使う文の口(受け手。docs の 設計/AI/会話の中核の口と受け手.md 13.3)。指示の組み立ての口(設計/AI/設定とプロンプト/README.md 11.4)を呼んで文を返す。中核に文を
 * 書き写さない。組み立ての誤りは投げる(中核は握らない)。
 *
 * @phpstan-import-type TemplateValues from \App\Actions\Ai\Api\ConversationItem
 */
interface LoopTexts
{
    /**
     * その送信の指示の文と、送る履歴に足す文(回の答え・続き・要約・要約の予備。題はループで送らない)。
     *
     * @param  list<string>  $toolNames  そのステップに渡す道具の名前(要約の依頼の文に入れる)
     * @param  string  $date  先頭の日付の文の値(東京の日 Y-m-d = 設計/AI/会話の中核.md 13.2。要約の予備は置かない)
     */
    public function instructions(SendKind $kind, array $toolNames, string $date): ComposedInstructions;

    /**
     * 定型の文(道具の結果・中断の印・足す文・中核の中の値の型を、送るときに今の版で組む = 13.2)。
     *
     * @param  TemplateValues  $values
     */
    public function template(AiInstructionTemplate $template, array $values): string;

    /** 要約の答えから、要約の文(続ける文を継いだ 1 つの文 = 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5)を組む */
    public function compactSummary(string $answer): string;

    /**
     * 今の版の会話の印の形の一覧(会話の印の形の直し = 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1) に当てる)。
     *
     * @return list<string>
     */
    public function markerForms(): array;
}
