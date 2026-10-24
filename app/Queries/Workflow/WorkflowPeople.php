<?php

namespace App\Queries\Workflow;

use App\Queries\Master\Api\MasterNames;
use DateTimeInterface;
use LogicException;

/**
 * 画面の人・組織の表示(docs の 設計/ワークフロー/画面/README.md 1.2 の WorkflowPerson = id・コード・d とロケールの名前。名前が無ければ name だけ null)。
 * 名前は共通マスタの公開の口 {@see MasterNames} で引く。判定しない。
 *
 * @phpstan-type Person array{id: int, code: string, name: string|null}
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
