<?php

namespace App\Queries\Ai;

use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * 題の持ち時間 = 実行の時間の上限 × 設定の割合(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.10。割合は 0 より大きく 1 より
 * 小さいことを起動の時に確かめる = 設計/AI/データと失敗と検査.md 8 節)。
 */
final class TitleBudget
{
    private const KEY = 'ai_runs.conversation.title_budget_ratio';

    /** 割合(形が合わなければ null) */
    public function ratio(): ?float
    {
        $ratio = Config::get(self::KEY);
        $number = is_int($ratio) || is_float($ratio);

        return $number && $ratio > 0 && $ratio < 1 ? (float) $ratio : null;
    }

    /**
     * 題の持ち時間(ミリ秒。端数は切り捨て)。
     *
     * @throws LogicException 割合の形が合わないとき(起動の時の確かめで止まる)
     */
    public function milliseconds(int $runTimeoutSeconds): int
    {
        $ratio = $this->ratio() ?? throw new LogicException(self::problem());

        return (int) floor($runTimeoutSeconds * 1000 * $ratio);
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->ratio() === null ? [self::problem()] : [];
    }

    private static function problem(): string
    {
        return 'config/ai_runs.php の conversation.title_budget_ratio が 0 より大きく 1 より小さい数でない';
    }
}
