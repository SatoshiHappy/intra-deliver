<?php

namespace App\Models\Authz;

use App\Models\Master\Locale;
use Database\Factories\Authz\AuthzSubjectCategoryTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認可の対象者のカテゴリのロケール別の表示名。表 authz_subject_category_translations(docs の 設計/共通マスタ/)。
 */
#[Fillable(['authz_subject_category_id', 'locale_id', 'display_name'])]
class AuthzSubjectCategoryTranslation extends Model
{
    /** @use HasFactory<AuthzSubjectCategoryTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AuthzSubjectCategory, $this>
     */
    public function authzSubjectCategory(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectCategory::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}
