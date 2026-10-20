<?php

namespace App\Models\Authz;

use App\Enums\Authz\PolicyEffect;
use Database\Factories\Authz\AuthzPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認可のポリシー(リソースグループ × 対象者 × リソースタイプとアクションのエフェクト)。表 authz_policies(docs の 設計/共通マスタ/)。
 */
#[Fillable(['authz_resource_group_id', 'authz_subject_group_id', 'resource_type', 'action', 'effect'])]
class AuthzPolicy extends Model
{
    /** @use HasFactory<AuthzPolicyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effect' => PolicyEffect::class,
        ];
    }

    /**
     * @return BelongsTo<AuthzResourceGroup, $this>
     */
    public function authzResourceGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzResourceGroup::class);
    }

    /**
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function authzSubjectGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class);
    }
}
