<?php

namespace App\Models\Authz;

use App\Models\Master\Locale;
use Database\Factories\Authz\AuthzResourceGroupTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認可のリソースグループのロケール別の表示名・説明。表 authz_resource_group_translations(docs の 設計/共通マスタ/)。
 */
#[Fillable(['authz_resource_group_id', 'locale_id', 'display_name', 'description'])]
class AuthzResourceGroupTranslation extends Model
{
    /** @use HasFactory<AuthzResourceGroupTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AuthzResourceGroup, $this>
     */
    public function authzResourceGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzResourceGroup::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}
