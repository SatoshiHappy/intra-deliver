<?php

namespace App\Models\Master;

use Database\Factories\Master\DepartmentSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 組織セット(期間なしのマスタ)。表 department_sets(docs の 設計/共通マスタ/)。
 */
#[Fillable(['company_id', 'code', 'is_default', 'sort_key'])]
class DepartmentSet extends Model
{
    /** @use HasFactory<DepartmentSetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_key' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * @return HasMany<CompanyPost, $this>
     */
    public function companyPosts(): HasMany
    {
        return $this->hasMany(CompanyPost::class);
    }
}
