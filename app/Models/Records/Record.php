<?php

namespace App\Models\Records;

use App\Models\Master\User;
use Database\Factories\Records\RecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * レコード(業務データの 1 行。親を持つ行は明細の行)。表 records(docs の 設計/業務データの入れ物/データモデル/表.md 2.5)。
 * 通常の項目の値 field_values は、項目の id をキーにした値の JSON のオブジェクト(設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節。{@see FieldValues})。
 * 参照の項目の値は record_references、明細は子のレコード。作成者・更新者が null の行はゲストが書いた。
 * チェック row_access_restricted と参照者 record_row_access_viewers は参照制限(ADR 0026。明細の行はどちらも持たない)。
 *
 * @phpstan-type Party array{type: 'user'|'department', id: int}
 */
#[Fillable(['record_module_id', 'parent_record_module_id', 'parent_record_id', 'sort_key', 'field_values', 'created_by_user_id', 'updated_by_user_id', 'row_access_restricted'])]
class Record extends Model
{
    /** @use HasFactory<RecordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
            'field_values' => FieldValues::class,
            'row_access_restricted' => 'boolean',
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
     * 親のレコード(明細の行のとき。親の無いレコードなら null)。
     *
     * @return BelongsTo<Record, $this>
     */
    public function parentRecord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_record_id');
    }

    /**
     * 明細の行。
     *
     * @return HasMany<Record, $this>
     */
    public function detailRecords(): HasMany
    {
        return $this->hasMany(self::class, 'parent_record_id');
    }

    /**
     * 参照の項目の値(このレコードが持つもの)。
     *
     * @return HasMany<RecordReference, $this>
     */
    public function recordReferences(): HasMany
    {
        return $this->hasMany(RecordReference::class);
    }

    /**
     * 参照者の行(sort_key の順)。読むのはチェックが付いているときだけ(付いていないレコードは参照者を持たない =
     * 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4 の「レコードの参照制限」)。
     *
     * @return HasMany<RecordRowAccessViewer, $this>
     */
    public function rowAccessViewers(): HasMany
    {
        return $this->hasMany(RecordRowAccessViewer::class)->orderBy('sort_key');
    }

    /**
     * 項目の参照の値の行(sort_key の順。無ければ空)。読み込んだ recordReferences から探す(複数でない項目は 1 行まで =
     * 設計/業務データの入れ物/データモデル/表.md 2.6)。
     *
     * @return list<RecordReference>
     */
    public function referencesFor(RecordField $field): array
    {
        return array_values($this->recordReferences
            ->filter(fn (RecordReference $reference) => $reference->record_field_id === $field->id)
            ->sortBy('sort_key')
            ->all());
    }

    /**
     * 項目の参照の値(相手の id。型「ユーザーと組織」は相手の {type, id}。複数選べる参照は sort_key の順の相手の配列。行が無ければ null)。
     * 業務処理の入力を正規化した値と同じ形(比べて変わったかを決める)。
     *
     * @return int|Party|list<int|Party>|null
     */
    public function referenceValue(RecordField $field): int|array|null
    {
        $targets = array_map(
            fn (RecordReference $reference) => $field->type->hasPartyKind()
                ? $reference->party()
                : $reference->targetId(),
            $this->referencesFor($field),
        );

        return match (true) {
            $targets === [] => null,
            $field->is_multiple => $targets,
            default => $targets[0],
        };
    }

    /**
     * 版(親のレコードの updated_at。更新の保存のたびに進む)。編集の画面に渡す版と、更新の業務処理が比べる版の形の 1 か所
     * (項目と規則/規則と業務処理/README.md 9.2 の 2)。updated_at が無い行は空文字。
     */
    public function version(): string
    {
        return $this->updated_at?->toIso8601String() ?? '';
    }

    /**
     * 作成者(ゲストが作ったなら null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * 更新者(ゲストが最後に変えたなら null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
