<?php

namespace App\Models\Authz;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use Database\Factories\Authz\AuthzSubjectConditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認可の対象者の条件(連動する子。1 つの条件は 1 つの相手だけを指す)。表 authz_subject_conditions(docs の 設計/共通マスタ/)。
 * 相手 = サブジェクト(外部キーで指すユーザー・組織・役職・ロールと、外部キーで指さない IPv4 アドレスのパターン・認証状態・期間([term_start_date, term_end_date)))
 * か、子の対象者(式の入れ子)。is_negated = 除外(相手に合うかを反転する)。
 */
#[Fillable(['authz_subject_group_id', 'user_id', 'department_id', 'company_post_id', 'role_id', 'ipv4_pattern', 'meta_subject', 'term_start_date', 'term_end_date', 'child_authz_subject_group_id', 'operator', 'is_negated'])]
class AuthzSubjectCondition extends Model
{
    /** @use HasFactory<AuthzSubjectConditionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta_subject' => MetaSubject::class,
            'term_start_date' => 'date',
            'term_end_date' => 'date',
            'operator' => ConditionOperator::class,
            'is_negated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function authzSubjectGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class);
    }

    /**
     * 子の対象者(この条件の相手が子の対象者のとき)。
     *
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function childAuthzSubjectGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class, 'child_authz_subject_group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<CompanyPost, $this>
     */
    public function companyPost(): BelongsTo
    {
        return $this->belongsTo(CompanyPost::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
