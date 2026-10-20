<?php

namespace App\Queries\Authz;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Master\CompanyPost;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use UnexpectedValueException;

/**
 * 判定を求める者の d の事実(d に有効なユーザーと、そのカレント組織の所属の組織・その上位と下位・所属役職、持つロール、接続元の IPv4 アドレス)と、
 * 認可の対象者の条件の相手(サブジェクト)に合うかの判定(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_conditions の「相手に合う」)。
 * 除外・式・子の対象者の組み方は {@see SubjectGroupMatcher}。
 *
 * 事実は {@see SubjectQuery} が d に成り立つ関係だけから作る。d に有効なユーザーがいなければ、ユーザーの事実はどれも空。
 * 所属から来る事実(組織・その上位と下位・役職)はカレント組織の所属 1 つだけから作り、カレント組織が無ければ空
 * (docs の 設計/デモユーザー切替/今の利用者.md 5.5)。
 * 判定する対象者は、条件の相手(user・department・companyPost)を d に有効なものだけ読み込んでおく
 * (d に有効でない相手は null = その条件はどの判定を求める者にも合わない)。これも {@see SubjectQuery} が行う。
 */
final readonly class Subject
{
    /**
     * @param  int|null  $userId  d に有効なユーザー(いなければ null = 認証状態は anonymous)
     * @param  string|null  $ipv4Address  接続元の IPv4 アドレス(無ければ null)
     * @param  DateTimeInterface  $date  基準日 d(期間の条件を比べる)
     * @param  array<int, int>  $departmentIds  カレント組織の所属の組織(高々 1 つ)
     * @param  array<int, int>  $upperDepartmentIds  カレント組織の上位の組織(d に成り立つ内包を親へたどった組織。カレント組織そのものは含まない)
     * @param  array<int, int>  $lowerDepartmentIds  カレント組織の下位の組織(同じく子へたどった組織)
     * @param  Collection<int, CompanyPost>  $companyPosts  カレント組織の所属の、d に成り立つ所属役職の役職(重ねない)
     * @param  array<int, int>  $roleIds  d に持つロール(サブロールを含む)
     */
    public function __construct(
        public ?int $userId,
        public ?string $ipv4Address,
        public DateTimeInterface $date,
        public array $departmentIds,
        public array $upperDepartmentIds,
        public array $lowerDepartmentIds,
        public Collection $companyPosts,
        public array $roleIds,
    ) {}

    /**
     * 条件の相手(サブジェクト)に合うか(docs の「相手に合う」。除外を掛ける前)。条件は相手を 1 つだけ指す(検査制約。期間は開始日の列で数える)。
     * 指す相手が d に有効でなければ(読み込んだ相手が null)合わない。ロールは期間を持たないので常に有効。IPv4 アドレス・認証状態・期間はマスタを指さない。
     * 子の対象者を指す条件は {@see SubjectGroupMatcher} が評価する。
     *
     * @throws UnexpectedValueException サブジェクトのどの相手の列にも当たらない条件(子の対象者を指す条件・判定できない種類の条件)。
     *                                  黙って合う・合わないのどちらにもしない(除外の反転で許可に倒れないように)
     */
    public function matchesTarget(AuthzSubjectCondition $condition): bool
    {
        return match (true) {
            $condition->user_id !== null => $condition->user !== null && $condition->user_id === $this->userId,
            $condition->department_id !== null => $condition->department !== null && $this->matchesDepartment($condition->department_id, $condition->operator),
            $condition->company_post_id !== null => $condition->companyPost !== null && $this->matchesCompanyPost($condition->companyPost, $condition->operator),
            $condition->role_id !== null => in_array($condition->role_id, $this->roleIds, true),
            $condition->ipv4_pattern !== null => $this->matchesIpv4Pattern($condition->ipv4_pattern),
            $condition->meta_subject !== null => $condition->meta_subject === $this->metaSubject(),
            $condition->term_start_date !== null => $this->isWithinTerm($condition),
            default => throw new UnexpectedValueException("判定できない種類の条件(id {$condition->id})"),
        };
    }

    /**
     * ユーザーの組織を条件の組織と比べる。lt = 下位組織(条件の組織が、ユーザーの組織の上位にある)、le = 一致するかまたは下位、
     * eq = 一致、ge = 一致するかまたは上位、gt = 上位組織(条件の組織が、ユーザーの組織の下位にある)。
     */
    private function matchesDepartment(int $departmentId, ConditionOperator $operator): bool
    {
        $candidates = match ($operator) {
            ConditionOperator::LessThan => $this->upperDepartmentIds,
            ConditionOperator::LessThanOrEqual => [...$this->departmentIds, ...$this->upperDepartmentIds],
            ConditionOperator::Equal => $this->departmentIds,
            ConditionOperator::GreaterThanOrEqual => [...$this->departmentIds, ...$this->lowerDepartmentIds],
            ConditionOperator::GreaterThan => $this->lowerDepartmentIds,
        };

        return in_array($departmentId, $candidates, true);
    }

    /**
     * ユーザーの役職を条件の役職と比べる。ランク(アンカーの値)は小さいほど上位。比べるのは条件の役職と同じ組織セットの役職だけ。
     * lt = ランクが下位、le = 同じか下位、eq = 指定の役職(ランクが同じ別の役職は合わない)、ge = 同じか上位、gt = 上位。
     */
    private function matchesCompanyPost(CompanyPost $conditionPost, ConditionOperator $operator): bool
    {
        return $this->companyPosts->contains(
            fn (CompanyPost $held) => $held->department_set_id === $conditionPost->department_set_id && match ($operator) {
                ConditionOperator::LessThan => $held->rank > $conditionPost->rank,
                ConditionOperator::LessThanOrEqual => $held->rank >= $conditionPost->rank,
                ConditionOperator::Equal => $held->id === $conditionPost->id,
                ConditionOperator::GreaterThanOrEqual => $held->rank <= $conditionPost->rank,
                ConditionOperator::GreaterThan => $held->rank < $conditionPost->rank,
            },
        );
    }

    /**
     * 接続元の IPv4 アドレスの 4 つの部分が、パターンの 4 つの部分にそれぞれ合うか(数値 = 等しい、* = どの値にも合う、
     * [m-n] = m 以上 n 以下。m > n はどの値にも合わない)。接続元が無ければ合わない。
     */
    private function matchesIpv4Pattern(string $pattern): bool
    {
        if ($this->ipv4Address === null) {
            return false;
        }

        $octets = array_map(intval(...), explode('.', $this->ipv4Address));

        foreach (explode('.', $pattern) as $index => $part) {
            if (! $this->matchesIpv4Part($part, $octets[$index] ?? -1)) {
                return false;
            }
        }

        return true;
    }

    private function matchesIpv4Part(string $part, int $octet): bool
    {
        if ($part === '*') {
            return true;
        }

        if (preg_match('/\A\[(\d+)-(\d+)\]\z/', $part, $range) === 1) {
            return intval($range[1]) <= $octet && $octet <= intval($range[2]);
        }

        return intval($part) === $octet;
    }

    /**
     * 認証状態は d に有効なユーザーの有無で決める(一方だけ。認証済みの者はゲストの条件に合わない)。
     */
    private function metaSubject(): MetaSubject
    {
        return $this->userId === null ? MetaSubject::Anonymous : MetaSubject::Authenticated;
    }

    /**
     * d が期間 [term_start_date, term_end_date) の中にあるか。日付は時刻を持たないので、日付の文字列(Y-m-d)で比べる。
     */
    private function isWithinTerm(AuthzSubjectCondition $condition): bool
    {
        $day = $this->date->format('Y-m-d');

        return $condition->term_start_date !== null
            && $condition->term_end_date !== null
            && $condition->term_start_date->format('Y-m-d') <= $day
            && $day < $condition->term_end_date->format('Y-m-d');
    }
}
