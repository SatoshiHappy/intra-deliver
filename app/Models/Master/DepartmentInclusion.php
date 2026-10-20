<?php

namespace App\Models\Master;

use Database\Factories\Master\DepartmentInclusionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 内包 = 組織の 1 段の親子(期間化する関係)。表 department_inclusions(docs の 設計/共通マスタ/)。
 */
#[Fillable(['department_set_id', 'parent_department_id', 'department_id', 'start_date', 'end_date', 'is_deleted', 'sort_key'])]
class DepartmentInclusion extends Model
{
    /** @use HasFactory<DepartmentInclusionFactory> */
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
            'sort_key' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function parentDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * 内包が d に成り立つもの(行が d に有効で、親と子の組織も d に有効。docs の 設計/共通マスタ/共通の規則.md 5.4)だけにする。
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function holdsOn(Builder $query, DateTimeInterface $date): void
    {
        $query->effectiveOn($date)
            ->whereHas('parentDepartment', fn (Builder $department) => $department->effectiveOn($date))
            ->whereHas('department', fn (Builder $department) => $department->effectiveOn($date));
    }
}
