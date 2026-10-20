<?php

namespace App\Models\Records;

use App\Enums\Records\RecordRevisionAction;
use App\Models\Master\User;
use Database\Factories\Records\RecordRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 更新履歴の版(レコードの部品。追記だけ)。表 record_revisions(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1 節)。
 */
#[Fillable([
    'record_module_id', 'record_id', 'revision', 'action', 'snapshot', 'labels',
    'user_id', 'principal_user_id', 'origin_operation', 'origin_node_name',
])]
class RecordRevision extends Model
{
    /** @use HasFactory<RecordRevisionFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'action' => RecordRevisionAction::class,
            'snapshot' => RevisionSnapshot::class,
            'labels' => RevisionLabels::class,
        ];
    }

    /**
     * 版を持つレコード。
     *
     * @return BelongsTo<Record, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    /**
     * 操作した人(ゲストなら null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 代理元(代理で処理していなければ null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function principalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_user_id');
    }
}
