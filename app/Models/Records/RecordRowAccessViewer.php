<?php

namespace App\Models\Records;

use App\Models\Master\Department;
use App\Models\Master\User;
use Database\Factories\Records\RecordRowAccessViewerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

/**
 * 参照者(参照を制限したレコードの参照者 1 つ = ユーザーか組織)。表 record_row_access_viewers(docs の
 * 設計/業務データの入れ物/データモデル/表.md 2.8、ADR 0026)。並びは sort_key(入力の配列の順。0 からの添字)。
 */
#[Fillable(['record_id', 'user_id', 'department_id', 'sort_key'])]
class RecordRowAccessViewer extends Model
{
    /** @use HasFactory<RecordRowAccessViewerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'record_id' => 'integer',
            'user_id' => 'integer',
            'department_id' => 'integer',
            'sort_key' => 'integer',
        ];
    }

    /**
     * 相手(種類と id。キーの順 type・id = App\Queries\Records\RecordParty の形)。
     *
     * @return array{type: 'user'|'department', id: int}
     *
     * @throws UnexpectedValueException 相手を持たない行のとき(起きない。DB の検査制約が止める)
     */
    public function party(): array
    {
        return match (true) {
            $this->user_id !== null => ['type' => 'user', 'id' => $this->user_id],
            $this->department_id !== null => ['type' => 'department', 'id' => $this->department_id],
            default => throw new UnexpectedValueException("参照者の行が相手を持たない(DB の検査制約が止める): {$this->id}"),
        };
    }

    /**
     * @return BelongsTo<Record, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
