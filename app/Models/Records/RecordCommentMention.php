<?php

namespace App\Models\Records;

use App\Models\Master\User;
use Database\Factories\Records\RecordCommentMentionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * コメントの宛先(コメントの部品)。表 record_comment_mentions(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.5 節)。
 */
#[Fillable(['record_comment_id', 'user_id'])]
class RecordCommentMention extends Model
{
    /** @use HasFactory<RecordCommentMentionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<RecordComment, $this>
     */
    public function recordComment(): BelongsTo
    {
        return $this->belongsTo(RecordComment::class);
    }

    /**
     * 宛先のユーザー。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
