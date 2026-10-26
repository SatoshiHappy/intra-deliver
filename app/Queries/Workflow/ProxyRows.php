<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowProxy;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Master\Api\MasterNames;
use App\Support\AccessContext;
use Illuminate\Support\Collection;
use LogicException;

/**
 * 代理の表の行(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節の ProxyRow・7.4 節の AdminProxyRow)。
 * 名前・所属は今日・ロケールの値で、所属は今日に有効な人の主所属(有効でない人は null)。終了日は最後の有効な日(行の end_date の前日 =
 * 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節)。行は {@see WorkflowProxies::orderedByStatus()} で引いたもの。判定しない。
 *
 * @phpstan-import-type Person from WorkflowPeople
 *
 * @phpstan-type PersonWithDepartment array{id: int, code: string, name: string|null, department: string|null}
 * @phpstan-type ProxyRow array{
 *     id: int, person: PersonWithDepartment, kind: string, flowName: string|null, startDate: string,
 *     endDate: string|null, status: string,
 * }
 * @phpstan-type AdminProxyRow array{
 *     id: int, kind: string, flowName: string|null, startDate: string, endDate: string|null, status: string,
 *     principal: PersonWithDepartment, proxy: PersonWithDepartment, registeredBy: Person,
 * }
 */
class ProxyRows
{
    public function __construct(
        private WorkflowPeople $people,
        private MasterNames $names,
        private EffectiveUsers $effectiveUsers,
    ) {}

    /**
     * 代理の設定の表の行(person = 自分の代理先の表は代理先、自分の代理元の表は代理元)。
     *
     * @param  Collection<int, WorkflowProxy>  $proxies
     * @param  'principal_user_id'|'proxy_user_id'  $personColumn  person にする人の列
     * @return list<ProxyRow>
     */
    public function own(Collection $proxies, string $personColumn, AccessContext $context): array
    {
        $people = $this->withDepartments(self::ids($proxies, $personColumn), $context);

        return array_values($proxies->map(fn (WorkflowProxy $proxy) => [
            'id' => $proxy->id,
            'person' => $people[(int) $proxy->getAttribute($personColumn)],
            ...self::terms($proxy),
        ])->all());
    }

    /**
     * 代理の管理の表の行。
     *
     * @param  Collection<int, WorkflowProxy>  $proxies
     * @return list<AdminProxyRow>
     */
    public function admin(Collection $proxies, AccessContext $context): array
    {
        $userIds = [...self::ids($proxies, 'principal_user_id'), ...self::ids($proxies, 'proxy_user_id')];
        $people = $this->withDepartments($userIds, $context);
        $registeredIds = self::ids($proxies, 'registered_by_user_id');
        $registered = $this->people->users($registeredIds, $context->date, $context->localeCode);

        return array_values($proxies->map(fn (WorkflowProxy $proxy) => [
            'id' => $proxy->id,
            ...self::terms($proxy),
            'principal' => $people[$proxy->principal_user_id],
            'proxy' => $people[$proxy->proxy_user_id],
            'registeredBy' => $registered[$proxy->registered_by_user_id],
        ])->all());
    }

    /**
     * 種類・対象・期間・状態(どちらの表も同じ形)。
     *
     * @return array{kind: string, flowName: string|null, startDate: string, endDate: string|null, status: string}
     *
     * @throws LogicException 状態の列を足さずに引いた行のとき(呼び手の誤り)
     */
    private static function terms(WorkflowProxy $proxy): array
    {
        $rank = $proxy->getAttribute(WorkflowProxies::STATUS_RANK);
        $status = $rank === null ? null : (WorkflowProxies::STATUSES[(int) $rank] ?? null);

        return [
            'kind' => $proxy->kind->value,
            'flowName' => $proxy->workflowFlow?->name,
            'startDate' => $proxy->start_date->toDateString(),
            'endDate' => $proxy->end_date?->subDay()->toDateString(),
            'status' => $status ?? throw new LogicException("代理 {$proxy->id} の状態が無い"),
        ];
    }

    /**
     * 行の人の列の id(行の順。重ねてよい)。
     *
     * @param  Collection<int, WorkflowProxy>  $proxies
     * @return list<int>
     */
    private static function ids(Collection $proxies, string $column): array
    {
        return array_values($proxies->map(fn (WorkflowProxy $proxy) => (int) $proxy->getAttribute($column))->all());
    }

    /**
     * 人の表示と今日の主所属(今日に有効でない人は null)。
     *
     * @param  list<int>  $userIds
     * @return array<int, PersonWithDepartment>
     */
    private function withDepartments(array $userIds, AccessContext $context): array
    {
        $ids = array_values(array_unique($userIds));
        $people = $this->people->users($ids, $context->date, $context->localeCode);
        $effective = $this->effectiveUsers->idsOn($ids, $context->date);
        $departments = $this->names->mainDepartmentNames($effective, $context->date, $context->localeCode);

        return array_map(
            fn (array $person) => [...$person, 'department' => $departments[$person['id']] ?? null],
            $people,
        );
    }
}
