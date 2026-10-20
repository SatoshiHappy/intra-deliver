<?php

namespace App\Models\Master;

use Database\Factories\Master\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 会社(期間なしのマスタ。名称は既定の組織セットの頂点の組織が持つ)。表 companies(docs の 設計/共通マスタ/)。
 */
#[Fillable(['code', 'sort_key'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
        ];
    }

    /**
     * @return HasMany<DepartmentSet, $this>
     */
    public function departmentSets(): HasMany
    {
        return $this->hasMany(DepartmentSet::class);
    }
}
