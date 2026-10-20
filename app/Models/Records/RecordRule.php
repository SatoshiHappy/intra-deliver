<?php

namespace App\Models\Records;

use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordRuleKind;
use Database\Factories\Records\RecordRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 規則(業務モジュールの部品。条件つき必須・相関チェック)。表 record_rules(docs の 設計/業務データの入れ物/データモデル/表.md 2.4、
 * 種別と条件の JSON は 項目と規則/規則と業務処理/README.md 8 節)。条件 condition は条件つき必須だけが持つ({"all": [式, …]})。
 */
#[Fillable(['record_module_id', 'kind', 'target_record_field_id', 'condition', 'operator', 'compared_record_field_id', 'message'])]
class RecordRule extends Model
{
    /** @use HasFactory<RecordRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RecordRuleKind::class,
            'condition' => 'array',
            'operator' => RecordComparisonOperator::class,
        ];
    }

    /**
     * @return BelongsTo<RecordModule, $this>
     */
    public function recordModule(): BelongsTo
    {
        return $this->belongsTo(RecordModule::class);
    }

    /**
     * 対象の項目。
     *
     * @return BelongsTo<RecordField, $this>
     */
    public function targetRecordField(): BelongsTo
    {
        return $this->belongsTo(RecordField::class, 'target_record_field_id');
    }

    /**
     * 相手の項目(相関チェックだけ。条件つき必須は null)。
     *
     * @return BelongsTo<RecordField, $this>
     */
    public function comparedRecordField(): BelongsTo
    {
        return $this->belongsTo(RecordField::class, 'compared_record_field_id');
    }
}
