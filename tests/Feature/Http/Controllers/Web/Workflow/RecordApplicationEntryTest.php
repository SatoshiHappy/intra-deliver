<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\DeleteRecord;
use App\Actions\Workflow\RecordApplicationSteps;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AppliesFromRecords;
use Tests\Support\BuildsUploads;
use Tests\Support\RegistersProxies;
use Tests\Support\RowAccessRestriction;
use Tests\Support\TransactionalTestCase;

/**
 * 既存のデータからの申請の入口(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 4 節・7 節・8 節): 申請のルートに record_id と
 * record_version を足す。判定の順は 403 → 404 → 422 record で、失敗は何も書かない。版の食い違いは 422 record。入力の形の誤りはそのキーの 422。
 * multipart の envelope でも同じ入力になる(添付/README.md の 3.1 節)。一時保存のルートは record_id を捨てる。
 */
final class RecordApplicationEntryTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use BuildsUploads;
    use RegistersProxies;
    use SendsAttachmentEnvelopes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->createFlow();
    }

    /**
     * 成功: 案件の詳細へ移ってトースト。案件はレコードを指し、展開は新規の申請と同じ。直した値がレコードに入り、履歴の行は書いた版を指す。
     */
    public function test_an_application_from_a_record_lands_on_the_new_case(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $new = $this->applyAs($u0001);
        $this->actingAs($u0001);

        $response = $this->postJson($this->applyUrl(), $this->payload($record, ['name' => '直した計画']));

        $response->assertStatus(302)->assertInertiaFlash('toast', ['type' => 'success', 'message' => '申請しました']);
        $case = $this->caseOf($response);
        $this->assertSame([$record->id, 'active'], [$case->record_id, $case->state->value]);
        $this->assertSame($this->nodeStates($new), $this->nodeStates($case));
        $this->assertSame('直した計画', $this->valueOf($record->id, 'name'));
        $revisions = $this->revisionIds($record->id);
        $this->assertSame(array_slice($revisions, -1), $this->historyRevisionIds($case));
    }

    /**
     * 0 の順: できない人(create だけ)は、無い・ほかのモジュール・申請中のどのレコードも 403。できる人は、無い・ほかのモジュール・明細の行が 404、
     * 申請中が 422 record。どれも何も書かない。
     */
    public function test_the_checks_run_in_the_order_403_404_422(): void
    {
        [$u0001, $u0003] = [$this->seededUser('u0001'), $this->seededUser('u0003')];
        $held = $this->projectRecord($u0003);
        $this->applyFromRecord($u0003, $held->id);
        $rowId = $this->detailRowIds($this->projectRecord($u0003)->id)[0];
        $other = Record::factory()->create(['record_module_id' => $this->moduleIdOf('project_issue')]);
        $missing = 999999999;
        $counts = $this->workflowCounts();
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0003);

        $this->actingAs($u0001);
        foreach ([$missing, $other->id, $held->id] as $recordId) {
            $this->postJson($this->applyUrl(), $this->payload($held, ['record_id' => $recordId]))->assertForbidden();
        }

        $this->actingAs($u0003);
        foreach ([$missing, $other->id, $rowId] as $recordId) {
            $this->postJson($this->applyUrl(), $this->payload($held, ['record_id' => $recordId]))->assertNotFound();
        }

        $this->postJson($this->applyUrl(), $this->payload($held))->assertStatus(422)
            ->assertJsonPath('errors', ['record' => [RecordApplicationSteps::HELD]]);
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * ゲスト(未ログイン)は 403。行の判定を通らないレコードは無いのと同じ(404)。どちらも何も書かない。
     */
    public function test_a_guest_gets_403_and_a_record_outside_the_row_access_gets_404(): void
    {
        $record = $this->projectRecord($this->seededUser('u0001'));
        RowAccessRestriction::restrictCode(self::PROJECT);
        $counts = $this->workflowCounts();

        $this->postJson($this->applyUrl(), $this->payload($record))->assertForbidden();
        $this->actingAs($this->seededUser('u0003'));
        $this->postJson($this->applyUrl(), $this->payload($record))->assertNotFound();

        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 開いた後に業務の画面で更新されたら 422 record(業務データの入れ物の版の文言)で、申請の行も書かれない。版の無い送信(null)も比べる。
     */
    public function test_a_record_updated_after_the_form_was_opened_gets_422_record(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $opened = $this->payload($record);
        $this->travel(1)->seconds();
        $this->updateFromScreen($u0001, $record->id, ['budget' => 50]);
        $counts = $this->workflowCounts();
        $this->actingAs($u0001);

        foreach ([$opened, [...$opened, 'record_version' => null]] as $payload) {
            $response = $this->postJson($this->applyUrl(), $payload)->assertStatus(422);
            $this->assertSame(['record'], array_keys($response->json('errors')));
            $this->assertStringContainsString('開いた後に更新されています', $response->json('errors.record.0'));
        }

        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * record_id・record_version の形の誤りは、そのキーの 422(入口の形)。record_id の桁の上限は申請の画面のクエリ record と同じ 18 桁。
     */
    public function test_malformed_record_inputs_get_422_on_their_keys(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->actingAs($u0001);
        $valid = $this->payload($record);
        $withoutVersion = array_diff_key($valid, ['record_version' => true]);

        $send = fn (array $payload) => $this->postJson($this->applyUrl(), $payload);

        $send([...$valid, 'record_id' => 'abc'])->assertJsonValidationErrors(['record_id']);
        $send([...$valid, 'record_id' => 0])->assertJsonValidationErrors(['record_id']);
        $send([...$valid, 'record_id' => 1000000000000000000])->assertJsonValidationErrors(['record_id']);
        $send($withoutVersion)->assertJsonValidationErrors(['record_version']);
        $send([...$valid, 'record_version' => 5])->assertJsonValidationErrors(['record_version']);
        $this->assertSame(0, WorkflowCase::query()->where('record_id', $record->id)->count());
    }

    /**
     * 確かめの後の競合: 同じレコードへの別の申請は 422 record、消えたレコードは 404。
     */
    public function test_races_after_the_check_get_422_record_or_404(): void
    {
        $u0001 = $this->seededUser('u0001');
        [$held, $deleted] = [$this->projectRecord($u0001), $this->projectRecord($u0001)];
        $this->applyFromRecord($u0001, $held->id);
        $payload = $this->payload($deleted);
        app(DeleteRecord::class)->execute($this->contextOf($u0001), self::PROJECT, $deleted->id);
        $this->actingAs($u0001);

        $this->checkAs(held: false, moduleCode: null);
        $this->postJson($this->applyUrl(), $this->payload($held))
            ->assertStatus(422)->assertJsonPath('errors', ['record' => [RecordApplicationSteps::HELD]]);

        $this->checkAs(held: false, moduleCode: self::PROJECT);
        $this->postJson($this->applyUrl(), $payload)->assertNotFound();
    }

    /**
     * multipart の envelope の record_id・record_version は JSON の要求と同じ入力になり、添付が付く。
     */
    public function test_the_multipart_envelope_carries_the_record_and_the_files(): void
    {
        $this->fakeUploadDisk();
        $this->limitUploads();
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->actingAs($u0001);

        $files = [$this->uploadedFile('根拠.pdf')];
        $response = $this->multipart('POST', $this->applyUrl(), $this->payload($record), $files)
            ->assertStatus(302)->assertSessionHasNoErrors();

        $case = $this->caseOf($response);
        $this->assertSame($record->id, $case->record_id);
        $this->assertSame(['根拠.pdf'], $this->recordAttachmentNames($case->id));
    }

    /**
     * 一時保存のルートは record_id を捨てる(形も見ず、入口の 403 も新規の申請のまま = update の無い人も一時保存できる)。
     * 一時保存はレコードを指さない。
     */
    public function test_the_draft_route_drops_the_record_id(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $this->seededUser('u0003'));
        $this->actingAs($u0001);

        foreach ([$record->id, 'abc'] as $recordId) {
            $input = ['name' => '下書き', 'record_id' => $recordId, 'record_version' => $record->version()];
            $response = $this->postJson('/workflow/flows/'.self::FLOW_CODE.'/drafts', $input)->assertStatus(302);
            $draft = WorkflowCase::query()->findOrFail($this->idIn($response, '#/workflow/drafts/(\d+)/edit$#D'));
            $this->assertNull($draft->record_id);
            $this->assertArrayNotHasKey('record_id', $draft->draft_input ?? []);
        }
    }

    private function applyUrl(): string
    {
        return '/workflow/flows/'.self::FLOW_CODE.'/apply';
    }

    /**
     * 申請の画面が送る形(今の値に changes を重ね、明細の行は id つきで送る)。
     *
     * @param  array<string, mixed>  $changes  値の上書き(record_id・record_version は入力の上書き)
     * @return array<string, mixed>
     */
    private function payload(Record $record, array $changes = []): array
    {
        $inputs = array_intersect_key($changes, ['record_id' => true, 'record_version' => true]);
        $values = [...$this->projectInput()->record->values, ...array_diff_key($changes, $inputs)];
        $rows = array_map(
            fn (int $id) => ['id' => $id, 'values' => ['name' => '要件の整理', 'due_date' => '2026-11-30']],
            $this->detailRowIds($record->id),
        );

        return [
            'values' => $values,
            'details' => ['milestones' => $rows],
            'base_date' => $this->operationDay()->toDateString(),
            'record_id' => $record->id,
            'record_version' => $record->version(),
            ...$inputs,
        ];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function caseOf(TestResponse $response): WorkflowCase
    {
        return WorkflowCase::query()->findOrFail($this->idIn($response, '#/workflow/cases/(\d+)$#D'));
    }

    /**
     * 応答の移り先(Location)の id(新しく作った案件の id は送る前に分からない)。
     *
     * @param  TestResponse<Response>  $response
     */
    private function idIn(TestResponse $response, string $pattern): int
    {
        $location = (string) $response->headers->get('Location');

        if (preg_match($pattern, $location, $matches) !== 1) {
            $this->fail("リダイレクト先の形が違う: {$location}");
        }

        return (int) $matches[1];
    }
}
