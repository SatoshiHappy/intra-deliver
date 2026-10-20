<?php

namespace App\Models\Authz;

use App\Enums\Authz\ResourceGroupAttributeKey;
use Database\Factories\Authz\AuthzResourceGroupAttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認可のリソースグループの汎用属性(連動する子。1 つのリソースグループの 1 つのキーに値は 1 つ)。表 authz_resource_group_attributes(docs の 設計/共通マスタ/)。
 * キーは属性を使うモジュールが決める(Authz の閉塞は {@see ResourceGroupAttributeKey})。
 */
#[Fillable(['authz_resource_group_id', 'key', 'value'])]
class AuthzResourceGroupAttribute extends Model
{
    /** @use HasFactory<AuthzResourceGroupAttributeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AuthzResourceGroup, $this>
     */
    public function authzResourceGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzResourceGroup::class);
    }
}
