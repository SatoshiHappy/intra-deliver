<?php

namespace App\Models\Authz;

use App\Models\Master\HasPeriod;
use App\Models\Master\User;
use Database\Factories\Authz\UserRoleFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ロールの付与(期間化する関係)。表 user_roles(docs の 設計/共通マスタ/)。
 */
#[Fillable(['user_id', 'role_id', 'start_date', 'end_date', 'is_deleted'])]
class UserRole extends Model
{
    /** @use HasFactory<UserRoleFactory> */
    use HasFactory;

    use HasPeriod;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_deleted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * 付与が d に成り立つもの(行が d に有効で、ユーザーも d に有効。ロールは期間を持たないので常に有効。docs の 設計/共通マスタ/共通の規則.md 5.4)だけにする。
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function holdsOn(Builder $query, DateTimeInterface $date): void
    {
        $query->effectiveOn($date)
            ->whereHas('user', fn (Builder $user) => $user->effectiveOn($date));
    }
}
