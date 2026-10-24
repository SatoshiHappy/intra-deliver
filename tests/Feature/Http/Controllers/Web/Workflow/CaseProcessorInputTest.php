<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\HoldCase;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\ReleaseCase;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\TransferCase;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Notice\Notice;
use App\Queries\Workflow\CaseActor;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 処理の操作の入力 principal_user_id・contact(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、
 * 画面/案件の画面.md の 5 節、案件の操作/操作.md の 10.5 節): 9 つの操作のそれぞれで、入口が処理担当者と連絡を業務処理へ渡す
 * (連絡する人は整数に変えて {@see CaseContact} を作る)。形の誤りは入口の 422 で、キーは画面が欄の下に出すキー。中身の規則は業務処理の 422。
 * 初期データの見本(初期データ.md の 4 節)を使い、ルートの認可を外さない。
 */
final class CaseProcessorInputTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    /** 画面(Inertia の router)が送る要求の印 */
    private const INERTIA = ['X-Inertia' => 'true'];

    /** 業務処理へ渡ったかを見る処理担当者(入口は形だけを見る) */
    private const PRINCIPAL = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function operations(): array
    {
        return [
            'approve' => ['approve', ApproveCase::class],
            'approve early' => ['approve-early', ApproveCaseEarly::class],
            'reject' => ['reject', RejectCase::class],
            'send back' => ['send-back', SendBackCase::class],
            'pull back' => ['pull-back', PullBackCase::class],
            'withdraw' => ['withdraw', WithdrawCase::class],
            'hold' => ['hold', HoldCase::class],
            'release' => ['release', ReleaseCase::class],
            'transfer' => ['transfer', TransferCase::class],
        ];
    }

    /**
     * 処理担当者と、数字の文字列を整数に変えた連絡が、業務処理の引数(処理担当者の直後が連絡)に渡る。
     *
     * @param  class-string  $operation
     */
    #[DataProvider('operations')]
    public function test_the_principal_and_the_contact_reach_the_operation(string $route, string $operation): void
    {
        $case = $this->caseNumbered(2);
        $received = [];
        $this->mock($operation, function (MockInterface $mock) use (&$received, $case): void {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function (mixed ...$args) use (&$received, $case) {
                $received = $args;

                return new CaseOperationResult($case, WorkflowAction::Approve, 0, [], null, CaseActor::of(1));
            });
        });
        $this->actingAsUser('u0001');
        $contact = ['user_ids' => ['5', 7], 'message' => 'ひと言'];

        $this->post("/workflow/cases/{$case}/{$route}", [...$this->input($case), 'contact' => $contact], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors();

        $index = array_key_first(array_filter($received, fn (mixed $arg) => $arg instanceof CaseContact));
        $this->assertIsInt($index);
        $expected = [self::PRINCIPAL, new CaseContact([5, 7], 'ひと言')];
        $this->assertEquals($expected, [$received[$index - 1], $received[$index]]);
    }

    /**
     * 連絡する人を数字の文字列で送っても、業務処理が整数の id で比べて連絡の通知を作る。
     */
    public function test_contact_users_sent_as_numeric_strings_pass_as_integers(): void
    {
        $case = $this->caseNumbered(3);
        $lastId = (int) Notice::query()->max('id');
        $contact = ['user_ids' => [(string) $this->userIdOf('u0005')], 'message' => 'ご確認ください。'];

        $this->hold('u0003', $case, ['contact' => $contact])->assertStatus(302)->assertSessionHasNoErrors();

        $this->assertSame([$this->userIdOf('u0005')], $this->contactRecipientsSince($lastId));
    }

    public function test_an_operation_without_the_contact_sends_no_contact(): void
    {
        $case = $this->caseNumbered(3);
        $lastId = (int) Notice::query()->max('id');

        $this->hold('u0003', $case)->assertStatus(302)->assertSessionHasNoErrors();

        $this->assertSame([], $this->contactRecipientsSince($lastId));
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function malformedInputs(): array
    {
        $users = 'contact.user_ids';
        $usersMessage = '連絡する人の指定が正しくありません';

        return [
            'a contact user that is not numeric' => [['contact' => ['user_ids' => ['abc']]], $users, $usersMessage],
            'a contact user that is a decimal' => [['contact' => ['user_ids' => [1.5]]], $users, $usersMessage],
            'a contact user that is a boolean' => [['contact' => ['user_ids' => [true]]], $users, $usersMessage],
            'contact users that are not a list' => [['contact' => ['user_ids' => ['a' => 1]]], $users, $usersMessage],
            'contact users that are a string' => [['contact' => ['user_ids' => '12']], $users, $usersMessage],
            'a contact that is not an object' => [['contact' => 'abc'], $users, $usersMessage],
            'a message that is not a string' => [
                ['contact' => ['user_ids' => [], 'message' => ['x']]], 'contact.message', 'ひと言の形が正しくありません',
            ],
            'a principal that is not an integer' => [
                ['principal_user_id' => 'abc'], 'principal_user_id', '処理担当者の指定が正しくありません',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('malformedInputs')]
    public function test_a_malformed_input_is_unprocessable_at_its_key(
        array $overrides,
        string $key,
        string $message,
    ): void {
        $case = $this->caseNumbered(3);
        $this->actingAsUser('u0003');

        $this->postJson("/workflow/cases/{$case}/hold", [...$this->input($case), ...$overrides])
            ->assertUnprocessable()->assertJsonValidationErrors([$key => $message]);
    }

    public function test_a_transfer_target_that_is_not_an_integer_is_unprocessable(): void
    {
        $case = $this->caseNumbered(3);
        $this->actingAsUser('u0003');

        $this->postJson("/workflow/cases/{$case}/transfer", [...$this->input($case), 'to_user_id' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors(['to_user_id' => '担当変更先の指定が正しくありません']);
    }

    /**
     * 処理のダイアログが送る形(今の改訂番号・現在のノード・差戻し先・振替先と処理担当者)。
     *
     * @return array<string, mixed>
     */
    private function input(int $caseId): array
    {
        $node = $this->currentNodeIdOf($caseId);

        return [
            'revision' => $this->revisionOf($caseId), 'node_id' => $node, 'send_back_node_id' => $node,
            'to_user_id' => $this->userIdOf('u0004'), 'comment' => null, 'principal_user_id' => self::PRINCIPAL,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function hold(string $user, int $caseId, array $overrides = []): TestResponse
    {
        $this->actingAsUser($user);
        $input = [...$this->input($caseId), 'principal_user_id' => null, ...$overrides];

        return $this->post("/workflow/cases/{$caseId}/hold", $input, self::INERTIA);
    }

    /**
     * $lastId より後の連絡の通知の宛先(id の昇順)。
     *
     * @return list<int>
     */
    private function contactRecipientsSince(int $lastId): array
    {
        return array_values(array_map('intval', Notice::query()->where('id', '>', $lastId)
            ->where('type', WorkflowNoticeType::Contact->value)->orderBy('user_id')->pluck('user_id')->all()));
    }
}
