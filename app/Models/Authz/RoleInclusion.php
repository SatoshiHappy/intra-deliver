<?php

namespace App\Models\Authz;

use Database\Factories\Authz\RoleInclusionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * サブロール(期間なしの関係。親ロールを持つ人は子ロールも持つ)。表 role_inclusions(docs の 設計/共通マスタ/)。
 */
#[Fillable(['parent_role_id', 'child_role_id'])]
class RoleInclusion extends Model
{
    /** @use HasFactory<RoleInclusionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Role, $this>
     */
    public function parentRole(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function childRole(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
