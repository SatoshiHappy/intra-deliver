<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\UpdateRecordModule;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の参照制限(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1 節・5.2 節・9 節の「版の作り方」「差分」):
 * チェックだけ・参照者だけの更新で版 + 1 になり、差分に参照制限・参照者の行が出る。参照者の並べ替えだけでは版を作らない。チェックを外した更新は、
 * 参照者がいたときは参照制限と参照者の 2 行、いなかったときは参照制限の 1 行。参照制限の既定を変えても版は増えない。
 * 同じ名前のユーザーと組織は相手の種類で見分けられる。
 */
final class RecordRowAccessRevisionTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    private User $writer;

    private User $user;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relatedModule('meetings')->update(['row_access_restricted_by_default' => false]);
        $this->writer = $this->user();
        [$this->user, $this->department] = $this->userAndDepartmentWithTheSameId(DepartmentSet::factory()->create());
    }

    public function test_only_the_check_and_only_the_viewers_make_a_revision_and_their_diff(): void
    {
        $id = $this->create();

        $this->update($id, true);
        $this->assertSame(
            [2, ['restricted' => ['before' => false, 'after' => true], 'viewers' => null]],
            $this->latestDiff($id),
        );

        $this->update($id, true, [$this->party($this->user), $this->party($this->department)]);
        [$revision, $diff] = $this->latestDiff($id);
        $this->assertSame(3, $revision);
        $this->assertNull($diff['restricted'] ?? null);
        $this->assertSame([], $diff['viewers']['before'] ?? null);
        $this->assertSame(
            [['user', $this->user->id], ['department', $this->user->id]],
            array_map(fn (array $viewer) => [$viewer['type'], $viewer['id']], $diff['viewers']['after'] ?? []),
        );
    }

    public function test_a_reorder_of_the_viewers_and_a_change_of_the_default_make_no_revision(): void
    {
        $id = $this->create(true, [$this->party($this->user), $this->party($this->department)]);

        $this->update($id, true, [$this->party($this->department), $this->party($this->user)]);
        app(UpdateRecordModule::class)->execute('meetings', ['row_access_restricted_by_default' => true]);

        $this->assertSame(1, RecordRevision::query()->where('record_id', $id)->count());
    }

    public function test_removing_the_check_shows_two_lines_with_viewers_and_one_line_without(): void
    {
        $withViewers = $this->create(true, [$this->party($this->user)]);
        $withoutViewers = $this->create(true);

        $this->update($withViewers, false);
        $this->update($withoutViewers, false);

        [, $two] = $this->latestDiff($withViewers);
        [, $one] = $this->latestDiff($withoutViewers);
        $this->assertSame(['before' => true, 'after' => false], $two['restricted'] ?? null);
        $this->assertSame(
            [[$this->user->id], []],
            [array_column($two['viewers']['before'] ?? [], 'id'), $two['viewers']['after'] ?? null],
        );
        $this->assertSame(['restricted' => ['before' => true, 'after' => false], 'viewers' => null], $one);
    }

    /**
     * @param  list<array{type: string, id: int}>|null  $viewers
     */
    private function create(?bool $restricted = null, ?array $viewers = null): int
    {
        $input = new RecordInput(['title' => '定例'], rowAccessRestricted: $restricted, rowAccessViewers: $viewers);

        return app(CreateRecord::class)->execute($this->contextFor($this->writer->id), 'meetings', $input);
    }

    /**
     * @param  list<array{type: string, id: int}>|null  $viewers
     */
    private function update(int $id, ?bool $restricted = null, ?array $viewers = null): void
    {
        $input = new RecordInput(['title' => '定例'], rowAccessRestricted: $restricted, rowAccessViewers: $viewers);
        app(UpdateRecord::class)->execute($this->contextFor($this->writer->id), 'meetings', $id, $input, null);
    }

    /**
     * @return array{type: string, id: int}
     */
    private function party(User|Department $party): array
    {
        return ['type' => $party instanceof User ? 'user' : 'department', 'id' => $party->id];
    }

    /**
     * 最新の版の番号と、1 つ前の版からの差分の参照制限(画面の値)。
     *
     * @return array{int, mixed}
     */
    private function latestDiff(int $id): array
    {
        [$after, $before] = RecordRevision::query()->where('record_id', $id)->orderByDesc('revision')->limit(2)
            ->get()->all();
        $diff = app(RecordRevisionQuery::class)->diffBetween(
            new Requester($this->writer->id, null),
            $before->id,
            $after->id,
            CarbonImmutable::parse(self::TODAY),
            'ja',
        );

        return [$after->revision, $diff['rowAccess']];
    }
}
