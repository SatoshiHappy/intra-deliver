<?php

namespace App\Models\Notice;

use App\Models\Master\User;
use Database\Factories\Notice\NoticeMuteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 利用者 1 人が通知の種類 1 つをミュートする印(行がある = ミュート中、無い = ミュートしない)。表 notice_mutes(docs の ADR 0050 の決定 1)。
 * ミュートしないに戻すと行を消す。
 */
#[Fillable(['user_id', 'type'])]
class NoticeMute extends Model
{
    /** @use HasFactory<NoticeMuteFactory> */
    use HasFactory;

    /**
     * ミュートしている利用者。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
