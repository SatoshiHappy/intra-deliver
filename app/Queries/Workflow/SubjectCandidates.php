<?php

namespace App\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Queries\Authz\Api\RoleCandidates;
use App\Queries\Master\Api\MasterCandidates;
use DateTimeInterface;

/**
 * 定義の管理の対象者の候補(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 4.2 節、画面/README.md 1.1 節): 種類ごとに共通マスタの公開の口
 * (ユーザー・組織・役職。基準日 = 今日)と認可の公開の口(ロール)を呼ぶ。候補の判定は書かない。空の語は空。
 *
 * @phpstan-type SubjectCandidate array{id: int, code: string, name: string|null, mainDepartmentName: string|null}
 */
class SubjectCandidates
{
    /** 候補の上限(画面/README.md 1.1 節) */
    public const LIMIT = 20;

    public function __construct(
        private MasterCandidates $master,
        private RoleCandidates $roles,
    ) {}

    /**
     * @return list<SubjectCandidate> 並び = 候補の口の並び。主所属の名前はユーザーだけ(ほかは null)
     */
    public function of(SelectionConditionKind $type, string $keyword, DateTimeInterface $today, string $locale): array
    {
        $limit = self::LIMIT;

        if ($type === SelectionConditionKind::User) {
            return $this->master->usersOn($keyword, $today, $locale, $limit);
        }

        $candidates = match ($type) {
            SelectionConditionKind::Department => $this->master->departmentsOn($keyword, $today, $locale, $limit),
            SelectionConditionKind::CompanyPost => $this->master->companyPostsOn($keyword, $today, $locale, $limit),
            SelectionConditionKind::Role => $this->roles->roles($keyword, $locale, $limit),
        };

        return array_map(fn (array $candidate) => [...$candidate, 'mainDepartmentName' => null], $candidates);
    }
}
