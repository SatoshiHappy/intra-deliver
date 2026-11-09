<?php

namespace App\Actions\Ai;

use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStepText;
use App\Models\Ai\AiRunText;
use App\Models\Ai\AiToolCallText;
use App\Queries\Ai\AiSettings;
use Illuminate\Support\Facades\DB;

/**
 * 保存期間(設定 retention_days)を過ぎた原文・例外の文を消す(対象と期限の正本 = docs の 設計/AI/データと失敗と検査.md 6 節)。
 * 原文は実行・ステップ・道具の呼び出しの 3 つの表から同じ基準で行ごと消し、記録の行は残す。
 * 例外の文は NULL でなく空文字にする(表の検査制約に当たらないように)。
 */
class PruneRunTexts
{
    public function __construct(private AiSettings $settings) {}

    /**
     * 表ごとに消した原文の行の数と、空にした例外の文の数を返す。
     *
     * @return array{texts: int, stepTexts: int, toolCallTexts: int, errorMessages: int}
     */
    public function execute(): array
    {
        $before = now()->subDays($this->settings->retentionDays());

        return DB::transaction(fn (): array => [
            'texts' => AiRunText::query()->where('created_at', '<', $before)->delete(),
            'stepTexts' => AiRunStepText::query()->where('created_at', '<', $before)->delete(),
            'toolCallTexts' => AiToolCallText::query()->where('created_at', '<', $before)->delete(),
            'errorMessages' => AiRun::query()->where('created_at', '<', $before)->where('error_message', '<>', '')->update(['error_message' => '']),
        ]);
    }
}
