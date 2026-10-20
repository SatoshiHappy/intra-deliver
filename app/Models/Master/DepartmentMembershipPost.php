<?php

namespace App\Models\Master;

use Database\Factories\Master\DepartmentMembershipPostFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 組織所属役職(連動する子。所属の期間と無効の旗に従う)。表 department_membership_posts(docs の 設計/共通マスタ/)。
 */
#[Fillable(['department_set_id', 'department_membership_id', 'company_post_id'])]
class DepartmentMembershipPost extends Model
{
    /** @use HasFactory<DepartmentMembershipPostFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<DepartmentMembership, $this>
     */
    public function departmentMembership(): BelongsTo
    {
        return $this->belongsTo(DepartmentMembership::class);
    }

    /**
     * @return BelongsTo<CompanyPost, $this>
     */
    public function companyPost(): BelongsTo
    {
        return $this->belongsTo(CompanyPost::class);
    }

    /**
     * 所属役職が d に成り立つもの(親の所属が d に成り立ち、役職も d に有効。docs の 設計/共通マスタ/共通の規則.md 5.4)だけにする。
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function holdsOn(Builder $query, DateTimeInterface $date): void
    {
        $query->whereHas('departmentMembership', fn (Builder $membership) => $membership->holdsOn($date))
            ->whereHas('companyPost', fn (Builder $companyPost) => $companyPost->effectiveOn($date));
    }
}
