<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Workflow\WorkflowCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * multipart の envelope の受け取り(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節・3.3 節、判定の順 =
 * 設計/ワークフロー/案件の運用/案件の操作/操作.md の 7 節)と要求の本文の上限(4 節): envelope の値の型・空の配列・値の無い行・キーの省略が
 * JSON の要求と同じ入力になる。壊れた envelope でも 404・403 が先で、見える人には 422 envelope だけを返す。本文の上限超えは 413・本文なし。
 */
final class WorkflowAttachmentEnvelopeTest extends TransactionalTestCase
{
    use BuildsUploads;
    use SendsAttachmentEnvelopes;
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    private const ENVELOPE_UNREADABLE = '送信の形が正しくありません';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    /**
     * 一時保存の入力は検証せず送られた形のまま保つので、型の違いがそのまま残る(業務データとの接続/申請と書き込み.md の 2 節)。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function envelopes(): array
    {
        return [
            'scalars of each type and blanks' => [[
                'name' => '  型の確認  ', 'comment' => '   ',
                'values' => [
                    'name' => ' 計画 ', 'budget' => 100, 'ratio' => 1.5, 'flag' => true, 'off' => false,
                    'none' => null, 'text' => '0',
                ],
            ]],
            'empty arrays and rows without values' => [[
                'values' => new stdClass,
                'details' => [
                    'milestones' => [new stdClass, ['values' => []], ['id' => 7, 'values' => ['name' => '試行']]],
                    'risks' => [],
                ],
            ]],
            'omitted keys' => [[]],
            'nested lists and a base date' => [
                ['base_date' => ' 2026-10-01 ', 'values' => ['tags' => ['a', '', ' b ', 2]]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    #[DataProvider('envelopes')]
    public function test_the_multipart_envelope_gives_the_same_draft_as_the_json_request(array $envelope): void
    {
        $this->actingAsUser('u0001');
        $uri = '/workflow/flows/project_plan/drafts';

        $json = $this->json('POST', $uri, $envelope, ['X-Inertia' => 'true'])
            ->assertStatus(302)->assertSessionHasNoErrors();
        $multipart = $this->multipart('POST', $uri, $envelope)->assertStatus(302)->assertSessionHasNoErrors();

        $expected = $this->draftInputIn($json);
        $this->assertSame($expected, $this->draftInputIn($multipart));
        $this->assertNotSame([], $expected);
    }

    public function test_the_types_survive_the_envelope(): void
    {
        $this->actingAsUser('u0001');
        $envelope = ['values' => ['budget' => 100, 'flag' => true, 'none' => null, 'blank' => ' ', 'list' => []]];

        $draft = $this->draftInputIn($this->multipart('POST', '/workflow/flows/project_plan/drafts', $envelope));

        $values = $draft['values'];
        ksort($values);
        $this->assertSame(['blank' => null, 'budget' => 100, 'flag' => true, 'list' => [], 'none' => null], $values);
    }

    public function test_a_broken_envelope_is_404_and_403_before_422_on_a_flow(): void
    {
        $broken = ['values' => 'x'];

        $this->multipartFields('POST', '/workflow/flows/no_such_flow/apply', $broken)->assertNotFound();
        $this->multipartFields('POST', '/workflow/flows/project_plan/apply', $broken)->assertForbidden();
        $this->actingAsUser('u0001');
        $this->multipartFields('POST', '/workflow/flows/no_such_flow/drafts', $broken)->assertNotFound();
        $count = WorkflowCase::query()->count();

        $page = '/workflow/flows/project_plan/apply';

        $this->multipartFields('POST', '/workflow/flows/project_plan/drafts', $broken, from: $page)
            ->assertRedirect($page)
            ->assertSessionHasErrors(['envelope' => self::ENVELOPE_UNREADABLE]);
        $this->assertSame(['envelope'], $this->sessionErrorKeys());
        $this->assertSame($count, WorkflowCase::query()->count());
    }

    public function test_a_broken_envelope_is_404_and_403_before_422_on_a_case(): void
    {
        $draft = $this->seededDraft();
        $this->actingAsUser('u0002');
        $this->multipartFields('PUT', "/workflow/drafts/{$draft}", [])->assertNotFound();
        $this->multipartFields('POST', '/workflow/cases/999999999/approve', [])->assertNotFound();

        $this->actingAsUser('u0001');
        $this->deny('project_issue', RecordModuleAction::Create);
        $this->multipartFields('PUT', "/workflow/drafts/{$draft}", [])->assertForbidden();
        $this->multipartFields('POST', "/workflow/drafts/{$draft}/apply", [])->assertForbidden();
        $this->multipartFields('POST', '/workflow/cases/'.$this->caseNumbered(4).'/reapply', [])
            ->assertSessionHasErrors(['envelope' => self::ENVELOPE_UNREADABLE]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unreadableEnvelopes(): array
    {
        return [
            'no envelope but the fields' => [['revision' => 0, 'comment' => 'x']],
            'an empty envelope' => [['envelope' => '']],
            'not json' => [['envelope' => '{revision: 0']],
            'a json list' => [['envelope' => '[0]']],
            'an empty json list' => [['envelope' => '[]']],
            'a json string' => [['envelope' => '"x"']],
            'json null' => [['envelope' => 'null']],
            'an array field' => [['envelope' => ['revision' => 0]]],
            'an object over the size cap' => [['envelope' => '{"comment":"'.str_repeat('x', 8_388_608).'"}']],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[DataProvider('unreadableEnvelopes')]
    public function test_an_unreadable_envelope_is_422_envelope_only(array $fields): void
    {
        $this->actingAsUser('u0002');
        $case = $this->caseNumbered(2);
        $revision = $this->revisionOf($case);

        $page = "/workflow/cases/{$case}";

        $this->multipartFields('POST', "{$page}/approve", $fields, [$this->uploadedFile('a.pdf')], $page)
            ->assertRedirect($page)
            ->assertSessionHasErrors(['envelope' => self::ENVELOPE_UNREADABLE]);

        $this->assertSame(['envelope'], $this->sessionErrorKeys());
        $this->assertSame($revision, $this->revisionOf($case));
        $this->assertSame([], $this->recordAttachmentNames($case));
    }

    public function test_a_body_over_the_post_max_size_is_413_without_a_body(): void
    {
        $max = ini_parse_quantity((string) ini_get('post_max_size'));
        $this->assertGreaterThan(0, $max, 'post_max_size に上限が無いと本文の上限超えを起こせない');
        $server = ['CONTENT_LENGTH' => (string) ($max + 1), 'CONTENT_TYPE' => 'multipart/form-data; boundary=x'];
        $this->actingAsUser('u0001');

        $uri = '/workflow/flows/project_plan/apply';

        $this->call('POST', $uri, [], [], [], [...$server, 'HTTP_X_INERTIA' => 'true'])
            ->assertStatus(413)->assertContent('');
        $this->call('POST', $uri, [], [], [], [...$server, 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)->assertContent('');
    }

    /**
     * 応答の移り先の一時保存の入力(draft_input)。
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function draftInputIn(TestResponse $response): array
    {
        $caseId = $this->caseIdIn($response, '#/workflow/drafts/(\d+)/edit$#D');

        return WorkflowCase::query()->findOrFail($caseId)->draft_input ?? $this->fail('一時保存の入力が無い');
    }
}
