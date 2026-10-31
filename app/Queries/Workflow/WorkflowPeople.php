<?php

namespace App\Queries\Workflow;

use App\Queries\Master\Api\MasterNames;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;

/**
 * 画面の人・組織の表示(docs の 設計/ワークフロー/画面/README.md 1.2 の WorkflowPerson = id・コード・d とロケールの名前。名前が無ければ name だけ null)。
 * 名前は共通マスタの公開の口 {@see MasterNames} で引く。判定しない。
 *
 * @phpstan-type Person array{id: int, code: string, name: string|null}
 * @phpstan-type LinkedPerson array{id: int, code: string, name: string|null, mainDepartmentId: int|null}
 */
class WorkflowPeople
{
    public function __construct(private MasterNames $names) {}

    /**
     * ユーザーの表示(1 回の呼び出しで引く)。
     *
     * @param  list<int>  $userIds
     * @return array<int, Person> ユーザーの id → 表示
     *
     * @throws LogicException 無いユーザーを渡したとき(案件・履歴・処理対象者のユーザーは外部キーで必ずある)
     */
    public function users(array $userIds, DateTimeInterface $date, string $localeCode): array
    {
        return $this->people($userIds, $this->names->users(array_values(array_unique($userIds)), $date, $localeCode), 'ユーザー');
    }

    /**
     * 名前の札に出す人の表示(表示に主所属の組織の id を足す。札の「組織図で見る」の行き先 = docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1)。
     *
     * @param  array<int, Person>  $people  ユーザーの id → 表示({@see self::users()} の結果。名前の日は呼び手が決める)
     * @return array<int, LinkedPerson>
     */
    public function linked(array $people, CarbonImmutable $today, string $localeCode): array
    {
        $departmentIds = $this->mainDepartmentIds(array_keys($people), $today, $localeCode);

        return array_map(
            fn (array $person) => [...$person, 'mainDepartmentId' => $departmentIds[$person['id']] ?? null],
            $people,
        );
    }

    /**
     * 今日の主所属の組織の id(主所属の意味は共通マスタの公開の口 {@see MasterNames::mainDepartments()} のまま)。組織図は今日の木で開くので、
     * 名前の日(申請基準日など)に依らず今日で引く(メニューと導線.md 2.1 の「移り先は今日で判定する」)。
     *
     * @param  list<int>  $userIds
     * @return array<int, int|null> ユーザーの id → 主所属の組織の id(主所属が無ければ null。無いユーザーは入らない)
     */
    public function mainDepartmentIds(array $userIds, CarbonImmutable $today, string $localeCode): array
    {
        $userIds = array_values(array_unique($userIds));
        $departments = $this->names->mainDepartments($userIds, $today, $localeCode);

        return array_map(fn (?array $department) => $department['id'] ?? null, $departments);
    }

    /**
     * 組織の表示。
     *
     * @param  list<int>  $departmentIds
     * @return array<int, Person> 組織の id → 表示
     *
     * @throws LogicException 無い組織を渡したとき(担当組織は外部キーで必ずある)
     */
    public function departments(array $departmentIds, DateTimeInterface $date, string $localeCode): array
    {
        return $this->people($departmentIds, $this->names->departments(array_values(array_unique($departmentIds)), $date, $localeCode), '組織');
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array{code: string, name: string|null}>  $named
     * @return array<int, Person>
     */
    private function people(array $ids, array $named, string $kind): array
    {
        $people = [];

        foreach ($ids as $id) {
            $entry = $named[$id] ?? throw new LogicException("{$kind} {$id} がありません");
            $people[$id] = ['id' => $id, 'code' => $entry['code'], 'name' => $entry['name']];
        }

        return $people;
    }
}
