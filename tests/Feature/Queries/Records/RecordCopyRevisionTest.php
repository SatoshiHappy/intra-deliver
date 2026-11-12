<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordSnapshots;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Support\BuildsCopyChecks;
use Tests\Support\TransactionalTestCase;

/**
 * 写しの初期値の公開の口「版から」と「参照者の確かめ」(docs の 設計/ワークフロー/業務データとの接続/公開の口/画面の値の口.md の 7.2、
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 13.3): 版の値と参照制限の参照者に保存と同じ確かめを当てる。版の後に消えた参照先・選択肢
 * (同じ要求の中の窓)は空になる。記録の無い版の参照制限は null。判定をせず、書かない。参照者の確かめは書き込みの参照者の確かめと結果が同じ。
 *
 * @phpstan-import-type CopyCheckFixture from BuildsCopyChecks
 */
final class RecordCopyRevisionTest extends TransactionalTestCase
{
    use BuildsCopyChecks;

    public function test_the_revision_values_and_viewers_are_checked_and_the_viewers_follow_the_fields(): void
    {
        $fixture = $this->copyCheckFixture();
        ['users' => $users, 'departments' => $departments] = $this->people();
        $record = $this->sourceRecord($fixture, ['note' => 'メモ']);
        $this->referTo($record, 'user', $users['endsToday']);
        $this->referTo($record, 'ref', $fixture['refOk']);
        $viewers = [$users['endsTomorrow'], $users['endsToday'], $departments['startsToday'], $departments['disabled']];
        $this->restrictTo($record, $viewers);

        $copied = $this->fromRevision($fixture, $this->revisionOf($fixture, $record)->id);

        $this->assertSame('メモ', $copied['values']['note']);
        $this->assertNull($copied['values']['user']);
        $this->assertSame(['id' => $fixture['refOk']->id], self::targetsOf($copied['values']['ref']));
        $this->assertSame(['restricted' => true, 'viewers' => [
            ['type' => 'user', 'id' => $users['endsTomorrow']->id],
            ['type' => 'department', 'id' => $departments['startsToday']->id],
        ]], $copied['rowAccess']);
        $this->assertSame(['user', '参照制限の参照者'], array_column($copied['clearedFields'], 'name'));
    }

    public function test_targets_and_options_gone_after_the_revision_are_cleared(): void
    {
        $fixture = $this->copyCheckFixture();
        $record = $this->sourceRecord($fixture);
        $ids = RecordField::query()->whereIn('code', ['choice', 'ref', 'note'])->pluck('id', 'code');
        $revision = RecordRevision::factory()->create([
            'record_module_id' => $fixture['module']->id,
            'record_id' => $record->id,
            'snapshot' => [
                'values' => [$ids['choice'] => 'gone', $ids['note'] => '残る', 999_999 => '消した項目'],
                'references' => [$ids['ref'] => ['type' => 'record', 'id' => 999_999_999]],
                'details' => [],
                'attachments' => [],
            ],
        ]);

        $copied = $this->fromRevision($fixture, $revision->id);

        $this->assertSame([null, null], [$copied['values']['choice'], $copied['values']['ref']]);
        $this->assertSame('残る', $copied['values']['note']);
        $this->assertSame(['choice', 'ref'], array_column($copied['clearedFields'], 'name'));
        $this->assertNull($copied['rowAccess'], 'row_access の無い版');
    }

    public function test_the_revision_port_does_not_judge_and_rejects_missing_or_foreign_revisions(): void
    {
        $fixture = $this->copyCheckFixture();
        $revisionId = $this->revisionOf($fixture, $this->sourceRecord($fixture, ['note' => 'メモ']))->id;
        $refModuleId = RecordModule::query()->where('code', 'm_ref')->sole()->id;
        $foreign = RecordRevision::factory()->create(['record_module_id' => $refModuleId]);
        $forms = app(RecordFormQuery::class);
        $date = CarbonImmutable::parse('2026-10-11');
        $revisions = RecordRevision::query()->count();

        $guest = new Requester(null, null);
        $requester = $this->requesterOf($fixture);

        $this->assertSame('メモ', $forms->copyFromRevision($guest, 'm_src', $revisionId, $date, 'ja')['values']['note']);
        $this->assertSame($revisions, RecordRevision::query()->count());
        $this->expectNotFound(fn () => $forms->copyFromRevision($requester, 'm_src', 999_999_999, $date, 'ja'));
        $this->expectNotFound(fn () => $forms->copyFromRevision($requester, 'missing', $revisionId, $date, 'ja'));
        $this->expectException(LogicException::class);
        $forms->copyFromRevision($requester, 'm_src', $foreign->id, $date, 'ja');
    }

