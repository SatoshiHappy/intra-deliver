<?php

namespace App\Models\Records;

use Database\Factories\Records\RecordFieldOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 選択肢(項目の部品)。表 record_field_options(docs の 設計/業務データの入れ物/データモデル/表.md 2.3)。コードがレコードの値に入る。
 * 組み込みの旗 is_builtin = 設計/業務ツール/共通データ.md の「入れ物の拡張」(入力から立てないので fillable に入れない)。
 */
#[Fillable(['record_field_id', 'code', 'name', 'sort_key'])]
class RecordFieldOption extends Model
{
    /** @use HasFactory<RecordFieldOptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
            'is_builtin' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RecordField, $this>
     */
    public function recordField(): BelongsTo
    {
        return $this->belongsTo(RecordField::class);
    }
}
