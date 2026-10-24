<?php

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * AI の費用の繰り越しの表 ai_spending_carryovers(docs の 設計/AI/実行と費用.md 5.2)。
 * Eloquent は複数の列の主キーを扱えないので、行はモデルの保存でなく繰り越しの業務処理の SQL の文で書く。モデルは読むために使う。
 */
#[Fillable(['budget', 'spent_on', 'cost_usd'])]
class AiSpendingCarryover extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'cost_usd' => 'decimal:6',
        ];
    }
}
