<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordApplyEntry;
use App\Queries\Workflow\RecordApplyFlow;
use App\Queries\Workflow\RecordApplyFlows;
use App\Queries\Workflow\WorkflowRecordApplyEntries;
use Tests\Support\AppliesFromRecords;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * レコードから申請できる入口のワークフローの実装(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 2 節): レコードの業務モジュールで
 * 選べるフローの名前・説明と、申請の画面の URL(flow とクエリ record)。無いレコード・明細の行は空。持ち主の有無を見ない。
 */
final class WorkflowRecordApplyEntriesTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_the_entries_are_the_flows_to_apply_from_the_record_with_the_application_url(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $listed = app(RecordApplyFlows::class)
            ->flowsFor($this->requesterOf($u0001), self::PROJECT, $this->operationDay());

        $entries = $this->entries($u0001, $record->id);

        $this->assertContains(self::FLOW_CODE, array_map(fn (RecordApplyFlow $flow) => $flow->code, $listed));
        $expected = array_map(fn (RecordApplyFlow $flow) => [
            $flow->name,
            $flow->description,
            route('workflow.applications.create', ['flow' => $flow->code, 'record' => $record->id]),
        ], $listed);
        $actual = array_map(fn (RecordApplyEntry $entry) => [$entry->name, $entry->description, $entry->url], $entries);
        $this->assertSame($expected, $actual);
        $urls = array_map(fn (RecordApplyEntry $entry) => $entry->url, $entries);
        $this->assertContains(url('/workflow/flows/'.self::FLOW_CODE.'/apply').'?record='.$record->id, $urls);
    }

    public function test_a_missing_record_and_a_detail_row_have_no_entry_and_a_held_record_still_has_entries(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $held = $this->projectRecord($u0001);
        $this->applyFromRecord($u0001, $held->id);

        $this->assertSame([], $this->entries($u0001, $this->missingRecordId()));
        $this->assertSame([], $this->entries($u0001, $this->detailRowIds($record->id)[0]));
        $this->assertSame(count($this->entries($u0001, $record->id)), count($this->entries($u0001, $held->id)));
        $this->assertNotSame([], $this->entries($u0001, $held->id));
    }

    public function test_a_person_without_update_has_no_entry(): void
    {
        [$u0001, $u0003] = [$this->seededUser('u0001'), $this->seededUser('u0003')];
        $record = $this->projectRecord($u0001);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0003);

        $this->assertSame([], $this->entries($u0001, $record->id));
        $this->assertNotSame([], $this->entries($u0003, $record->id));
    }

    /**
     * @return list<RecordApplyEntry>
     */
    private function entries(User $user, int $recordId): array
    {
        return app(WorkflowRecordApplyEntries::class)
            ->entriesFor($this->requesterOf($user), $recordId, $this->operationDay());
    }

    private function requesterOf(User $user): Requester
    {
        return new Requester($user->id, null);
    }

    private function missingRecordId(): int
    {
        return (int) Record::query()->max('id') + 1000;
    }
}
