<?php

namespace Tests\Unit\Queries\Workflow;

use App\Queries\Authz\Api\RoleCandidates;
use App\Queries\Master\Api\MasterCandidates;
use App\Queries\Workflow\SubjectCandidates;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

/**
 * 対象者の候補の群(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節): 1 つの語で 4 種類を引き、上限は種類ごと
 * (どの口にも上限 20 を渡し、群をまとめて切らない)。空の語は口を呼ばずに 4 つの空の群。公開の口は、上限まで候補を返す偽物に替える。
 */
final class SubjectCandidatesTest extends TestCase
{
    /** @var array<string, array{string, int}> 口ごとの呼ばれた [語, 上限] */
    private array $calls = [];

    public function test_each_kind_gets_its_own_limit(): void
    {
        $groups = $this->candidates()->of('検索', CarbonImmutable::parse('2026-11-01'), 'ja');

        $this->assertSame(['user', 'department', 'company_post', 'role'], array_column($groups, 'type'));
        foreach ($groups as $group) {
            $this->assertCount(SubjectCandidates::LIMIT, $group['candidates'], $group['type']);
        }
        $this->assertSame(
            array_fill_keys(['users', 'departments', 'companyPosts', 'roles'], ['検索', SubjectCandidates::LIMIT]),
            $this->calls,
        );
        $this->assertSame('u-1 の所属', $groups[0]['candidates'][0]['mainDepartmentName']);
        $this->assertNull($groups[1]['candidates'][0]['mainDepartmentName']);
    }

    public function test_an_empty_keyword_calls_nothing_and_returns_four_empty_groups(): void
    {
        $groups = $this->candidates()->of('', CarbonImmutable::parse('2026-11-01'), 'ja');

        $this->assertSame(['user', 'department', 'company_post', 'role'], array_column($groups, 'type'));
        $this->assertSame([[], [], [], []], array_column($groups, 'candidates'));
        $this->assertSame([], $this->calls);
    }

    /**
     * 上限まで並べた偽物の候補(どの口の形にも合う)。
     *
     * @return list<array{id: int, code: string, name: string|null}>
     */
    public static function rowsOf(string $prefix, int $limit): array
    {
        return array_map(
            fn (int $id) => ['id' => $id, 'code' => "{$prefix}-{$id}", 'name' => null],
            range(1, $limit),
        );
    }

    private function candidates(): SubjectCandidates
    {
        $record = function (string $port, string $q, int $limit): void {
            $this->calls[$port] = [$q, $limit];
        };

        return new SubjectCandidates($this->fakeMaster($record), $this->fakeRoles($record));
    }

    /**
     * 呼ばれた語と上限を覚え、上限まで候補を返す偽物の公開の口(マスタ)。
     */
    private function fakeMaster(Closure $record): MasterCandidates
    {
        return new class($record) extends MasterCandidates
        {
            public function __construct(private Closure $record) {}

            public function usersOn(string $q, DateTimeInterface $d, string $localeCode, int $limit): array
            {
                ($this->record)('users', $q, $limit);

                return array_map(
                    fn (array $row) => [...$row, 'mainDepartmentName' => "{$row['code']} の所属"],
                    SubjectCandidatesTest::rowsOf('u', $limit),
                );
            }

            public function departmentsOn(string $q, DateTimeInterface $d, string $localeCode, int $limit): array
            {
                ($this->record)('departments', $q, $limit);

                return SubjectCandidatesTest::rowsOf('d', $limit);
            }

            public function companyPostsOn(string $q, DateTimeInterface $d, string $localeCode, int $limit): array
            {
                ($this->record)('companyPosts', $q, $limit);

                return SubjectCandidatesTest::rowsOf('p', $limit);
            }
        };
    }

    /**
     * 呼ばれた語と上限を覚え、上限まで候補を返す偽物の公開の口(ロール)。
     */
    private function fakeRoles(Closure $record): RoleCandidates
    {
        return new class($record) extends RoleCandidates
        {
            public function __construct(private Closure $record) {}

            public function roles(string $q, string $localeCode, int $limit): array
            {
                ($this->record)('roles', $q, $limit);

                return SubjectCandidatesTest::rowsOf('r', $limit);
            }
        };
    }
}
