<?php

namespace App\Models\Notice;

use App\Models\Master\User;
use Database\Factories\Notice\NoticeOptOutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 利用者 1 人が通知の種類 1 つを受け取らない印(行がある = 受け取らない、無い = 受け取る)。表 notice_opt_outs(docs の ADR 0045 の決定 1)。
 * 受け取るに戻すと行を消す。
 */
#[Fillable(['user_id', 'type'])]
class NoticeOptOut extends Model
{
    /** @use HasFactory<NoticeOptOutFactory> */
    use HasFactory;

    /**
     * 受け取らない利用者。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
