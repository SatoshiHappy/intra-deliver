<?php

namespace App\Models\Master;

use Database\Factories\Master\DepartmentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 組織(期間化するマスタのアンカー)。表 departments(docs の 設計/共通マスタ/)。
 */
#[Fillable(['department_set_id', 'code', 'is_root', 'sort_key'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_root' => 'boolean',
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
     * @return HasMany<DepartmentTerm, $this>
     */
    public function departmentTerms(): HasMany
    {
        return $this->hasMany(DepartmentTerm::class);
    }

    /**
     * @return HasMany<DepartmentInclusion, $this>
     */
    public function inclusionsAsParent(): HasMany
    {
        return $this->hasMany(DepartmentInclusion::class, 'parent_department_id');
    }

    /**
     * @return HasMany<DepartmentInclusion, $this>
     */
    public function inclusionsAsChild(): HasMany
    {
        return $this->hasMany(DepartmentInclusion::class, 'department_id');
    }

    /**
     * @return HasMany<DepartmentMembership, $this>
     */
    public function departmentMemberships(): HasMany
    {
        return $this->hasMany(DepartmentMembership::class);
    }

    /**
     * d に有効なもの(d に有効な期間の行があるもの。docs の 設計/共通マスタ/共通の規則.md 5.4)だけにする。
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function effectiveOn(Builder $query, DateTimeInterface $date): void
    {
        $query->whereHas('departmentTerms', fn (Builder $terms) => $terms->effectiveOn($date));
    }
}
