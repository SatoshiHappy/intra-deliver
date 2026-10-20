<?php

namespace App\Models\Authz;

use Database\Factories\Authz\AuthzSubjectCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 認可の対象者のカテゴリ(期間なしのマスタ。コードは対象者の条件のサブジェクトタイプから決まる)。表 authz_subject_categories(docs の 設計/共通マスタ/)。
 * 対象者のカテゴリは表に持たず、対象者の条件から決まるコードでこの行を引く。
 */
#[Fillable(['code', 'sort_key'])]
class AuthzSubjectCategory extends Model
{
    /** @use HasFactory<AuthzSubjectCategoryFactory> */
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
     * @return HasMany<AuthzSubjectCategoryTranslation, $this>
     */
    public function authzSubjectCategoryTranslations(): HasMany
    {
        return $this->hasMany(AuthzSubjectCategoryTranslation::class);
    }
}
