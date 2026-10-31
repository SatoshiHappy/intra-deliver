<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Records\Record;
use App\Models\Records\RecordRowAccessViewer;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * ワークフローの入口は参照制限の 2 つのキーを読まない(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 2 節の入口、
 * 申請対象からの申請.md 11 節の「10.1 の入口の参照制限のキー」のうち送る値の表に依らない組): 新規の申請・一時保存からの申請・再申請に
 * row_access_restricted: false を送っても、既定が「する」の申請書の業務モジュールの登録と、チェックの付いた更新ではチェックが付いたまま。
 * 空でない row_access_viewers を送っても参照者は変わらない(登録は空、更新は今のまま)。形の誤った値でも 422 にならない。一時保存の新規と上書きの
 * 下書きに 2 つのキーは入らない。初期データ(案件の見本)を入れ、要求は画面と同じ Inertia の要求で送る。そのものを申請する・申請対象からの申請 =
 * {@see RecordApplicationRowAccessKeysTest}。
 */
final class WorkflowRowAccessKeysTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    private const INERTIA = ['X-Inertia' => 'true'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow(SeedingDates::BASE.' 03:00:00 UTC');
    }

    /**
     * 送る参照制限のキーの組(チェックを外す・チェックのキーなしで参照者・チェック付きで参照者・形の誤った値)。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rowAccessKeys(): array
    {
        return [
            'チェックを外す' => [['row_access_restricted' => false]],
            'チェックのキーなしで参照者' => [['row_access_viewers' => [['type' => 'user', 'id' => 1]]]],
            'チェック付きで参照者' => [
                ['row_access_restricted' => true, 'row_access_viewers' => [['type' => 'user', 'id' => 1]]],
            ],
            '形の誤った値' => [['row_access_restricted' => 'x', 'row_access_viewers' => 'x']],
        ];
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    #[DataProvider('rowAccessKeys')]
    public function test_a_new_application_keeps_the_default_and_no_viewers(array $keys): void
    {
        $this->actingAsUser('u0001');
        $body = [
            'name' => '', 'comment' => null, 'values' => $this->planValues('新しい計画'), 'base_date' => $this->baseDate(),
        ];

        $response = $this->post('/workflow/flows/project_plan/apply', [...$body, ...$keys], self::INERTIA);

        $response->assertStatus(302)->assertSessionHasNoErrors();
        $case = WorkflowCase::query()->findOrFail($this->caseIdIn($response, '#/workflow/cases/(\d+)$#D'));
        $this->assertSame([true, []], $this->rowAccessOf((int) $case->record_id));
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    #[DataProvider('rowAccessKeys')]
    public function test_the_drafts_do_not_keep_the_keys_and_an_application_from_a_draft_keeps_the_default(
        array $keys,
    ): void {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();
        $new = ['name' => '下書き', 'comment' => null, 'values' => ['name' => '下書き']];
        $created = $this->post('/workflow/flows/project_plan/drafts', [...$new, ...$keys], self::INERTIA);
        $created->assertStatus(302)->assertSessionHasNoErrors();
        $newDraft = $this->caseIdIn($created, '#/workflow/drafts/(\d+)/edit$#D');
        $overwrite = [
            'revision' => $this->revisionOf($draft), 'name' => '課題の対応の承認 様式の変更', 'comment' => null,
            'values' => ['title' => '様式の変更'],
        ];
        $this->put("/workflow/drafts/{$draft}", [...$overwrite, ...$keys], self::INERTIA)
            ->assertStatus(303)->assertSessionHasNoErrors();

        foreach ([$newDraft, $draft] as $id) {
            $input = WorkflowCase::query()->findOrFail($id)->draft_input;
            $this->assertStringNotContainsString('row_access', (string) json_encode($input));
        }

        $apply = [
            'revision' => $this->revisionOf($draft), 'name' => '', 'comment' => null,
            'values' => $this->issueValues('請求書の様式の変更'), 'base_date' => $this->baseDate(),
        ];
        $this->post("/workflow/drafts/{$draft}/apply", [...$apply, ...$keys], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertSame([true, []], $this->rowAccessOf((int) WorkflowCase::query()->findOrFail($draft)->record_id));
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    #[DataProvider('rowAccessKeys')]
    public function test_a_reapplication_keeps_the_check_and_the_current_viewers(array $keys): void
    {
        $this->actingAsUser('u0001');
        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(4));
        $recordId = (int) $case->record_id;
        $viewer = $this->userIdOf('u0005');
        RecordRowAccessViewer::query()->create(['record_id' => $recordId, 'user_id' => $viewer, 'sort_key' => 0]);
        $body = [
            'revision' => $this->revisionOf($case->id), 'name' => '', 'comment' => null,
            'values' => $this->planValuesOfCase4(['budget' => 120]),
        ];

        $this->post("/workflow/cases/{$case->id}/reapply", [...$body, ...$keys], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors();

        $this->assertSame([true, [$viewer]], $this->rowAccessOf($recordId));
    }

    /**
     * レコードのチェックと参照者のユーザーの id(sort_key の順。行が無ければ空)。
     *
     * @return array{bool, list<int|null>}
     */
    private function rowAccessOf(int $recordId): array
    {
        $record = Record::query()->findOrFail($recordId);

        return [$record->row_access_restricted, array_values($record->rowAccessViewers()->pluck('user_id')->all())];
    }
}
