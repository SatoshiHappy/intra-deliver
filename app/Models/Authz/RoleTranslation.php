<?php

namespace App\Models\Authz;

use App\Models\Master\Locale;
use Database\Factories\Authz\RoleTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ロールのロケール別の表示名。表 role_translations(docs の 設計/共通マスタ/)。
 */
#[Fillable(['role_id', 'locale_id', 'display_name'])]
class RoleTranslation extends Model
{
    /** @use HasFactory<RoleTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}
