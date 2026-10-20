<?php

namespace App\Models\Master;

use Database\Factories\Master\DepartmentMembershipFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 組織所属(期間化する関係。主所属を含む)。表 department_memberships(docs の 設計/共通マスタ/)。
 */
#[Fillable(['department_set_id', 'department_id', 'user_id', 'start_date', 'end_date', 'is_deleted', 'is_main', 'sort_key'])]
class DepartmentMembership extends Model
{
    /** @use HasFactory<DepartmentMembershipFactory> */
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
            'is_main' => 'boolean',
            'sort_key' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DepartmentMembershipPost, $this>
     */
    public function departmentMembershipPosts(): HasMany
    {
        return $this->hasMany(DepartmentMembershipPost::class);
    }

    /**
     * 所属が d に成り立つもの(行が d に有効で、組織とユーザーも d に有効。docs の 設計/共通マスタ/共通の規則.md 5.4)だけにする。
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function holdsOn(Builder $query, DateTimeInterface $date): void
    {
        $query->effectiveOn($date)
            ->whereHas('department', fn (Builder $department) => $department->effectiveOn($date))
            ->whereHas('user', fn (Builder $user) => $user->effectiveOn($date));
    }
}
