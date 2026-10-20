<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Http\Requests\Records\RecordInputRequest;
use App\Http\Requests\Workflow\DraftApplicationRequest;
use App\Http\Requests\Workflow\FlowApplicationRequest;
use App\Http\Requests\Workflow\ReapplicationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 申請・一時保存・再申請の封筒(docs の 設計/ワークフロー/業務データとの接続/README.md 2 節・9 節): 同じ封筒(配列の欠落・値の無い行を含む)を業務データの入れ物の入口と
 * ワークフローの入口に送ると、誤りのキー・文言と作るレコードの入力が同じ。一時保存の入力は明細の行の id を捨てる。
 */
final class ApplicationEnvelopeTest extends TransactionalTestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function envelopes(): array
    {
        return [
            'missing arrays' => [[]],
            'an empty row and ids' => [['values' => ['name' => '計画', 'budget' => '10'], 'details' => ['milestones' => [[], ['id' => '7', 'values' => ['name' => '試行']], ['values' => []]]]]],
            'broken shapes' => [['values' => 'x', 'details' => ['milestones' => 'x']]],
            'broken rows' => [['details' => ['milestones' => [['id' => 'x', 'values' => 'x'], 'x']]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    #[DataProvider('envelopes')]
    public function test_the_workflow_entrances_give_the_same_errors_and_record_input_as_the_records_entrance(array $envelope): void
    {
        $records = $this->requestOf(RecordInputRequest::class, $envelope);
        $expectedErrors = $this->errorsOf($records, $envelope);

        foreach ([FlowApplicationRequest::class, DraftApplicationRequest::class, ReapplicationRequest::class] as $class) {
            $workflow = $this->requestOf($class, [...$envelope, 'revision' => 0]);

            $this->assertSame($expectedErrors, $this->errorsOf($workflow, [...$envelope, 'revision' => 0]), $class);

            if ($expectedErrors === []) {
                $this->assertEquals($records->recordInput(), $workflow->applicationInput()->record, $class);
            }
        }
    }

    public function test_the_draft_input_drops_the_row_ids(): void
    {
        $request = $this->requestOf(FlowApplicationRequest::class, [
            'name' => '案件名', 'comment' => null,
            'values' => ['name' => '計画'],
            'details' => ['milestones' => [['id' => 7, 'values' => ['name' => '試行']], []]],
        ]);
        $draft = $request->draftInput();

        $this->assertSame('案件名', $draft->name);
        $this->assertNull($draft->comment);
        $this->assertSame(['name' => '計画'], $draft->values);
        $this->assertSame(['milestones' => [['values' => ['name' => '試行']], ['values' => []]]], $draft->details);
    }

    /**
     * @template T of FormRequest
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $input
     * @return T
     */
    private function requestOf(string $class, array $input): FormRequest
    {
        return $class::create('/', 'POST', $input);
    }

    /**
     * 封筒の誤り(revision を除くキー → 文言)。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<string>>
     */
    private function errorsOf(RecordInputRequest|FlowApplicationRequest|DraftApplicationRequest|ReapplicationRequest $request, array $input): array
    {
        $errors = Validator::make($input, $request->rules(), $request->messages())->errors()->toArray();
        unset($errors['revision']);
        ksort($errors);

        return $errors;
    }
}
