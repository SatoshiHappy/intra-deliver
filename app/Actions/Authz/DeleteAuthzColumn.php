<?php

namespace App\Actions\Authz;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\AuthzEditGuard;
use App\Queries\Authz\BuiltinSubjects;
use App\Queries\Authz\SubjectGroupPolicyFingerprints;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 認可設定の列(対象者)を消す(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.2)。
 * 確かめのダイアログが見た件数・指紋({@see SubjectGroupPolicyFingerprints})と食い違えば止め、守り({@see AuthzEditGuard})が外れれば全体を巻き戻す。
 * 組み込みの列({@see BuiltinSubjects})は消さない。
 */
class DeleteAuthzColumn
{
    use CommonMasterWriteLock;

    /** 件数か指紋が確かめのダイアログの見たものと違うときの文 */
    public const STALE_MESSAGE = 'この列の設定がほかの利用者に変更されました。件数を確かめ直してください。';

    public function __construct(
        private BuiltinSubjects $builtinSubjects,
        private SubjectGroupPolicyFingerprints $fingerprints,
        private AuthzEditGuard $guard,
    ) {}

    /**
     * @param  int  $policyCount  確かめのダイアログが見た件数
     * @param  string  $fingerprint  確かめのダイアログが見た指紋
     *
     * @throws ModelNotFoundException<AuthzSubjectGroup> 対象者が無い・用途 selection(列ではない)のとき
     * @throws ValidationException 消せない列のとき、件数か指紋が違うとき、守りに外れたとき(どれも subject)
     */
    public function execute(AccessContext $context, int $subjectGroupId, int $policyCount, string $fingerprint): void
    {
        $this->writeCommonMaster(function () use ($context, $subjectGroupId, $policyCount, $fingerprint) {
            $subjectGroup = AuthzSubjectGroup::query()
                ->whereKey($subjectGroupId)
                ->where('purpose', SubjectGroupPurpose::Policy)
                ->firstOrFail();
            $this->assertDeletable($subjectGroupId);
            $current = $this->fingerprints->of([$subjectGroupId])[$subjectGroupId];

            if ($current['policyCount'] !== $policyCount || ! hash_equals($current['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['subject' => [self::STALE_MESSAGE]]);
            }

            AuthzPolicy::query()->where('authz_subject_group_id', $subjectGroupId)->delete();
            $subjectGroup->delete();
            $this->guard->assertKept($context, 'subject');
        });
    }

    /**
     * @throws ValidationException 組み込みの列のとき、ほかの対象者の子に使われているとき
     */
    private function assertDeletable(int $subjectGroupId): void
    {
        if (in_array($subjectGroupId, $this->builtinSubjects->ids(), true)) {
            throw ValidationException::withMessages(['subject' => ['この列は削除できません。']]);
        }

        if (AuthzSubjectCondition::query()->where('child_authz_subject_group_id', $subjectGroupId)->exists()) {
            throw ValidationException::withMessages(['subject' => ['ほかの対象者の条件に使われているため、削除できません。']]);
        }
    }
}
