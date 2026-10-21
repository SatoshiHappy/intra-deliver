<?php

namespace App\Models\Records;

use App\Enums\Records\RowAccessScope;
use Database\Factories\Records\RecordModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 業務モジュール(業務データの表 1 つの定義。期間なしのマスタ)。表 record_modules(docs の 設計/業務データの入れ物/データモデル/表.md 2.1、
 * 添付ファイルの使用の列 = 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.8 節)。
 * 見える範囲 row_access_scope = 認可とメニュー/README.md 2.4。
 * 組み込みの旗 is_builtin = 設計/業務ツール/共通データ.md の「入れ物の拡張」(入力から立てないので fillable に入れない)。
 */
#[Fillable([
    'code', 'name', 'description', 'icon', 'is_attachment_enabled', 'parent_record_module_id', 'sort_key',
    'row_access_scope',
])]
class RecordModule extends Model
{
    /** @use HasFactory<RecordModuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_attachment_enabled' => 'boolean',
            'sort_key' => 'integer',
            'row_access_scope' => RowAccessScope::class,
            'is_builtin' => 'boolean',
        ];
    }

    /**
     * 親の業務モジュール(明細のモジュールのとき。親の無い業務モジュールなら null)。
     *
     * @return BelongsTo<RecordModule, $this>
     */
    public function parentRecordModule(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_record_module_id');
    }

    /**
     * 明細のモジュール。
     *
     * @return HasMany<RecordModule, $this>
     */
    public function childRecordModules(): HasMany
    {
        return $this->hasMany(self::class, 'parent_record_module_id');
    }

    /**
     * @return HasMany<RecordField, $this>
     */
    public function recordFields(): HasMany
    {
        return $this->hasMany(RecordField::class);
    }

    /**
     * @return HasMany<RecordRule, $this>
     */
    public function recordRules(): HasMany
    {
        return $this->hasMany(RecordRule::class);
    }

    /**
     * @return HasMany<Record, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
