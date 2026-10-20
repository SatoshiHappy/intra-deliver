<?php

namespace App\Models\Authz;

use Database\Factories\Authz\AuthzResourceGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 認可のリソースグループ(期間なしのマスタ。階層)。表 authz_resource_groups(docs の 設計/共通マスタ/)。
 */
#[Fillable(['code', 'parent_id', 'sort_key'])]
class AuthzResourceGroup extends Model
{
    /** @use HasFactory<AuthzResourceGroupFactory> */
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
     * @return BelongsTo<AuthzResourceGroup, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    /**
     * @return HasMany<AuthzResourceGroup, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AuthzResourceGroupTranslation, $this>
     */
    public function authzResourceGroupTranslations(): HasMany
    {
        return $this->hasMany(AuthzResourceGroupTranslation::class);
    }

    /**
     * @return HasMany<AuthzResourceGroupAttribute, $this>
     */
    public function authzResourceGroupAttributes(): HasMany
    {
        return $this->hasMany(AuthzResourceGroupAttribute::class);
    }

    /**
     * @return HasOne<AuthzResource, $this>
     */
    public function authzResource(): HasOne
    {
        return $this->hasOne(AuthzResource::class);
    }

    /**
     * @return HasMany<AuthzPolicy, $this>
     */
    public function authzPolicies(): HasMany
    {
        return $this->hasMany(AuthzPolicy::class);
    }
}
