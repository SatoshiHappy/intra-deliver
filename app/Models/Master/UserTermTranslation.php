<?php

namespace App\Models\Master;

use Database\Factories\Master\UserTermTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ユーザーの期間のロケール別の値。表 user_term_translations(docs の 設計/共通マスタ/)。
 */
#[Fillable(['user_term_id', 'locale_id', 'name', 'search_name', 'country_code', 'zip_code', 'address1', 'address2', 'address3', 'telephone_number', 'extension_number', 'fax_number', 'extension_fax_number', 'mobile_number', 'email_address1', 'email_address2', 'mobile_email_address', 'url', 'notes'])]
class UserTermTranslation extends Model
{
    /** @use HasFactory<UserTermTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<UserTerm, $this>
     */
    public function userTerm(): BelongsTo
    {
        return $this->belongsTo(UserTerm::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}
