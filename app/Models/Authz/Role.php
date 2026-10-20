<?php

namespace App\Models\Authz;

use Database\Factories\Authz\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ロール(期間なしのマスタ)。表 roles(docs の 設計/共通マスタ/)。
 */
#[Fillable(['code', 'name', 'category', 'notes', 'sort_key'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
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
     * @return HasMany<RoleTranslation, $this>
     */
    public function roleTranslations(): HasMany
    {
        return $this->hasMany(RoleTranslation::class);
    }

    /**
     * @return HasMany<RoleInclusion, $this>
     */
    public function inclusionsAsParent(): HasMany
    {
        return $this->hasMany(RoleInclusion::class, 'parent_role_id');
    }

    /**
     * @return HasMany<RoleInclusion, $this>
     */
    public function inclusionsAsChild(): HasMany
    {
        return $this->hasMany(RoleInclusion::class, 'child_role_id');
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * @return HasMany<AuthzSubjectCondition, $this>
     */
    public function authzSubjectConditions(): HasMany
    {
        return $this->hasMany(AuthzSubjectCondition::class);
    }
}
