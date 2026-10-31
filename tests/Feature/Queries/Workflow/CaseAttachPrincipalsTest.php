<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\WorkflowAttachmentLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesStepAttachments;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 詳細の nodeActions の principals の各選択肢の canAttach と attachmentLimits(docs の 設計/ワークフロー/申請の周辺/添付/README.md の
 * 1.1 節・5 節): 承認の系統の 7 つの操作それぞれで、業務モジュール × 案件に写したフローの値(今のフローの値は逆にする)× 操作するステップの
 * 設定の 8 通りが真偽を決める。保留解除だけ、処理者で読んだ状況(保留者側か)が加わる。値は業務処理の 422 と食い違わない。
 */
final class CaseAttachPrincipalsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesStepAttachments;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    /**
     * @return array<string, array{string, string, string}> [業務処理の名前, principals のキー, 案件の用意]
     */
    public static function operations(): array
    {
        return [
            '承認' => ['approve', 'approve', 'approve'],
            '承認終了' => ['approve_early', 'approveEarly', 'approve'],
            '否認' => ['reject', 'reject', 'approve'],
            '差戻し' => ['send_back', 'sendBack', 'approve'],
            '保留' => ['hold', 'hold', 'approve'],
            '担当変更' => ['transfer', 'transfer', 'approve'],
            '保留解除' => ['release', 'release', 'release'],
        ];
    }

    /**
     * 偽の組を先に、真の組を最後に流す(真の組は処理を実際に行うので案件が進む)。
     *
     * @return list<array{bool, bool, bool}> [業務モジュール, 写したフローの値, ステップが追加を止める]
     */
    private static function combinations(): array
    {
        $rows = [];

        foreach (range(0, 7) as $bits) {
            $rows[] = [($bits & 4) === 0, ($bits & 2) === 0, ($bits & 1) === 1];
        }

        usort($rows, fn (array $a, array $b) => self::allows(...$a) <=> self::allows(...$b));

        return $rows;
    }

    private static function allows(bool $module, bool $copy, bool $prevents): bool
    {
        return $module && $copy && ! $prevents;
    }

    #[DataProvider('operations')]
    public function test_each_choice_follows_the_three_conditions(string $step, string $key, string $ready): void
    {
        [$case, $u0002] = $this->caseReadyFor($ready);
        $node = $this->caseNode($case, 'approval1');

        foreach (self::combinations() as [$module, $copy, $prevents]) {
            $case->update(['is_attachment_enabled' => $copy]);
            $node->update(['prevents_attachment_add' => $prevents]);
            $this->switchAttachments($module, ! $copy);
            $expected = self::allows($module, $copy, $prevents);
            $label = json_encode([$module, $copy, $prevents], JSON_THROW_ON_ERROR);

            $detail = $this->detail($u0002, $case);

            $this->assertSame([['u0002', $expected]], $this->choicesOf($detail, $key), $label);
            $limits = $expected ? app(WorkflowAttachmentLimits::class)->props() : null;
            $this->assertSame($limits, $detail['attachmentLimits'], $label);
            $this->assertOperationAgrees($expected, fn () => $this->stepWithFile($step, $u0002, $case), $label);
        }
    }

    /**
     * 保留解除は処理者で読んだ状況による: 案件の管理者として(本人で)解除する選択肢は偽、保留者側の代理元として解除する選択肢は真
     * (3 つの条件がどれも真でも)。ほかの操作は代理元でも同じ結果になる。管理者は代理元を通して処理対象者になるので、選択肢の行を持つ。
     */
    public function test_a_release_by_the_case_manager_does_not_accept_files_but_the_holder_side_does(): void
    {
        [$case, $u0002] = $this->caseReadyFor('release');
        $manager = $this->seededUser('u0004');

        $this->addProxy($u0002, $manager, WorkflowProxyKind::Approve, $this->flowIdOf(self::FLOW_CODE));
        $detail = $this->detail($manager, $case);

        $this->assertSame([['u0004', false], ['u0002', true]], $this->choicesOf($detail, 'release'));
        $this->assertSame([['u0002', true]], $this->choicesOf($detail, 'approve'));
        $this->assertSame(app(WorkflowAttachmentLimits::class)->props(), $detail['attachmentLimits']);
        $this->assertOperationAgrees(false, fn () => $this->stepWithFile('release', $manager, $case));
        $this->assertOperationAgrees(true, fn () => $this->stepWithFile('release', $manager, $case, $u0002));
    }

    /**
     * 完了した申請など、処理の入口が無い案件はどの選択肢も持たず、attachmentLimits は null。
     */
    public function test_a_case_without_choices_has_no_limits(): void
    {
        [$case, $u0002] = $this->caseReadyFor('approve');
        $this->step('approve', $u0002, $case);
        $this->step('approve', $this->seededUser('u0003'), $case);

        $detail = $this->detail($u0002, $case);

        $this->assertSame([[], null], [$detail['nodeActions'], $detail['attachmentLimits']]);
    }

    /**
     * 業務処理が、props の値が真なら添付を受け、偽なら 422 attachments を返す。
     */
    private function assertOperationAgrees(bool $canAttach, callable $operation, string $label = ''): void
    {
        if ($canAttach) {
            $operation();
            $this->addToAssertionCount(1);

            return;
        }

        $errors = $this->validationErrors($operation(...));
        $this->assertSame(['attachments' => CaseAttachmentRules::NOT_ATTACHABLE], $errors, $label);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(User $user, WorkflowCase $case): array
    {
        return app(CaseDetailQuery::class)->show($this->contextOf($user), $case->id);
    }

    /**
     * 先頭のステップの操作の選択肢の [コード, canAttach](並びのまま。canAttach は props の値のまま返し、真偽かは呼び手の assertSame が確かめる)。
     *
     * @param  array<string, mixed>  $detail
     * @return list<array{string, mixed}>
     */
    private function choicesOf(array $detail, string $key): array
    {
        $choices = $detail['nodeActions'][0]['principals'][$key] ?? [];
        $code = fn (int $id) => (string) User::query()->whereKey($id)->value('code');

        return array_values(array_map(fn (array $choice) => [$code($choice['id']), $choice['canAttach']], $choices));
    }

    private function stepWithFile(string $name, User $user, WorkflowCase $case, ?User $principal = null): void
    {
        $this->step($name, $user, $case, [$this->uploadedFile("{$name}.pdf")], $principal);
    }
}
