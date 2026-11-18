<?php

namespace App\Actions\Ai\Core;

/**
 * 送る先のモデルとモデルの値(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「1 回の送信の受け取る物」)。中核は調べず、呼ぶ側が渡す。
 */
final readonly class ModelSpec
{
    /**
     * @param  string  $id  モデルの ID(応答の終わりの項目の作ったモデルに写す値 = 設計/AI/会話の中核.md 13.2)
     * @param  int|null  $contextWindow  読める量(ループと量を返す口では必須。任意なのは 1 回の送信だけを使う題)
     * @param  int  $maxOutputTokens  出力の上限
     * @param  int|null  $thinkingBudget  思考の予算(出力の上限の計算のためだけ。繋ぎが送信の種別の足す指定から写す)
     * @param  bool  $usesThinking  考える過程を使うの印(思考の文脈の管理の指定を付けるか = 13.2。予算の無い考え方でも真)
     */
    public function __construct(
        public string $id,
        public ?int $contextWindow,
        public int $maxOutputTokens,
        public ?int $thinkingBudget,
        public bool $usesThinking,
    ) {}
}
