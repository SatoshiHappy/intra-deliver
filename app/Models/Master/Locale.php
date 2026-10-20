<?php

namespace App\Models\Master;

use Database\Factories\Master\LocaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ロケール(期間なしのマスタ)。表 locales(docs の 設計/共通マスタ/)。
 */
#[Fillable(['code', 'sort_key'])]
class Locale extends Model
{
    /** @use HasFactory<LocaleFactory> */
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
     * @return HasMany<DepartmentTermTranslation, $this>
     */
    public function departmentTermTranslations(): HasMany
    {
        return $this->hasMany(DepartmentTermTranslation::class);
    }

    /**
     * @return HasMany<CompanyPostTermTranslation, $this>
     */
    public function companyPostTermTranslations(): HasMany
    {
        return $this->hasMany(CompanyPostTermTranslation::class);
    }

    /**
     * @return HasMany<UserTermTranslation, $this>
     */
    public function userTermTranslations(): HasMany
    {
        return $this->hasMany(UserTermTranslation::class);
    }
}
