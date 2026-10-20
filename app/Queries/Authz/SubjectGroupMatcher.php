<?php

namespace App\Queries\Authz;

use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 判定を求める者が認可の対象者に合うか(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_conditions の「除外と組み方」)。
 *
 * 1. 条件の値: 条件の相手(サブジェクトか子の対象者)に合うか(「相手に合う」。子の対象者は 2 の「式に合う」)を、除外(is_negated)なら反転したもの。
 * 2. 式に合う: all なら全条件の値が真、any なら少なくとも 1 つが真。条件が 0 件ならどちらでも合わない。
 * 3. 対象者に合う(ポリシーの対象者について判定が使う): (a) たどれる対象者(自分を含む)のどれも循環しておらず、条件が 0 件でない、
 *    (b) たどれる対象者の条件のうち子の対象者を指すものを除いた条件の、少なくとも 1 つの相手に合う(除外を掛ける前)、(c) 式に合う。
 *    (a) を先に確かめ、成り立たなければ (b)・(c) は求めない。(b) は 3 の対象者にだけ掛け、子の「式に合う」には掛けない。
 *
 * 1 回の判定の中だけで使う(式に合うかの結果を対象者ごとに覚えておき、同じ子を 2 つの道で指すときに重ねて評価しない)。
 */
final class SubjectGroupMatcher
{
    /** @var array<int, bool> 対象者 → 式に合うか */
    private array $expressionResults = [];

    /**
     * @param  Subject  $subject  判定を求める者の d の事実
     * @param  Collection<int, AuthzSubjectGroup>  $subjectGroups  判定する対象者と、そこから子の対象者をたどれるすべての対象者(id をキーに。条件と、d に有効な相手を読み込んだもの)
     */
    public function __construct(
        private readonly Subject $subject,
        private readonly Collection $subjectGroups,
    ) {}

    /**
     * 判定を求める者が対象者に合うか(上の 3「対象者に合う」)。
     */
    public function matches(AuthzSubjectGroup $subjectGroup): bool
    {
        $reachable = [];

        if (! $this->reachesWithoutCycleOrEmptyGroup($subjectGroup, [], $reachable)) {
            return false;
        }

        return $this->matchesSomeSubjectTarget($reachable) && $this->matchesExpression($subjectGroup);
    }

    /**
     * 上の (a): 対象者から子の対象者をたどり、循環(たどっている道の上の対象者に戻る)と条件が 0 件の対象者が無いか。
     * たどった対象者を reachable に集める。同じ子を 2 つの道で指すのは循環ではない(たどり終えた対象者は重ねてたどらない)。
     *
     * @param  array<int, true>  $path  いまたどっている道の上の対象者
     * @param  array<int, AuthzSubjectGroup>  $reachable  たどった対象者
     */
    private function reachesWithoutCycleOrEmptyGroup(AuthzSubjectGroup $subjectGroup, array $path, array &$reachable): bool
    {
        if (isset($path[$subjectGroup->id]) || $subjectGroup->authzSubjectConditions->isEmpty()) {
            return false;
        }

        if (isset($reachable[$subjectGroup->id])) {
            return true;
        }

        $reachable[$subjectGroup->id] = $subjectGroup;
        $path[$subjectGroup->id] = true;

        foreach ($this->childrenOf($subjectGroup) as $child) {
            if (! $this->reachesWithoutCycleOrEmptyGroup($child, $path, $reachable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 上の (b): たどった対象者の条件のうち、子の対象者を指さない条件の相手(サブジェクト)の少なくとも 1 つに合うか(除外を掛ける前)。
     *
     * @param  array<int, AuthzSubjectGroup>  $reachable
     */
    private function matchesSomeSubjectTarget(array $reachable): bool
    {
        foreach ($reachable as $subjectGroup) {
            $subjectConditions = $subjectGroup->authzSubjectConditions->whereNull('child_authz_subject_group_id');

            if ($subjectConditions->contains(fn (AuthzSubjectCondition $condition) => $this->subject->matchesTarget($condition))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 上の 2「式に合う」。(a) を確かめた対象者からたどれる対象者だけを評価するので、再帰は終わる。
     */
    private function matchesExpression(AuthzSubjectGroup $subjectGroup): bool
    {
        if (isset($this->expressionResults[$subjectGroup->id])) {
            return $this->expressionResults[$subjectGroup->id];
        }

        $conditions = $subjectGroup->authzSubjectConditions;
        $isValueTrue = fn (AuthzSubjectCondition $condition): bool => $this->matchesTarget($condition) !== $condition->is_negated;

        return $this->expressionResults[$subjectGroup->id] = $conditions->isNotEmpty() && match ($subjectGroup->combination) {
            SubjectCombination::All => $conditions->every($isValueTrue),
            SubjectCombination::Any => $conditions->contains($isValueTrue),
        };
    }

    /**
     * 「相手に合う」(除外を掛ける前)。子の対象者は「式に合う」、サブジェクトは {@see Subject::matchesTarget()}
     * (判定できない種類の条件は、反転の前にそこで例外になる)。
     */
    private function matchesTarget(AuthzSubjectCondition $condition): bool
    {
        if ($condition->child_authz_subject_group_id === null) {
            return $this->subject->matchesTarget($condition);
        }

        return $this->matchesExpression($this->subjectGroup($condition->child_authz_subject_group_id));
    }

    /**
     * @return list<AuthzSubjectGroup>
     */
    private function childrenOf(AuthzSubjectGroup $subjectGroup): array
    {
        $childIds = $subjectGroup->authzSubjectConditions->pluck('child_authz_subject_group_id')->filter()->all();

        return array_values(array_map(fn (int $childId) => $this->subjectGroup($childId), $childIds));
    }

    /**
     * 読み込んだ対象者。子の対象者は読み込む側({@see SubjectQuery})がすべて読み込むので、無ければ読み込みの誤り。
     */
    private function subjectGroup(int $subjectGroupId): AuthzSubjectGroup
    {
        return $this->subjectGroups->get($subjectGroupId)
            ?? throw new LogicException("子の対象者 {$subjectGroupId} を読み込んでいない");
    }
}
