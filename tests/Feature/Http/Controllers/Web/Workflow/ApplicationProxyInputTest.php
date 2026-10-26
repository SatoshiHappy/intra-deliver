<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AppliesFromRecords;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 申請・一時保存・再申請の入力 applicant_user_id・principal_user_id(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、
 * 案件の運用/案件の操作/操作.md の 10.8 節): 入口が業務処理へ渡し、申請者・持ち主・履歴の処理者と実行者が代理元になる。形の誤りは入口の 422、
 * 代理元でないは業務処理の 422。一時保存の上書き・一時保存からの申請は申請者を読まない。代理元 A = u0009、申請の代理先 B = u0002。
 */
final class ApplicationProxyInputTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use RegistersProxies;

    private int $flowId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->flowId = $this->createFlow(approvals: [[$this->userCondition('u0003')]]);
    }

    /**
     * 新規の申請と新規の一時保存: null なら自分として、代理元の id なら代理として書く。
     */
    public function test_the_applicant_reaches_the_new_application_and_the_new_draft(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        $this->actingAs($b);

        $own = $this->caseIn($this->post($this->applyUrl(), $this->newPayload(null))->assertStatus(302));
        $proxied = $this->caseIn($this->post($this->applyUrl(), $this->newPayload($a->id))->assertStatus(302));
        $draft = $this->caseIn($this->post($this->draftsUrl(), $this->newPayload($a->id))->assertStatus(302));

        $this->assertSame([$b->id, [['apply', $b->id, null]]], [$own->user_id, $this->histories($own)]);
        $this->assertSame([$a->id, [['apply', $a->id, $b->id]]], [$proxied->user_id, $this->histories($proxied)]);
        $this->assertSame(['draft', $a->id], [$draft->state->value, $draft->user_id]);
    }

    /**
     * 既存のデータからの申請も、作成者が代理元のレコードを代理元として申請できる。
     */
    public function test_the_applicant_reaches_the_application_from_a_record(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        $record = $this->projectRecord($a);
        $this->actingAs($b);

        $case = $this->caseIn($this->post($this->applyUrl(), $this->recordPayload($record, $a->id))->assertStatus(302));

        $this->assertSame([$record->id, $a->id], [$case->record_id, $case->user_id]);
        $this->assertSame([['apply', $a->id, $b->id]], $this->histories($case));
    }

    /**
     * 代理元でない id は業務処理の 422 applicant_user_id、数字でない値は入口の 422(どちらも何も書かない)。
     */
    public function test_a_wrong_applicant_is_unprocessable(): void
    {
        [, $b] = $this->people();
        $nonPrincipal = $this->seededUser('u0011');
        $this->actingAs($b);
        $counts = $this->workflowCounts();

        foreach ([$this->applyUrl(), $this->draftsUrl()] as $url) {
            $this->postJson($url, $this->newPayload($nonPrincipal->id))->assertUnprocessable()
                ->assertJsonValidationErrors(['applicant_user_id' => CaseOperationErrors::APPLICANT_PRINCIPAL]);
            $this->postJson($url, [...$this->newPayload(null), 'applicant_user_id' => 'abc'])->assertUnprocessable()
                ->assertJsonValidationErrors(['applicant_user_id' => '申請者の指定が正しくありません']);
        }

        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 一時保存の上書きと一時保存からの申請に applicant_user_id を送っても読まない(持ち主のまま)。
     */
    public function test_the_draft_writes_do_not_read_the_applicant(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        $draft = $this->draftOf($b);
        $this->actingAs($b);

        $this->put("/workflow/drafts/{$draft}", $this->draftPayload($draft, $a->id))
            ->assertStatus(302)->assertSessionHasNoErrors();
        $owner = WorkflowCase::query()->findOrFail($draft)->user_id;
        $this->post("/workflow/drafts/{$draft}/apply", $this->draftPayload($draft, $a->id))
            ->assertStatus(302)->assertSessionHasNoErrors();
        $applied = WorkflowCase::query()->findOrFail($draft);

        $this->assertSame([$b->id, $b->id], [$owner, $applied->user_id]);
        $this->assertSame([['apply', $b->id, null]], $this->histories($applied));
    }

    /**
     * 再申請の principal_user_id: null なら自分として、代理元(案件の申請者)なら代理として再申請する。代理元でない id は業務処理の 422。
     */
    public function test_the_principal_reaches_the_reapplication(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        [$own, $principals] = [$this->sentBackOf($b), $this->sentBackOf($a)];
        $this->actingAs($b);

        $nonPrincipal = $this->seededUser('u0011')->id;
        $this->postJson($this->reapplyUrl($principals), $this->reapplyPayload($principals, $nonPrincipal))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_user_id' => CaseOperationErrors::PRINCIPAL]);
        $this->post($this->reapplyUrl($own), $this->reapplyPayload($own, null))
            ->assertRedirect("/workflow/cases/{$own->id}");
        $this->post($this->reapplyUrl($principals), $this->reapplyPayload($principals, $a->id))
            ->assertRedirect("/workflow/cases/{$principals->id}");

        $this->assertSame(['reapply', $b->id, null], $this->histories($own)[2]);
        $this->assertSame(['reapply', $a->id, $b->id], $this->histories($principals)[2]);
    }

    /**
     * 連絡は申請・既存のデータからの申請・一時保存からの申請・再申請のそれぞれで業務処理へ渡り、選んだ人へ連絡の通知ができる。
     */
    public function test_the_contact_reaches_every_application_route(): void
    {
        [, $b] = $this->people();
        $recipient = $this->seededUser('u0012');
        $contact = ['user_ids' => [$recipient->id], 'message' => 'ご確認ください。'];
        [$draft, $sentBack, $record] = [$this->draftOf($b), $this->sentBackOf($b), $this->projectRecord($b)];
        $this->actingAs($b);
        $with = fn (array $payload) => [...$payload, 'contact' => $contact];
        $routes = [
            'apply' => fn () => $this->post($this->applyUrl(), $with($this->newPayload(null))),
            'record' => fn () => $this->post($this->applyUrl(), $with($this->recordPayload($record, null))),
            'draft' => fn () => $this->post(
                "/workflow/drafts/{$draft}/apply", $with($this->draftPayload($draft, null)),
            ),
            'reapply' => fn () => $this->post(
                $this->reapplyUrl($sentBack), $with($this->reapplyPayload($sentBack, null)),
            ),
        ];

        foreach ($routes as $label => $send) {
            $lastId = (int) Notice::query()->max('id');
            $send()->assertStatus(302)->assertSessionHasNoErrors();
            $this->assertSame([$recipient->id], $this->contactRecipientsSince($lastId), $label);
        }
    }

    /**
     * 連絡の形(1 経路): 数字の文字列は整数で通り、形の誤りは 422 contact.user_ids・contact.message、無ければ連絡なし。
     * 一時保存の作成・上書きは contact を読まない(形が誤っていても通り、通知も無い)。
     */
    public function test_the_contact_shape_is_checked_only_where_the_contact_is_read(): void
    {
        [, $b] = $this->people();
        $recipient = $this->seededUser('u0012');
        $draft = $this->draftOf($b);
        $this->actingAs($b);
        $lastId = (int) Notice::query()->max('id');
        $new = fn (array $contact) => [...$this->newPayload(null), 'contact' => $contact];

        $this->post($this->applyUrl(), $new(['user_ids' => [(string) $recipient->id]]))
            ->assertStatus(302)->assertSessionHasNoErrors();
        $stringIds = $this->contactRecipientsSince($lastId);
        $this->postJson($this->applyUrl(), $new(['user_ids' => ['abc']]))->assertUnprocessable()
            ->assertJsonValidationErrors(['contact.user_ids' => '連絡する人の指定が正しくありません']);
        $this->postJson($this->applyUrl(), $new(['user_ids' => [], 'message' => ['x']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['contact.message' => 'ひと言の形が正しくありません']);
        $lastId = (int) Notice::query()->max('id');
        $this->post($this->applyUrl(), $this->newPayload(null))->assertStatus(302)->assertSessionHasNoErrors();
        $malformed = ['contact' => ['user_ids' => ['abc'], 'message' => ['x']]];
        $this->post($this->draftsUrl(), [...$this->newPayload(null), ...$malformed])
            ->assertStatus(302)->assertSessionHasNoErrors();
        $this->put("/workflow/drafts/{$draft}", [...$this->draftPayload($draft, null), ...$malformed])
            ->assertStatus(302)->assertSessionHasNoErrors();

        $this->assertSame([[$recipient->id], []], [$stringIds, $this->contactRecipientsSince($lastId)]);
    }

    /**
     * @return array{User, User} [代理元 A, 代理先 B]
     */
    private function people(): array
    {
        return [$this->seededUser('u0009'), $this->seededUser('u0002')];
    }

    private function draftOf(User $owner): int
    {
        $input = new DraftInput('下書き', null, [], []);

        return app(CreateDraft::class)->execute($this->contextOf($owner), self::FLOW_CODE, $input);
    }

    private function sentBackOf(User $applicant): WorkflowCase
    {
        $case = $this->applyAs($applicant);
        $this->sendBack($this->seededUser('u0003'), $case, 'apply');

        return $case->refresh();
    }

    private function applyUrl(): string
    {
        return '/workflow/flows/'.self::FLOW_CODE.'/apply';
    }

    private function draftsUrl(): string
    {
        return '/workflow/flows/'.self::FLOW_CODE.'/drafts';
    }

    private function reapplyUrl(WorkflowCase $case): string
    {
        return "/workflow/cases/{$case->id}/reapply";
    }

    /**
     * @return array<string, mixed>
     */
    private function newPayload(?int $applicantUserId): array
    {
        $baseDate = $this->operationDay()->toDateString();

        return [...$this->valuesPayload(), 'base_date' => $baseDate, 'applicant_user_id' => $applicantUserId];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(int $caseId, ?int $applicantUserId): array
    {
        $revision = WorkflowCase::query()->findOrFail($caseId)->revision;

        return [...$this->newPayload($applicantUserId), 'revision' => $revision];
    }

    /**
     * @return array<string, mixed>
     */
    private function reapplyPayload(WorkflowCase $case, ?int $principalUserId): array
    {
        $revision = $this->revisionOf($case);

        return [...$this->valuesPayload(), 'revision' => $revision, 'principal_user_id' => $principalUserId];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPayload(Record $record, ?int $applicantUserId): array
    {
        $rows = array_map(
            fn (int $id) => ['id' => $id, 'values' => ['name' => '要件の整理', 'due_date' => '2026-11-30']],
            $this->detailRowIds($record->id),
        );

        return [
            ...$this->newPayload($applicantUserId), 'details' => ['milestones' => $rows],
            'record_id' => $record->id, 'record_version' => Record::query()->findOrFail($record->id)->version(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valuesPayload(): array
    {
        $rows = [['values' => ['name' => '要件の整理', 'due_date' => '2026-11-30']]];

        return ['values' => $this->projectInput()->record->values, 'details' => ['milestones' => $rows]];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function caseIn(TestResponse $response): WorkflowCase
    {
        $location = (string) $response->headers->get('Location');
        $matched = preg_match('#/workflow/(?:cases|drafts)/(\d+)(?:/edit)?$#D', $location, $matches) === 1;

        return $matched ? WorkflowCase::query()->findOrFail((int) $matches[1]) : $this->fail("移り先の形が違う: {$location}");
    }

    /**
     * @return list<array{string, int, int|null}> 履歴の行の [種別, 処理者, 実行者](id の順)
     */
    private function histories(WorkflowCase $case): array
    {
        $rows = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')->get();

        return array_values($rows->map(fn (WorkflowCaseHistory $row) => [
            $row->action->value, $row->user_id, $row->executor_user_id,
        ])->all());
    }

    /**
     * @return list<int> $lastId より後の連絡の通知の宛先
     */
    private function contactRecipientsSince(int $lastId): array
    {
        return array_values(array_map('intval', Notice::query()->where('id', '>', $lastId)
            ->where('type', WorkflowNoticeType::Contact->value)->orderBy('user_id')->pluck('user_id')->all()));
    }
}
