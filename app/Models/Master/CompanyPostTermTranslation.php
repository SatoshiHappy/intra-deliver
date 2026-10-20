<?php

namespace App\Models\Master;

use Database\Factories\Master\CompanyPostTermTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 役職の期間のロケール別の値。表 company_post_term_translations(docs の 設計/共通マスタ/)。
 */
#[Fillable(['company_post_term_id', 'locale_id', 'name', 'notes'])]
class CompanyPostTermTranslation extends Model
{
    /** @use HasFactory<CompanyPostTermTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<CompanyPostTerm, $this>
     */
    public function companyPostTerm(): BelongsTo
    {
        return $this->belongsTo(CompanyPostTerm::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}
