<?php

namespace App\Actions\Ai;

use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Queries\Ai\AiSettings;
use Illuminate\Support\Facades\DB;

/**
 * 保存期間(設定 retention_days)を過ぎた原文・例外の文を消す(対象と期限の正本 = docs の 設計/AI/データと失敗と検査.md 6 節)。
 * 例外の文は NULL でなく空文字にする(表の検査制約に当たらないように)。
 */
class PruneRunTexts
{
    public function __construct(private AiSettings $settings) {}

    /**
     * @return array{texts: int, errorMessages: int} 消した原文の行の数と、空にした例外の文の数
     */
    public function execute(): array
    {
        $before = now()->subDays($this->settings->retentionDays());

        return DB::transaction(fn (): array => [
            'texts' => AiRunText::query()->where('created_at', '<', $before)->delete(),
            'errorMessages' => AiRun::query()->where('created_at', '<', $before)->where('error_message', '<>', '')->update(['error_message' => '']),
        ]);
    }
}
