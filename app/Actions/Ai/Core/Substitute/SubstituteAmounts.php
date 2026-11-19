<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\CompactThresholds;
use App\Actions\Ai\Core\ModelSpec;
use LogicException;

/**
 * 代わりの版の量(自前の式。式を版ごとに持つ = docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.9 の「量」、見積もりに係数の前のトークンの式を
 * 持たない = 設計/AI/実行と費用/接続先と費用.md 5.3)。発動の量を止める量より下に置くのは、要約の依頼と答えを止める量の内に収めるため。
 */
final class SubstituteAmounts
{
    /** 発動の量の、止める量に対する割合(分子 / 分母) */
    private const COMPACT_NUMERATOR = 4;

    private const COMPACT_DENOMINATOR = 5;

    /**
     * @throws LogicException 読める量が無いか、出力の上限以下のとき(作りの誤り)
     */
    public static function of(ModelSpec $model): CompactThresholds
    {
        $stopAt = self::window($model) - $model->maxOutputTokens;

        if ($stopAt <= 0) {
            throw new LogicException("モデル {$model->id} の読める量が出力の上限以下");
        }

        return new CompactThresholds(intdiv($stopAt * self::COMPACT_NUMERATOR, self::COMPACT_DENOMINATOR), $stopAt);
    }

    /**
     * @throws LogicException 読める量が無いとき
     */
    public static function window(ModelSpec $model): int
    {
        return $model->contextWindow ?? throw new LogicException("モデル {$model->id} に読める量が無い");
    }

    /** 送る量の見積もり(トークン。下回らない側に切り上げる) */
    public static function estimate(int $chars, float $factor): int
    {
        return (int) ceil($chars * $factor);
    }
}
