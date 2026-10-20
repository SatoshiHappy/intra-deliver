<?php

namespace App\Queries\Authz;

use App\Enums\Authz\SubjectColumnKind;
use App\Queries\Authz\Api\RoleCandidates;
use App\Queries\Master\Api\MasterCandidates;
use DateTimeInterface;

/**
 * 「列を追加」の候補(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1): 種類ごとに既存の候補の口(ユーザー・組織・役職 =
 * {@see MasterCandidates}、ロール = {@see RoleCandidates})を d で呼ぶ(d に有効なものだけ。ロールは期間を持たない)。空の語は空。判定しない。
 *
 * @phpstan-type SubjectColumnCandidate array{id: int, code: string, name: string|null, mainDepartmentName: string|null}
 */
class SubjectColumnCandidates
{
    /** 候補の上限 */
    public const LIMIT = 20;

    public function __construct(
        private MasterCandidates $master,
        private RoleCandidates $roleCandidates,
    ) {}

    /**
     * 候補(並び = 候補の口の並び)。主所属の名前はユーザーだけ(ほかは null)。
     *
     * @return list<SubjectColumnCandidate>
     */
    public function of(SubjectColumnKind $kind, string $keyword, DateTimeInterface $date, string $localeCode): array
    {
        if ($kind === SubjectColumnKind::User) {
            return $this->master->usersOn($keyword, $date, $localeCode, self::LIMIT);
        }

        $limit = self::LIMIT;
        $candidates = match ($kind) {
            SubjectColumnKind::Role => $this->roleCandidates->roles($keyword, $localeCode, $limit),
            SubjectColumnKind::Department => $this->master->departmentsOn($keyword, $date, $localeCode, $limit),
            SubjectColumnKind::CompanyPost => $this->master->companyPostsOn($keyword, $date, $localeCode, $limit),
        };

        return array_map(fn (array $candidate) => [...$candidate, 'mainDepartmentName' => null], $candidates);
    }
}
