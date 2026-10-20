<?php

namespace App\Models\Notice;

use App\Models\Master\User;
use Database\Factories\Notice\NoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 通知(宛先 1 人 1 行。作る・既読にするだけで消さない)。表 notices(docs の 設計/ワークフロー/案件の運用/通知.md の 2 節)。
 * 対象(target_type・target_id)は外部キーの無い参照で、移り先は表示の時に解く(同じ文書の 5 節)。
 */
#[Fillable(['user_id', 'type', 'target_type', 'target_id', 'actor_user_id', 'params', 'read_at'])]
class Notice extends Model
{
    /** @use HasFactory<NoticeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'params' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * 宛先。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 起こした人(人によらない通知では null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
