<?php

namespace App\Models\Records;

use App\Enums\Records\RecordFieldType;
use Database\Factories\Records\RecordFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 項目(業務モジュールの部品)。表 record_fields(docs の 設計/業務データの入れ物/データモデル/表.md 2.2、型と属性は 項目と規則/README.md 1 節)。
 * 既定値 default_value は値の JSON の形(設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節)の 1 つの値で、読むと文字列・数・真偽になる
 * (日付・日時の相対の既定値は {@see RelativeDefault}。NULL = 既定値なし)。
 */
#[Fillable(['record_module_id', 'code', 'name', 'type', 'is_required', 'is_multiline', 'max_length', 'default_value', 'referenced_record_module_id', 'child_record_module_id', 'is_listed', 'is_searchable', 'is_title', 'is_multiple', 'grants_row_access', 'sort_key'])]
class RecordField extends Model
{
    /** @use HasFactory<RecordFieldFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RecordFieldType::class,
            'is_required' => 'boolean',
            'is_multiline' => 'boolean',
            'max_length' => 'integer',
            'default_value' => FieldDefaultValue::class,
            'is_listed' => 'boolean',
            'is_searchable' => 'boolean',
            'is_title' => 'boolean',
            'is_multiple' => 'boolean',
            'grants_row_access' => 'boolean',
            'sort_key' => 'integer',
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
     * 参照先の業務モジュール(型「他モジュール参照」だけ。ほかの型は null)。
     *
     * @return BelongsTo<RecordModule, $this>
     */
    public function referencedRecordModule(): BelongsTo
    {
        return $this->belongsTo(RecordModule::class, 'referenced_record_module_id');
    }

    /**
     * 明細のモジュール(型「明細」だけ。ほかの型は null)。
     *
     * @return BelongsTo<RecordModule, $this>
     */
    public function childRecordModule(): BelongsTo
    {
        return $this->belongsTo(RecordModule::class, 'child_record_module_id');
    }

    /**
     * 選択肢(型「選択」だけが持つ)。
     *
     * @return HasMany<RecordFieldOption, $this>
     */
    public function recordFieldOptions(): HasMany
    {
        return $this->hasMany(RecordFieldOption::class);
    }
}
