<?php

namespace App\Models\Master;

use Database\Factories\Master\DepartmentTermTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 組織の期間のロケール別の値。表 department_term_translations(docs の 設計/共通マスタ/)。
 */
#[Fillable(['department_term_id', 'locale_id', 'name', 'short_name', 'search_name', 'country_code', 'zip_code', 'address1', 'address2', 'address3', 'telephone_number', 'extension_number', 'fax_number', 'extension_fax_number', 'email_address1', 'email_address2', 'url', 'notes'])]
class DepartmentTermTranslation extends Model
{
    /** @use HasFactory<DepartmentTermTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<DepartmentTerm, $this>
     */
    public function departmentTerm(): BelongsTo
    {
        return $this->belongsTo(DepartmentTerm::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}
