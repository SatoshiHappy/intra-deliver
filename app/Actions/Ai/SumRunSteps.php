<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiKindType;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use LogicException;

/**
 * 会話の型の実行の表示用の見積もり・費用・使用量の和(docs の 設計/AI/実行の記録.md 2.1 の「会話の型が持つもの」の「見積もり・使用量・費用の和」)。
 * 和を返し、終わり方を書く呼び手が実行の行の 1 回の書き込みに入れる(同じ行を 2 回書かない)。
 */
class SumRunSteps
{
    /** 使用量の和の鍵(実行の行の使用量の列の名前) */
    private const USAGE_KEYS = [
        'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens',
    ];

    private const CODE_EXECUTION_SECONDS = 'code_execution_seconds';

    /**
     * 使用量の分からないステップは使用量と費用の列が空(docs の 設計/AI/実行の記録.md 2.3 の検査制約)なので、列の和がそのまま分かった分の和になる。
     * 額は列と同じ小数 6 桁で返す(足すステップが無いときの 0 も)。
     *
     * @return array{step_reserved_sum_usd: string, step_cost_sum_usd: string, step_usage_sum: array<string, int|float>}
     *
     * @throws LogicException 会話の型でない実行のとき(作りの誤り。ほかの型の行は和の列を持てない)
     */
    public function columns(AiRun $run): array
    {
        if ($run->kind_type !== AiKindType::Conversation) {
            throw new LogicException("AI の実行 {$run->id} は会話の型でないので、ステップの和を持たない");
        }

        $sums = [
            'coalesce(sum(reserved_cost_usd), 0)::numeric(12, 6) AS reserved_cost_usd',
            'coalesce(sum(cost_usd), 0)::numeric(12, 6) AS cost_usd',
            ...array_map(
                fn (string $column): string => "coalesce(sum({$column}), 0) AS {$column}",
                [...self::USAGE_KEYS, self::CODE_EXECUTION_SECONDS],
            ),
        ];
        $row = AiRunStep::query()->where('ai_run_id', $run->id)->selectRaw(implode(', ', $sums))->toBase()->sole();
        $usage = [];

        foreach (self::USAGE_KEYS as $key) {
            $usage[$key] = (int) $row->{$key};
        }

        $usage[self::CODE_EXECUTION_SECONDS] = (float) $row->{self::CODE_EXECUTION_SECONDS};

        return [
            'step_reserved_sum_usd' => (string) $row->reserved_cost_usd,
            'step_cost_sum_usd' => (string) $row->cost_usd,
            'step_usage_sum' => $usage,
        ];
    }
}
