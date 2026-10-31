<?php

namespace Tests\Support;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\ReapplyCase;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRowAccessViewer;
use App\Models\Workflow\WorkflowCase;

/**
 * 申請対象の参照制限を送る規則のテストの手助け(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の
 * 10.1 節・11 節)。申請対象の組(申請対象の有無・チェック・申請者の閲覧)を作り、登録の経路(新規・一時保存からの申請)と更新の経路
 * (そのものを申請する・再申請)を、申請者本人と、代理先が代理元の申請者として行う形で通す。{@see BuildsApplicationTargetCopies}・
 * {@see RunsWorkflowOperations}・{@see RegistersProxies} と使う。
 */
trait AppliesToTargets
{
    /** 参照者の見本(元の申請書が制限していた更新で、参照者が元のままであることを見る) */
    private const VIEWER = 'u0005';

    /**
     * 申請対象の組。checked = 申請対象のチェックが付く、viewable = 申請者が閲覧できる(作成者でなければ行の判定で見えない)、
     * read = 申請者が元の業務モジュールの read を持つ。
     *
     * @var array<string, array{checked: bool, viewable: bool, read: bool}|null>
     */
    private const SCENARIOS = [
        'empty' => null,
        'viewable_checked' => ['checked' => true, 'viewable' => true, 'read' => true],
        'viewable_unchecked' => ['checked' => false, 'viewable' => true, 'read' => true],
        'row_hidden' => ['checked' => true, 'viewable' => false, 'read' => true],
        'read_missing' => ['checked' => false, 'viewable' => true, 'read' => false],
    ];

    /**
     * 申請対象のレコード(組が空なら null)。作成者は閲覧できる組なら申請者(代理元)、できない組なら別の人。チェックは付けない
     * ({@see self::markTarget()} が付ける。付ける前なら申請書のレコードが参照を書ける)。
     */
    protected function targetOf(string $scenario, User $principal): ?int
    {
        $spec = self::SCENARIOS[$scenario];

        if ($spec === null) {
            return null;
        }

        $creator = $spec['viewable'] ? $principal : $this->usersOf('u0003')[0];

        return $this->hubRecord($creator)['hub'];
    }

    /**
     * 申請対象のチェックを組に合わせる。
     */
    protected function markTarget(string $scenario, ?int $hub): void
    {
        $spec = self::SCENARIOS[$scenario];

        if ($spec !== null && $hub !== null) {
            Record::query()->whereKey($hub)->update(['row_access_restricted' => $spec['checked']]);
        }
    }

    /**
     * 申請者の閲覧の判定を組に合わせる。read の無い組は代理先だけが read を持つ(代理先で判定すると取り違えが見える)。
     * read のある組は申請者だけが持つ(同じく)。申請書のレコードを作った後に呼ぶ。
     */
    protected function limitRead(string $scenario, User $principal, User $delegate): void
    {
        $spec = self::SCENARIOS[$scenario];

        if ($spec !== null && $spec['viewable']) {
            $this->allowOnly(self::HUB, RecordModuleAction::Read, $spec['read'] ? $principal : $delegate);
        }
    }

    /**
     * 登録の経路の既定(業務モジュールの参照制限の既定)。
     */
    protected function setDefault(bool $restricted): void
    {
        RecordModule::query()->where('code', self::COPY_FORM)
            ->update(['row_access_restricted_by_default' => $restricted]);
    }

    /**
     * 更新の経路の今の状態(チェックと、付いているときの参照者 1 人)。
     */
    protected function setCurrent(int $recordId, bool $restricted): void
    {
        Record::query()->whereKey($recordId)->update(['row_access_restricted' => $restricted]);

        if ($restricted) {
            $viewer = $this->usersOf(self::VIEWER)[0];
            RecordRowAccessViewer::query()
                ->create(['record_id' => $recordId, 'user_id' => $viewer->id, 'sort_key' => 0]);
        }
    }

    /**
     * レコードのチェックと参照者のユーザーの id。
     *
     * @return array{bool, list<int|null>}
     */
    protected function rowAccessOf(int $recordId): array
    {
        $record = Record::query()->findOrFail($recordId);

        return [$record->row_access_restricted, array_values($record->rowAccessViewers()->pluck('user_id')->all())];
    }

