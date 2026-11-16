<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentToolSubject;
use App\Queries\Master\Api\DepartmentTree;
use App\Queries\Master\Api\MasterCandidates;
use App\Queries\Master\Api\MasterNames;

/**
 * 名前で書いたユーザー・組織の相手を、共通マスタの候補の口で引いて決める部品(docs の 設計/AI/道具と権限/README.md 10.2 のユーザー・組織の名前)。
 * 決まるのは、引いた候補のうち名前が完全に一致する物が 1 つで、引いた件数が上限に達していないときだけ(姓だけ・表記の違う名前では決めない)。
 * 型「ユーザーと組織」はユーザーと組織の両方を引き、両方を合わせて 1 つのときだけ決まる。決まらなければ、候補(条件に書く値・名前・所属)か
 * 当たらなかったことを返す。
 */
class PartyCandidates
{
    /** 1 つの名前で引く候補の数の上限(型ごと) */
    public const LIMIT = 10;

    public function __construct(
        private MasterCandidates $candidates,
        private DepartmentTree $tree,
        private MasterNames $names,
    ) {}

    /**
     * 名前の相手の、条件に書く値(決まらなければ null と誤りの文)。
     *
     * @return array{string|null, string|null}
     */
    public function resolve(string $code, string $type, string $name, AgentToolSubject $subject): array
    {
        $candidates = [
            ...($type === 'department' ? [] : $this->users($name, $subject, $type === 'user_or_department')),
            ...($type === 'user' ? [] : $this->departments($name, $subject, $type === 'user_or_department')),
        ];
        $exact = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['name'] === $name));
        $full = count(array_filter($candidates, fn (array $candidate): bool => $candidate['full']));

        if (count($exact) === 1 && $full === 0) {
            return [$exact[0]['value'], null];
        }

        $subjectText = "条件 `{$code}` の名前「{$name}」";

        if ($candidates === []) {
            return [null, "{$subjectText}に当たる相手がいない。"];
        }

        $lines = array_map(
            fn (array $candidate): string => "- `{$candidate['value']}` {$candidate['label']}",
            $candidates,
        );

        return [null, implode("\n", ["{$subjectText}の相手が 1 つに決まらない。候補:", ...$lines])];
    }

    /**
     * @return list<array{value: string, name: string|null, label: string, full: bool}>
     */
    private function users(string $name, AgentToolSubject $subject, bool $withKind): array
    {
        $users = $this->candidates->usersOn($name, $subject->date(), $subject->localeCode(), self::LIMIT);

        return array_map(fn (array $user): array => [
            'value' => ($withKind ? 'user:' : '').$user['id'],
            'name' => $user['name'],
            'label' => ($user['name'] ?? $user['code'])
                .'(ユーザー'.($user['mainDepartmentName'] === null ? '' : '。所属: '.$user['mainDepartmentName']).')',
            'full' => count($users) >= self::LIMIT,
        ], $users);
    }

    /**
     * @return list<array{value: string, name: string|null, label: string, full: bool}>
     */
    private function departments(string $name, AgentToolSubject $subject, bool $withKind): array
    {
        $date = $subject->date();
        $departments = $this->candidates->departmentsOn($name, $date, $subject->localeCode(), self::LIMIT);
        $parents = [];

        foreach ($departments as $department) {
            $parents[$department['id']] = $this->tree->ancestorIdsOn($department['id'], $date)[0] ?? null;
        }

        $parentIds = array_values(array_unique(array_filter($parents, is_int(...))));
        $parentNames = $this->names->departments($parentIds, $date, $subject->localeCode());

        return array_map(function (array $department) use ($withKind, $parents, $parentNames, $departments): array {
            $parentId = $parents[$department['id']] ?? null;
            $parent = $parentId === null ? null : $parentNames[$parentId]['name'] ?? null;

            return [
                'value' => ($withKind ? 'department:' : '').$department['id'],
                'name' => $department['name'],
                'label' => ($department['name'] ?? $department['code'])."(組織。コード {$department['code']}"
                    .($parent === null ? '' : "。上位の組織: {$parent}").')',
                'full' => count($departments) >= self::LIMIT,
            ];
        }, $departments);
    }
}
