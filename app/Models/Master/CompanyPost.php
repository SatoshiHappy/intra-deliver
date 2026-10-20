<?php

namespace App\Models\Master;

use Database\Factories\Master\CompanyPostFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 役職(期間化するマスタのアンカー)。表 company_posts(docs の 設計/共通マスタ/)。
 */
#[Fillable(['department_set_id', 'code', 'rank', 'sort_key'])]
class CompanyPost extends Model
{
    /** @use HasFactory<CompanyPostFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'sort_key' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DepartmentSet, $this>
     */
    public function departmentSet(): BelongsTo
    {
        return $this->belongsTo(DepartmentSet::class);
    }

    /**
     * @return HasMany<CompanyPostTerm, $this>
     */
    public function companyPostTerms(): HasMany
    {
        return $this->hasMany(CompanyPostTerm::class);
    }

    /**
     * @return HasMany<DepartmentMembershipPost, $this>
     */
    public function departmentMembershipPosts(): HasMany
    {
        return $this->hasMany(DepartmentMembershipPost::class);
    }

    /**
     * d に有効なもの(d に有効な期間の行があるもの。docs の 設計/共通マスタ/共通の規則.md 5.4)だけにする。
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function effectiveOn(Builder $query, DateTimeInterface $date): void
    {
        $query->whereHas('companyPostTerms', fn (Builder $terms) => $terms->effectiveOn($date));
    }
}
