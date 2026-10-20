<?php

namespace App\Models\Authz;

use App\Models\Master\Locale;
use Database\Factories\Authz\AuthzSubjectGroupTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認可の対象者のロケール別の表示名・説明(本家の条件の名称)。表 authz_subject_group_translations(docs の 設計/共通マスタ/)。
 */
#[Fillable(['authz_subject_group_id', 'locale_id', 'display_name', 'description'])]
class AuthzSubjectGroupTranslation extends Model
{
    /** @use HasFactory<AuthzSubjectGroupTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function authzSubjectGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}