    /**
     * 更新の経路の期待(送る値と、今のチェック・参照者から)。
     *
     * @return array{bool, list<int|null>}
     */
    protected function expectedUpdate(bool $sends, bool $current): array
    {
        $viewers = $current ? [$this->usersOf(self::VIEWER)[0]->id] : [];

        return [$sends || $current, $viewers];
    }

    /**
     * 写しのフロー(申請のノードの対象者 = 申請者)を作り、申請者(代理元)と申請する人を決める。proxy が真なら申請する人は代理先で、
     * 代理(申請)を登録する。
     *
     * @return array{User, User, User|null} 申請者・申請する人・代理元として渡す人(自分なら null)
     */
    protected function actors(bool $proxy): array
    {
        [$principal, $delegate] = $this->usersOf('u0001', 'u0004');
        $flowId = $this->copyFlow([$principal]);

        if ($proxy) {
            $this->addProxy($principal, $delegate, WorkflowProxyKind::Apply, $flowId);
        }

        return [$principal, $proxy ? $delegate : $principal, $proxy ? $principal : null];
    }

    /**
     * 新規の申請。
     *
     * @param  array<string, mixed>  $values
     */
    protected function applyNew(User $actor, ?User $principal, array $values): WorkflowCase
    {
        $context = $this->contextOf($actor);
        $input = new ApplicationInput(null, null, new RecordInput(['title' => '申請した題', ...$values]));
        $result = app(ApplyCase::class)
            ->execute($context, self::COPY_FLOW, $input, $context->date->toDateString(), $principal?->id);

        return WorkflowCase::query()->findOrFail($result->caseId);
    }

    /**
     * 一時保存(値は申請の入力と同じ)から申請する。
     *
     * @param  array<string, mixed>  $values
     */
    protected function applyFromDraft(
        User $actor,
        ?User $principal,
        array $values,
        ?callable $between = null,
    ): WorkflowCase {
        $context = $this->contextOf($actor);
        $values = ['title' => '申請した題', ...$values];
        $draft = new DraftInput('下書き', null, $values, []);
        $caseId = app(CreateDraft::class)->execute($context, self::COPY_FLOW, $draft, $principal?->id);

        if ($between !== null) {
            $between();
        }

        $input = new ApplicationInput(null, null, new RecordInput($values));
        $revision = WorkflowCase::query()->findOrFail($caseId)->revision;
        app(ApplyCase::class)->executeFromDraft($context, $caseId, $revision, $input, $context->date->toDateString());

        return WorkflowCase::query()->findOrFail($caseId);
    }

    /**
     * そのものを申請する(申請書のレコードを開いた時の版で)。
     *
     * @param  array<string, mixed>  $values
     */
    protected function applyRecord(User $actor, ?User $principal, int $formId, array $values): WorkflowCase
    {
        $context = $this->contextOf($actor);
        $input = new ApplicationInput(null, null, new RecordInput(['title' => '申請した題', ...$values]));
        $version = Record::query()->findOrFail($formId)->version();
        $result = app(ApplyCase::class)->executeFromRecord(
            $context, self::COPY_FLOW, $formId, $version, $input, $context->date->toDateString(), $principal?->id,
        );

        return WorkflowCase::query()->findOrFail($result->caseId);
    }

    /**
     * 再申請待ちの案件(申請して差し戻す)。
     *
     * @param  array<string, mixed>  $values
     */
    protected function waitingCase(User $actor, ?User $principal, array $values = []): WorkflowCase
    {
        $case = $this->applyNew($actor, $principal, $values);
        $this->sendBack($this->usersOf('u0002')[0], $case, 'apply');

        return $case;
    }

    /**
     * 再申請。
     *
     * @param  array<string, mixed>  $values
     */
    protected function reapplyCase(User $actor, ?User $principal, WorkflowCase $case, array $values): void
    {
        $input = new ApplicationInput(null, null, new RecordInput(['title' => '再申請した題', ...$values]));
        app(ReapplyCase::class)
            ->execute($this->contextOf($actor), $case->id, $this->revisionOf($case), $input, $principal?->id);
    }

    /**
     * 申請対象のキーを送らない再申請(申請書のレコードの今の値のまま)。
     */
    protected function reapplyCaseWithout(User $actor, WorkflowCase $case): void
    {
        $input = new ApplicationInput(null, null, new RecordInput(['title' => '再申請した題']));
        app(ReapplyCase::class)->execute($this->contextOf($actor), $case->id, $this->revisionOf($case), $input);
    }
}
