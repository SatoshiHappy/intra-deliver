<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseInputRules;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\JsonObjectMaps;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CopiedRowAccess;
use Carbon\CarbonImmutable;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\CopiesCases;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件からの複製の一時保存(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 13.2 の一時保存):
 * 一時保存の新規が開いた時の値を copied_row_access に持ち、上書きで変えず、一時保存からの申請で引き継ぐ。大きさは入れた後の全体で数える。
 */
final class CaseCopyDraftTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
    use CatchesValidationErrors;
    use CopiesCases;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 一時保存の新規の下書きに開いた時の値だけが入り、下書きの props に出ない。上書きの後も残り、一時保存からの申請で引き継ぐ
     * (画面が送った暗号化した値は捨てる)。
     */
    public function test_the_draft_keeps_the_opened_value(): void
    {
        [$u0001, $u0005] = $this->usersOf('u0001', 'u0005');
        $this->copyFlow([$u0001]);
        $viewers = [['type' => 'user', 'id' => $u0005->id]];
        $source = $this->sourceCase($u0001, [], ['restricted' => true, 'viewers' => $viewers]);
        $sent = $this->sentFrom($this->copyPropsAs($u0001, $source->id));
        $draft = $this->postDraft($u0001, $sent);
        $opened = ['restricted' => true, 'viewers' => [['id' => $u0005->id, 'type' => 'user']]];
        $this->assertSame($opened, $this->draftCopied($draft));

        $page = $this->get("/workflow/drafts/{$draft->id}/edit")->assertOk();
        $shown = json_encode($this->pagePropsOf($page), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('copied_row_access', $shown);
        $overwritten = [...$sent, 'name' => '直した', 'revision' => $this->revisionOf($draft)];
        $this->put("/workflow/drafts/{$draft->id}", $overwritten)->assertSessionHasNoErrors();
        $this->assertSame($opened, $this->draftCopied($draft));

        $other = $this->sourceCase($u0001)->id;
        $applied = $this->postDraftApply($u0001, $draft, $this->sentFrom($this->copyPropsAs($u0001, $other)));
        $this->assertSame([true, $viewers], $this->viewersOf((int) $applied->record_id));
    }

    /**
     * 大きさの境界: 入れる前は上限の内で入れた後に超える → 422 draft(何も作らない)、入れた後にちょうど上限 → 通る、入れた後も内 → 通る。
     */
    public function test_the_draft_size_counts_the_copied_value(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $flowId = $this->copyFlow([$u0001]);
        $copied = ['restricted' => true, 'viewers' => [['type' => 'user', 'id' => $u0001->id]]];
        $sealed = app(CopiedRowAccess::class)->seal($copied, $u0001->id, $flowId);
        $empty = JsonObjectMaps::draftInput(null, null, ['memo' => ''], [], null);
        $without = strlen((string) json_encode($empty, JSON_UNESCAPED_UNICODE));
        $with = strlen((string) json_encode([...$empty, 'copied_row_access' => $copied], JSON_UNESCAPED_UNICODE));
        $create = fn (int $memo) => app(CreateDraft::class)->execute(
            $this->contextOf($u0001), self::COPY_FLOW,
            new DraftInput(null, null, ['memo' => str_repeat('a', $memo)], [], copyRowAccess: $sealed),
        );
        $counts = $this->workflowCounts();

        $errors = $this->validationErrors(fn () => $create(CaseInputRules::DRAFT_MAX_BYTES - $without));

        $this->assertSame(['draft' => '一時保存の内容が大きすぎます'], $errors);
        $this->assertSame($counts, $this->workflowCounts());

        foreach ([CaseInputRules::DRAFT_MAX_BYTES - $with, CaseInputRules::DRAFT_MAX_BYTES - $with - 1] as $memo) {
            $draft = WorkflowCase::query()->findOrFail($create($memo));
            $stored = ['restricted' => true, 'viewers' => [['id' => $u0001->id, 'type' => 'user']]];
            $this->assertSame($stored, $this->draftCopied($draft));
        }
    }
}
