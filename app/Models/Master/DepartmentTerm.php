<?php

namespace App\Models\Master;

use Database\Factories\Master\DepartmentTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 組織の期間の行。表 department_terms(docs の 設計/共通マスタ/)。
 */
#[Fillable(['department_id', 'start_date', 'end_date', 'is_deleted'])]
class DepartmentTerm extends Model
{
    /** @use HasFactory<DepartmentTermFactory> */
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
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<DepartmentTermTranslation, $this>
     */
    public function departmentTermTranslations(): HasMany
    {
        return $this->hasMany(DepartmentTermTranslation::class);
    }
}
