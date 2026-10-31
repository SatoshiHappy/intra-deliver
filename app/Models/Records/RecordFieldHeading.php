<?php

namespace App\Models\Records;

use Database\Factories\Records\RecordFieldHeadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 項目の見出し(業務モジュールの部品)。表 record_field_headings(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.6 節)。
 */
#[Fillable(['record_module_id', 'name', 'sort_key'])]
class RecordFieldHeading extends Model
{
    /**
     * 最初の見出しより前の項目に画面で付ける固定の見出しの名前(データに持たない。画面側の正本は resources/js/lib/recordHeadingGroups.ts の HEAD_GROUP_NAME で、
     * 同じ文字列。見出しの名前の重なりを拒むために、サーバーはこの名前も使われているものとして数える)。
     */
    public const HEAD_GROUP_NAME = '基本情報';

    /** @use HasFactory<RecordFieldHeadingFactory> */
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
     * @return BelongsTo<RecordModule, $this>
     */
    public function recordModule(): BelongsTo
    {
        return $this->belongsTo(RecordModule::class);
    }
}
