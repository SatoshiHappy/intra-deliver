<?php

namespace App\Models\Master;

use Database\Factories\Master\CompanyPostTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 役職の期間の行。表 company_post_terms(docs の 設計/共通マスタ/)。
 */
#[Fillable(['company_post_id', 'start_date', 'end_date', 'is_deleted'])]
class CompanyPostTerm extends Model
{
    /** @use HasFactory<CompanyPostTermFactory> */
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
     * @return BelongsTo<CompanyPost, $this>
     */
    public function companyPost(): BelongsTo
    {
        return $this->belongsTo(CompanyPost::class);
    }

    /**
     * @return HasMany<CompanyPostTermTranslation, $this>
     */
    public function companyPostTermTranslations(): HasMany
    {
        return $this->hasMany(CompanyPostTermTranslation::class);
    }
}
