<?php

namespace App\Models\Records;

use Database\Factories\Records\RecordRelatedColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 関連レコードの列(項目の部品)。表 record_related_columns(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.7 節)。
 */
#[Fillable(['record_field_id', 'record_module_id', 'column_record_field_id', 'sort_key'])]
class RecordRelatedColumn extends Model
{
    /** @use HasFactory<RecordRelatedColumnFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
        ];
    }

    /**
     * 入口の参照の項目。
     *
     * @return BelongsTo<RecordField, $this>
     */
    public function recordField(): BelongsTo
    {
        return $this->belongsTo(RecordField::class);
    }

    /**
     * 列に出す項目。
     *
     * @return BelongsTo<RecordField, $this>
     */
    public function columnRecordField(): BelongsTo
    {
        return $this->belongsTo(RecordField::class, 'column_record_field_id');
    }
}
