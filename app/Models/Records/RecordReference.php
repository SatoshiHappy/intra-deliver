<?php

namespace App\Models\Records;

use App\Models\Master\Department;
use App\Models\Master\User;
use Database\Factories\Records\RecordReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

/**
 * 参照の項目の値(レコードの部品)。表 record_references(docs の 設計/業務データの入れ物/データモデル/表.md 2.6)。
 * 1 行は 1 つの相手(レコード・ユーザー・組織のどれか)だけを指す。未設定は行が無いことで表す。複数選べる参照は相手ごとに 1 行で、並びは sort_key。
 */
#[Fillable(['record_module_id', 'record_id', 'record_field_id', 'referenced_record_module_id', 'referenced_record_id', 'user_id', 'department_id', 'sort_key', 'is_multiple'])]
class RecordReference extends Model
{
    /** @use HasFactory<RecordReferenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
            'is_multiple' => 'boolean',
        ];
    }

    /**
     * 指す相手の id(ユーザー・組織・レコードのどれか。1 行は 1 つの相手だけを指す)。
     *
     * @throws UnexpectedValueException 相手を持たない行のとき(DB の検査制約が止めるので起きない)
     */
    public function targetId(): int
    {
        return $this->user_id ?? $this->department_id ?? $this->referenced_record_id
            ?? throw new UnexpectedValueException("参照の行が相手を持たない: {$this->id}");
    }

    /**
     * 型「ユーザーと組織」の項目の値の相手(種類と id。キーの順 type・id = App\Queries\Records\RecordParty の形)。
     *
     * @return array{type: 'user'|'department', id: int}
     *
     * @throws UnexpectedValueException ユーザーも組織も持たない行のとき(業務処理が書かない = データモデル/表.md 2.6 の業務処理で止める規則)
     */
    public function party(): array
    {
        return match (true) {
            $this->user_id !== null => ['type' => 'user', 'id' => $this->user_id],
            $this->department_id !== null => ['type' => 'department', 'id' => $this->department_id],
            default => throw new UnexpectedValueException("ユーザーと組織の参照の行がユーザーも組織も持たない: {$this->id}"),
        };
    }

    /**
     * 持ち主のレコード。
     *
     * @return BelongsTo<Record, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    /**
     * 参照の項目。
     *
     * @return BelongsTo<RecordField, $this>
     */
    public function recordField(): BelongsTo
    {
        return $this->belongsTo(RecordField::class);
    }

    /**
     * 参照先のレコード(他モジュール参照のとき。ほかは null)。
     *
     * @return BelongsTo<Record, $this>
     */
    public function referencedRecord(): BelongsTo
    {
        return $this->belongsTo(Record::class, 'referenced_record_id');
    }

    /**
     * 参照先のユーザー(ユーザー参照のとき。ほかは null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 参照先の組織(組織参照のとき。ほかは null)。
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
