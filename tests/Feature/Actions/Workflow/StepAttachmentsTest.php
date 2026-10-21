<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesStepAttachments;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの処理の添付(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節・9.1 節、可否 = 設計/ワークフロー/申請の周辺/添付/README.md の
 * 1.1 節・2 節): 付けたときだけ版が 1 つ(契機 = 処理の種別・操作するノードの名前・実行者)で、履歴の行がその版を指す。付けないときは
 * 口を呼ばず版を作らず、可否のための読み取り(フロー・業務モジュールの設定)もしない。可否は業務モジュール・案件に写したフローの値・
 * 操作するノードの写した設定の AND。
 */
final class StepAttachmentsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesStepAttachments;
    use RegistersProxies;

    /** 処理の名前 → 版の契機の処理の種別(画面の語) */
    private const ORIGINS = [
        'approve' => '承認', 'approve_early' => '承認終了', 'reject' => '否認', 'send_back' => '差戻し', 'pull_back' => '引戻し',
        'withdraw' => '取止め', 'hold' => '保留', 'release' => '保留解除', 'transfer' => '担当変更',
    ];

    /** フローの行を id で 1 行引く問い合わせ(添付の可否のために読む) */
    private const FLOW_ROW = 'select * from "workflow_flows" where "workflow_flows"."id" = ? limit 1';

    /** 業務モジュールの「添付ファイルを使用」を読む問い合わせ(RecordModuleSettings) */
    private const MODULE_SETTING = 'where "parent_record_module_id" is null and "record_modules"."id" = ?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function steps(): array
    {
        $names = array_keys(self::ORIGINS);

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    #[DataProvider('steps')]
    public function test_a_step_with_files_writes_one_revision_that_the_history_points_to(string $name): void
    {
        [$case, $user, $nodeCode] = $this->caseReadyFor($name);
        $nodeName = $this->caseNode($case, $nodeCode)->displayName();
        $revisions = $this->revisionCount($case);

        $result = null;
        $queries = $this->recordedSql(function () use (&$result, $name, $user, $case) {
            $result = $this->step($name, $user, $case, [$this->uploadedFile('処理.pdf')]);
        });

        $this->assertNotNull($result);
        $this->assertSame([1, 1], $this->attachabilityReads($queries));
        $this->assertSame($revisions + 1, $this->revisionCount($case));
        $this->assertSame([$user->id, null, self::ORIGINS[$name], $nodeName, 1], $this->latestRevision($case));
        $this->assertSame($this->latestRevisionId($case), $this->historyRevisionId($result));
        $this->assertSame([[$user->id, $user->id]], $this->attachedBy($case));
    }

    #[DataProvider('steps')]
    public function test_a_step_without_files_does_not_call_the_record_port(string $name): void
    {
        [$case, $user] = $this->caseReadyFor($name);
        $revisions = $this->revisionCount($case);

        $result = null;
        $queries = $this->recordedSql(function () use (&$result, $name, $user, $case) {
            $result = $this->step($name, $user, $case);
        });

        $this->assertNotNull($result);
        $this->assertSame([0, 0], $this->attachabilityReads($queries));
        $this->assertSame([$revisions, null, []], [
            $this->revisionCount($case), $this->historyRevisionId($result), $this->attachedBy($case),
        ]);
    }

    /**
     * @return array<string, array{bool, bool, bool}>
     */
    public static function settings(): array
    {
        $rows = [];

        foreach (range(0, 7) as $bits) {
            [$module, $copy, $prevents] = [($bits & 4) === 0, ($bits & 2) === 0, ($bits & 1) === 1];
            $words = [$module ? '使用' : '不使用', $copy ? '使う' : '使わない', $prevents ? '追加できない' : '追加できる'];
            $rows[implode(' × ', $words)] = [$module, $copy, $prevents];
        }

        return $rows;
    }

    /**
     * 読むのは案件に写したフローの値(フローの今の値は逆にしておく)と、操作するノードの写した設定。偽なら 422 で何も書かない。
     */
    #[DataProvider('settings')]
    public function test_files_are_attached_only_when_all_three_conditions_hold(
        bool $module,
        bool $copy,
        bool $prevents,
    ): void {
        [$case, $u0002] = $this->caseReadyFor('approve');
        $case->update(['is_attachment_enabled' => $copy]);
        $this->switchAttachments($module, ! $copy);
        $this->caseNode($case, 'approval1')->update(['prevents_attachment_add' => $prevents]);
        $snapshot = fn () => [
            $this->workflowCounts(), $this->nodeStates($case), $this->revisionOf($case), $this->revisionCount($case),
        ];
        $before = $snapshot();
        $approve = fn () => $this->step('approve', $u0002, $case, [$this->uploadedFile('a.pdf')]);

        if ($module && $copy && ! $prevents) {
            $approve();
            $this->assertSame([[$u0002->id, $u0002->id]], $this->attachedBy($case));

            return;
        }

        $this->assertSame(['attachments' => CaseAttachmentRules::NOT_ATTACHABLE], $this->validationErrors($approve));
        $this->assertSame($before, $snapshot());
        $this->assertSame([0, 0], [RecordAttachment::query()->count(), Upload::query()->count()]);
    }

    /**
     * 代理先が処理者(代理元)の代わりに付ける: 付けた人・上げた人・版の操作した人 = 代理先、版の処理者と履歴の処理者 = 代理元。
     */
    public function test_the_proxy_attaches_as_the_executor_for_the_principal(): void
    {
        [$principal, $proxy] = [$this->seededUser('u0002'), $this->seededUser('u0004')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Approve, $this->flowIdOf(self::FLOW_CODE));
        [$case] = $this->caseReadyFor('approve');

        $result = $this->step('approve', $proxy, $case, [$this->uploadedFile('代理先.pdf')], $principal);

        $history = WorkflowCaseHistory::query()->findOrFail($result->historyId);
        $this->assertSame([[$proxy->id, $proxy->id]], $this->attachedBy($case));
        $this->assertSame([$proxy->id, $principal->id, '承認', '承認1', 1], $this->latestRevision($case));
        $this->assertSame([$principal->id, $proxy->id], [$history->user_id, $history->executor_user_id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stepsOtherThanApproval(): array
    {
        return array_diff_key(self::steps(), ['approve' => true]);
    }

    /**
     * 承認のほかの 8 操作も代理先が処理者(代理元)の代わりに付ける(取止めは申請の代理、ほかは承認の代理)。
     * 付けた人・上げた人・版の操作した人 = 代理先、版の処理者と履歴の処理者 = 代理元。
     */
    #[DataProvider('stepsOtherThanApproval')]
    public function test_the_proxy_attaches_on_each_step_as_the_executor(string $name): void
    {
        [$case, $principal, $nodeCode] = $this->caseReadyFor($name);
        $proxy = $this->seededUser('u0005');
        $kind = $name === 'withdraw' ? WorkflowProxyKind::Apply : WorkflowProxyKind::Approve;
        $this->addProxy($principal, $proxy, $kind, $this->flowIdOf(self::FLOW_CODE));
        $nodeName = $this->caseNode($case, $nodeCode)->displayName();

        $result = $this->step($name, $proxy, $case, [$this->uploadedFile('代理先.pdf')], $principal);

        $history = WorkflowCaseHistory::query()->findOrFail($result->historyId);
        $this->assertSame([[$proxy->id, $proxy->id]], $this->attachedBy($case));
        $expected = [$proxy->id, $principal->id, self::ORIGINS[$name], $nodeName, 1];
        $this->assertSame($expected, $this->latestRevision($case));
        $this->assertSame([$principal->id, $proxy->id], [$history->user_id, $history->executor_user_id]);
    }

    /**
     * 読むのは操作するノードの写した設定だけ(ほかの承認のノードが「追加できない」でも付く)。
     */
    public function test_only_the_setting_of_the_operated_node_is_read(): void
    {
        [$case, $u0002] = $this->caseReadyFor('hold');
        WorkflowCaseNode::query()->whereKeyNot($this->caseNode($case, 'approval1')->id)
            ->where('workflow_case_id', $case->id)->where('code', '!=', 'apply')
            ->update(['prevents_attachment_add' => true]);

        $this->step('hold', $u0002, $case, [$this->uploadedFile('保留.pdf')]);

        $this->assertSame([[$u0002->id, $u0002->id]], $this->attachedBy($case));
    }

    /**
     * 添付の可否のための読み取りの数: [フローの行, 業務モジュールの設定](添付が無い処理は 0 本、付ける処理は 1 本ずつ = 引き直さない)。
     *
     * @param  list<string>  $queries
     * @return array{int, int}
     */
    private function attachabilityReads(array $queries): array
    {
        return [
            count(array_keys($queries, self::FLOW_ROW, true)),
            count(array_filter($queries, fn (string $sql) => str_contains($sql, self::MODULE_SETTING))),
        ];
    }
}
