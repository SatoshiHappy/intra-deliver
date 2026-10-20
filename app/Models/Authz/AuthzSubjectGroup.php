<?php

namespace App\Models\Authz;

use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use Database\Factories\Authz\AuthzSubjectGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 認可の対象者(条件の組。条件は子の対象者を指して式を入れ子にできる)。表 authz_subject_groups(docs の 設計/共通マスタ/)。
 * 用途 purpose は {@see SubjectGroupPurpose}(既定 policy)。
 */
#[Fillable(['combination', 'sort_key', 'purpose'])]
class AuthzSubjectGroup extends Model
{
    /** @use HasFactory<AuthzSubjectGroupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'combination' => SubjectCombination::class,
            'sort_key' => 'integer',
            'purpose' => SubjectGroupPurpose::class,
        ];
    }

    /**
     * @return HasMany<AuthzSubjectCondition, $this>
     */
    public function authzSubjectConditions(): HasMany
    {
        return $this->hasMany(AuthzSubjectCondition::class);
    }

    /**
     * この対象者を子の対象者として指す条件(親の対象者の条件)。
     *
     * @return HasMany<AuthzSubjectCondition, $this>
     */
    public function authzSubjectConditionsAsChild(): HasMany
    {
        return $this->hasMany(AuthzSubjectCondition::class, 'child_authz_subject_group_id');
    }

    /**
     * @return HasMany<AuthzSubjectGroupTranslation, $this>
     */
    public function authzSubjectGroupTranslations(): HasMany
    {
        return $this->hasMany(AuthzSubjectGroupTranslation::class);
    }

    /**
     * @return HasMany<AuthzPolicy, $this>
     */
    public function authzPolicies(): HasMany
    {
        return $this->hasMany(AuthzPolicy::class);
    }
}
