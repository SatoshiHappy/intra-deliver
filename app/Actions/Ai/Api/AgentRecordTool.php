<?php

namespace App\Actions\Ai\Api;

/**
 * 業務モジュールを読み書きする道具の約束(性質が読む・書くの道具 = docs の 設計/AI/道具と権限/README.md 10.1 の性質の表)。ファイルを作る道具は
 * 実装しない(呼び出しが指す業務モジュールを持たない = 設計/AI/データと失敗と検査.md 8 節)。
 */
interface AgentRecordTool extends AgentTool
{
    /**
     * 呼び出しが読み書きする業務モジュールのコード(10.1 の「呼び出しが指す業務モジュール」。無いコードでも返す = 10.3 の B10)。
     * null を返すか例外を投げると、包みの中の例外(設計/AI/エージェントの実行/道具の包みと止める.md 9.4)。
     *
     * @param  array<string, mixed>  $arguments  形の検証を通った引数
     */
    public function moduleOf(array $arguments): ?string;
}
