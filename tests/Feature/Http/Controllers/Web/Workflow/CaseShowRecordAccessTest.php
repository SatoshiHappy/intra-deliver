<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\RecordRevisionWriter;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseShowProps;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Support\Header;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 申請の詳細のレコードの中身(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2 節・6 節、検査 = 8 節の「完了の後の見え方」):
 * 申請を閲覧できる人には、完了の前も後も同じ中身(詳細タブ・参照制限・関連レコード・サイドパネル)が出る。見本の WF-000001(完了。申請者 u0001 =
 * 作成者、承認 u0002)と WF-000005(未完了。課長 u0002 が承認待ちで、前に処理した履歴の行も持つ)。申請書のレコードは参照制限の既定が「する」なので、
 * u0002 は業務モジュールの read があっても行の判定を通らない(申請の経路だけで見る人)。read を外した形も確かめる。
 */
final class CaseShowRecordAccessTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesSeededCases;
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 未完了の申請の課長は申請の経路だけで見る人で、詳細タブの今の値に参照制限の今の値 rowAccess が載る(レコードの詳細の record と同じ形)。
     */
    public function test_an_approver_of_an_active_case_sees_the_record_through_the_application(): void
    {
        $case = $this->caseOf(5);
        $this->actingAsUser('u0002');

        $props = $this->props($this->get($this->url($case)));

        $this->assertSame($case->record_id, $props['content']['record']['id'] ?? null);
        $rowAccess = $props['content']['record']['rowAccess'] ?? null;
        $this->assertSame(['restricted', 'viewers'], array_keys($rowAccess ?? []));
        $this->assertTrue($rowAccess['restricted'] ?? null);
        $this->assertContains('comments', array_column($props['panel']['items'] ?? [], 'key'));
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function moduleReads(): array
    {
        return ['read はあるが行の判定を通らない' => [true], 'read が無い' => [false]];
    }

    /**
     * 申請が完了しても、申請の関係者の詳細タブ・参照制限・関連レコード・サイドパネル(帯と開いた欄)は未完了の間と同じ(全体の応答と、欄ごとの
     * 部分の再読み込み)。完了の後に書いたコメントも出る。
     */
    #[DataProvider('moduleReads')]
    public function test_a_participant_sees_the_same_record_after_the_case_is_completed(bool $read): void
    {
        $case = $this->caseOf(5);
        $application = RecordModule::query()->where('code', $this->moduleCodeOf($case))->sole();
        $this->referenceField($this->recordModule('case_notes'), 'plan', $application);
        if (! $read) {
            $this->deny($this->moduleCodeOf($case), RecordModuleAction::Read);
        }
        $this->actingAsUser('u0002');
        $before = $this->recordLayerOf($case);

        $this->complete($case);
        $after = $this->recordLayerOf($case);

        $this->assertNotSame([], $before['full']['relatedRecords']['entries'] ?? []);
        $this->assertNotSame([], $before['full']['panel']['items'] ?? []);
        $this->assertSame($before, $after);
        $url = "/records/data/{$this->moduleCodeOf($case)}/{$case->record_id}/comments";
        $this->from($this->url($case))->post($url, ['body' => '完了の後のコメント'])->assertStatus(303);
        $comments = $this->recordLayerOf($case)['comments']['rows'] ?? [];
        $this->assertContains('完了の後のコメント', array_column($comments, 'body'));
    }

    /**
     * 完了の後も詳細タブは今の値で、項目名も今の名前(申請した時点の名前は更新履歴で分かる。テストで持ち主の規則を通らずに値と名前を変える)。
     */
    public function test_a_completed_case_shows_the_current_values_and_names(): void
    {
        $case = $this->caseOf(1);
        $budget = $this->fieldOf($case, 'budget');
        $this->rewrite($case, $budget, 987_654_321);
        $budget->update(['name' => '今の名前の予算']);
        $this->actingAsUser('u0002');

        $props = $this->props($this->get($this->url($case)));

        $this->assertSame(987_654_321, $props['content']['record']['values']['budget'] ?? null);
        $this->assertStringContainsString('今の名前の予算', (string) json_encode($props['content'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 申請の詳細の props は申請の層かレコードの層のどれかに載る(表に無い props を足すと落ちる。全部の画面に共有の props は除く)。未完了と完了で
     * 同じ名前が並ぶ(完了の後だけの props は無い)。
     */
    public function test_every_prop_of_the_page_is_in_the_layers(): void
    {
        $expected = [
            ...CaseShowProps::CASE_LAYER,
            ...array_diff(CaseShowProps::RECORD_LAYER, CaseShowProps::OPTIONAL),
        ];
        sort($expected);

        foreach ([['u0002', 5], ['u0002', 1]] as [$user, $number]) {
            $this->actingAsUser($user);
            $keys = array_keys($this->props($this->get($this->url($this->caseOf($number)))));
            sort($keys);

            $this->assertSame($expected, $keys, "{$user} WF-{$number}");
        }
    }

    /**
     * 申請の経路だけで見る u0002 の申請の詳細のサイドパネルの添付と更新履歴の行に、作成者 u0001 がレコードの詳細で見る行と同じ URL が出る
     * (期限で実体を消した行は開けず null)。
     */
    public function test_the_side_panel_rows_carry_the_same_urls_as_the_record_detail(): void
    {
        $case = $this->caseOf(5);
        $record = Record::query()->findOrFail($case->record_id);
        $attach = fn (bool $purged) => RecordAttachment::factory()->create([
            'record_module_id' => $record->record_module_id, 'record_id' => $record->id,
            'upload_id' => ($purged ? Upload::factory()->purged() : Upload::factory())->create()->id,
        ]);
        $removed = [$attach(false), $attach(true)];
        app(RecordRevisionWriter::class)->write($record->id, RecordRevisionAction::Update, null, null);
        array_map(fn (RecordAttachment $attachment) => $attachment->delete(), $removed);
        app(RecordRevisionWriter::class)->write($record->id, RecordRevisionAction::Update, null, null);
        $attach(false);
        $attach(true);
        $detail = "/records/data/{$this->moduleCodeOf($case)}/{$record->id}";

        foreach (['attachments', 'revisions'] as $panel) {
            $this->actingAsUser('u0001');
            $full = $this->get("{$detail}?panel={$panel}");
            $expected = self::linksOf($panel, $this->props($full)['panelContent']['rows']);
            $this->actingAsUser('u0002');
            $partial = $this->partial($case, 'panelContent', ['panel' => $panel]);
            $actual = self::linksOf($panel, $this->props($partial)['panelContent']['rows']);

            $this->assertSame($expected, $actual, $panel);
            $this->assertContains(null, array_column($actual, 1), $panel);
            $this->assertNotSame([null], array_values(array_unique(array_column($actual, 1))), $panel);
        }
    }

    /**
     * 添付の欄の行か、更新履歴の欄の行の添付の変化の、(id か変化の種別, ダウンロードの URL, プレビューの URL)。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{mixed, mixed, mixed}>
     */
    private static function linksOf(string $panel, array $rows): array
    {
        $links = [];

        foreach ($rows as $row) {
            $items = $panel === 'attachments' ? [$row] : $row['changes']['attachments'] ?? [];

            foreach (is_array($items) ? $items : [] as $item) {
                $links[] = [$item['change'] ?? $item['id'], $item['downloadUrl'], $item['previewUrl']];
            }
        }

        return $links;
    }

    private function caseOf(int $number): WorkflowCase
    {
        return WorkflowCase::query()->findOrFail($this->caseNumbered($number));
    }

    private function url(WorkflowCase $case): string
    {
        return "/workflow/cases/{$case->id}";
    }

    private function moduleCodeOf(WorkflowCase $case): string
    {
        $record = Record::query()->findOrFail($case->record_id);

        return RecordModule::query()->findOrFail($record->record_module_id)->code;
    }

    private function fieldOf(WorkflowCase $case, string $code): RecordField
    {
        $record = Record::query()->findOrFail($case->record_id);

        return RecordField::query()->where('record_module_id', $record->record_module_id)->where('code', $code)->sole();
    }

    /**
     * 申請を完了させる(承認。処理の業務処理を通さずに状態だけを変える)。
     */
    private function complete(WorkflowCase $case): void
    {
        WorkflowCase::query()->whereKey($case->id)->update([
            'state' => WorkflowCaseState::Completed,
            'completion_reason' => WorkflowCompletionReason::Approved,
            'completed_at' => now(),
        ]);
    }

    /**
     * レコードの層(全体の応答の分と、関連レコードのタブとサイドパネルの欄ごとの部分の再読み込みの分)。
     *
     * @return array<string, mixed>
     */
    private function recordLayerOf(WorkflowCase $case): array
    {
        $layer = ['full' => array_intersect_key(
            $this->props($this->get($this->url($case))),
            array_flip(CaseShowProps::RECORD_LAYER),
        )];
        $layer['relatedRecordRows'] = $this->props($this->partial($case, 'relatedRecordRows', ['tab' => 'related']));

        foreach (['attachments', 'comments', 'revisions'] as $panel) {
            $layer[$panel] = $this->props($this->partial($case, 'panelContent', ['panel' => $panel]))['panelContent'];
        }

        return $layer;
    }

    /**
     * 持ち主の規則を通らずにレコードの値を書き換える(版を作らない)。
     */
    private function rewrite(WorkflowCase $case, RecordField $field, mixed $value): void
    {
        $record = Record::query()->findOrFail($case->record_id);
        $values = $record->field_values ?? [];
        $values[$field->id] = $value;
        Record::query()->whereKey($record->id)->toBase()->update(['field_values' => json_encode($values)]);
    }

    /**
     * 全体の応答の props はどれも、その名前だけを求めた部分の再読み込みで返る(申請の層の props を
     * {@see CaseShowProps::DETAIL_LAYER} に足し忘れると落ちる)。
     */
    public function test_every_prop_of_the_full_response_comes_back_on_its_own_partial_reload(): void
    {
        $case = $this->caseOf(5);
        $this->actingAsUser('u0002');

        foreach (array_keys($this->props($this->get($this->url($case)))) as $key) {
            $this->assertArrayHasKey($key, $this->props($this->partial($case, $key, [])), $key);
        }
    }

    /**
     * 部分の再読み込み(Inertia の画面が送る見出し)。
     *
     * @param  array<string, string>  $query
     * @return TestResponse<Response>
     */
    private function partial(WorkflowCase $case, string $only, array $query): TestResponse
    {
        return $this->get($this->url($case).'?'.http_build_query($query), [
            Header::PARTIAL_COMPONENT => 'Workflow/CaseShow',
            Header::PARTIAL_ONLY => $only,
        ]);
    }

    /**
     * ページの props から、全部の画面に共有の props を除いたもの。
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();
        $page = $response->viewData('page');

        return array_diff_key($page['props'], Inertia::getShared());
    }
}
