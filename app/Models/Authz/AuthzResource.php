<?php

namespace App\Models\Authz;

use Database\Factories\Authz\AuthzResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認可のリソース(リソースグループと対になる)。表 authz_resources(docs の 設計/共通マスタ/)。
 */
#[Fillable(['authz_resource_group_id', 'resource_type', 'uri'])]
class AuthzResource extends Model
{
    /** @use HasFactory<AuthzResourceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AuthzResourceGroup, $this>
     */
    public function authzResourceGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzResourceGroup::class);
    }
}