    public function test_effective_viewers_keep_the_order_and_match_the_viewer_check_of_the_write(): void
    {
        $fixture = $this->copyCheckFixture();
        ['users' => $users, 'departments' => $departments] = $this->people();
        $all = [
            ['type' => 'department', 'id' => $departments['endsTomorrow']->id],
            ['type' => 'user', 'id' => $users['endsToday']->id],
            ['type' => 'user', 'id' => $users['startsToday']->id],
            ['type' => 'department', 'id' => $departments['startsTomorrow']->id],
        ];
        $forms = app(RecordFormQuery::class);
        $date = CarbonImmutable::parse('2026-10-11');

        $kept = $forms->effectiveViewers($all, $date);
        $none = $forms->effectiveViewers([$all[1], $all[3]], $date);

        $this->assertSame([$all[0], $all[2]], $kept);
        $this->assertSame([], $none);
        $this->assertTrue(Record::query()->whereKey($this->create($fixture, $kept))->exists());

        try {
            $this->create($fixture, $all);
            $this->fail('外した相手を含めると 422');
        } catch (ValidationException $exception) {
            $this->assertSame(['row_access_viewers.1', 'row_access_viewers.3'], array_keys($exception->errors()));
        }
    }

    /**
     * @param  CopyCheckFixture  $fixture
     * @param  array<string, mixed>  $values
     */
    private function sourceRecord(array $fixture, array $values = []): Record
    {
        $note = RecordField::query()->where('record_module_id', $fixture['module']->id)->where('code', 'note')->sole();

        return Record::factory()->create([
            'record_module_id' => $fixture['module']->id,
            'field_values' => array_key_exists('note', $values) ? [$note->id => $values['note']] : [],
        ]);
    }

    /**
     * @param  CopyCheckFixture  $fixture
     */
    private function revisionOf(array $fixture, Record $record): RecordRevision
    {
        $module = app(RecordDefinitionQuery::class)->load($fixture['module']);

        return RecordRevision::factory()->create([
            'record_module_id' => $module->id,
            'record_id' => $record->id,
            'snapshot' => app(RecordSnapshots::class)->snapshot($record->refresh(), $module),
        ]);
    }

    /**
     * @param  CopyCheckFixture  $fixture
     * @return array{values: array<string, mixed>, details: array<string, list<array{values: array<string, mixed>}>>,
     *     clearedFields: list<array{kind: string, field: string|null, detail: string|null, row: int|null,
     *     name: string}>,
     *     rowAccess: array{restricted: bool, viewers: list<array{type: 'user'|'department', id: int}>}|null}
     */
    private function fromRevision(array $fixture, int $revisionId): array
    {
        return app(RecordFormQuery::class)->copyFromRevision(
            $this->requesterOf($fixture), 'm_src', $revisionId, CarbonImmutable::parse('2026-10-11'), 'ja',
        );
    }

    /**
     * @param  CopyCheckFixture  $fixture
     * @param  list<array{type: string, id: int}>  $viewers
     */
    private function create(array $fixture, array $viewers): int
    {
        $context = new AccessContext($fixture['me']->id, null, null, CarbonImmutable::parse('2026-10-11'), 'ja');

        $input = new RecordInput(rowAccessRestricted: true, rowAccessViewers: $viewers);

        return app(CreateRecord::class)->execute($context, 'm_src', $input);
    }

    private function expectNotFound(callable $call): void
    {
        try {
            $call();
            $this->fail('404 になる');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }
}
