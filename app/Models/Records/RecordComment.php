<?php

namespace App\Models\Records;

use App\Models\Master\User;
use Database\Factories\Records\RecordCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * コメント(レコードの部品)。表 record_comments(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.4 節)。
 * 削除の印は隠す旗でなく画面に出す記録なので、SoftDeletes の trait を使わない(設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 1 節)。
 */
#[Fillable(['record_module_id', 'record_id', 'user_id', 'body', 'deleted_at', 'deleted_by_user_id'])]
class RecordComment extends Model
{
    /** @use HasFactory<RecordCommentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * コメントを付けたレコード。
     *
     * @return BelongsTo<Record, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    /**
     * 書いた人。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 削除した人(削除していなければ null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    /**
     * コメントの宛先。
     *
     * @return HasMany<RecordCommentMention, $this>
     */
    public function recordCommentMentions(): HasMany
    {
        return $this->hasMany(RecordCommentMention::class);
    }
}
