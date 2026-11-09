<?php

namespace App\Enums\Ai;

/**
 * 道具の呼び出しの判定(docs の 設計/AI/実行の記録.md 2.4。表 ai_tool_calls の decision)。値は DB の検査制約と同じ一覧。
 * 道具を呼ぶ包みの結果(設計/AI/エージェントの実行/道具の包みと止める.md 9.4)と判定の関数の答えを 1 つの列に持ち、判定の前に止めた行は持たない。
 */
enum AiToolCallDecision: string
{
    /** 包みの結果: 無い道具 */
    case UnknownTool = 'unknown_tool';

    /** 包みの結果: 形の誤り */
    case InvalidInput = 'invalid_input';

    /** 包みの結果: 道具の検証の誤り */
    case ValidationError = 'validation_error';

    /** 判定の関数: 許す */
    case Allow = 'allow';

    /** 判定の関数: 断る(理由を持つ) */
    case Deny = 'deny';

    /** 判定の関数: 問う */
    case Ask = 'ask';
}
