<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CopiedRowAccess;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\CopiesCases;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件からの複製の参照制限の引き継ぎ(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 13.2):
 * 申請対象からの申請.md の 10.1 の送る参照制限の表の案件からの複製の行を、直接の申請と一時保存を経る申請、写す人と申請者が自分・代理元で通す。
 * 書く時に参照者を外し直すこと、開いた時に外した参照者の 1 行、送られた値が合わないときに強める向きに倒すことも見る。
 */
final class CaseCopyRowAccessTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
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
     * @return array<string, array{string, bool}>
     */
    public static function routesAndActors(): array
    {
        return [
            '直接・本人' => ['new', false], '直接・代理' => ['new', true],
            '一時保存・本人' => ['draft', false], '一時保存・代理' => ['draft', true],
        ];
    }

    /**
     * 元が付き × 申請対象が空 × 業務モジュールの既定が「制限しない」→ 付き・参照者 = 確かめた元の参照者(並びのまま)。代理で写すと申請者 = 代理元。
     */
    #[DataProvider('routesAndActors')]
    public function test_a_restricted_source_passes_its_viewers(string $route, bool $proxy): void
    {
        [$copier, $principal] = $this->copier($proxy);
        $viewers = [
            ['type' => 'user', 'id' => $this->usersOf('u0005')[0]->id],
            ['type' => 'department', 'id' => $this->departmentIdOf('planning')],
        ];
        $source = $this->sourceCase($this->usersOf('u0001')[0], [], ['restricted' => true, 'viewers' => $viewers]);
        $props = $this->copyPropsAs($copier, $source->id);

        $case = $this->sendCopy($route, $copier, $this->sentFrom($props), $principal);

        $this->assertSame([true, $viewers], $this->viewersOf((int) $case->record_id));
        $this->assertSame($this->usersOf('u0001')[0]->id, $case->user_id);
    }

    /**
     * 元が外れ・記録の無い版 → 新規の申請と同じ(業務モジュールの既定 = 「する」なら付き・参照者なし、申請対象が制限していれば付き、どちらでもなければ外れ)。
     * 一時保存では外れを restricted = 偽・viewers = 空で持つ。
     */
    public function test_an_unrestricted_source_follows_the_new_application_rule(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $unrestricted = $this->sourceCase($u0001);
        $unrecorded = $this->sourceCase($u0001, [], null);
        $checked = $this->targetOf('viewable_checked', $u0001);
        $withTarget = $this->sourceCase($u0001, [self::TARGET_FIELD => $checked]);
        $this->markTarget('viewable_checked', $checked);
        $sent = fn (WorkflowCase $source) => $this->sentFrom($this->copyPropsAs($u0001, $source->id));

        foreach ([$unrestricted, $unrecorded] as $source) {
            foreach (['new', 'draft'] as $route) {
                $case = $this->sendCopy($route, $u0001, $sent($source));
                $this->assertSame([false, []], $this->viewersOf((int) $case->record_id));
            }
        }

        $draft = $this->postDraft($u0001, $sent($unrecorded));
        $this->assertSame(['restricted' => false, 'viewers' => []], $this->draftCopied($draft));
        $targetSent = $sent($withTarget);
        $targetSent['values'][self::TARGET_FIELD] = $checked;
        $this->assertSame([true, []], $this->viewersOf((int) $this->sendCopy('new', $u0001, $targetSent)->record_id));
        $this->setDefault(true);
        $defaulted = $this->sendCopy('new', $u0001, $sent($unrestricted));
        $this->assertSame([true, []], $this->viewersOf((int) $defaulted->record_id));
    }

    /**
     * 開いた時に有効でない参照者は外れて 1 行に「参照制限の参照者」と出る。開いた後・申請の前に無効になった参照者は、書く時にその人だけが外れて
     * 通る(直接の申請と一時保存からの申請)。一時保存は開いた時の値のまま持つ。
     */
    public function test_viewers_are_checked_when_opening_and_rechecked_when_writing(): void
    {
        [$u0001, $u0005] = $this->usersOf('u0001', 'u0005');
        $this->copyFlow([$u0001]);
        [$late, $gone] = [$this->validUser(), $this->validUser()];
        $party = fn (User $user) => ['type' => 'user', 'id' => $user->id];
        $rowAccess = ['restricted' => true, 'viewers' => [$party($gone), $party($late), $party($u0005)]];
        $source = $this->sourceCase($u0001, [], $rowAccess);
        $this->endToday($gone);
        $props = $this->copyPropsAs($u0001, $source->id);
        $this->assertSame(['参照制限の参照者'], array_column($props['copy']['clearedFields'], 'name'));
        $draft = $this->postDraft($u0001, $this->sentFrom($props));

        $this->endToday($late);

        $direct = $this->postApply($u0001, $this->sentFrom($props));
        $this->assertSame([true, [$party($u0005)]], $this->viewersOf((int) $direct->record_id));
        $kept = [['id' => $late->id, 'type' => 'user'], ['id' => $u0005->id, 'type' => 'user']];
        $this->assertSame(['restricted' => true, 'viewers' => $kept], $this->draftCopied($draft));
        $applied = $this->postDraftApply($u0001, $draft, $this->sentFrom($props, [self::COPY_KEY => null]));
        $this->assertSame([true, [$party($u0005)]], $this->viewersOf((int) $applied->record_id));
    }

    /**
     * 元が付きでも、申請者が申請対象を閲覧できなければ書き込みの口の参照先の確かめで 422(何も作らない)。
     */
    public function test_a_restricted_source_with_an_unviewable_target_is_refused(): void
    {
        [$u0001, $u0004] = $this->usersOf('u0001', 'u0004');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        $source = $this->sourceCase($u0001, [self::TARGET_FIELD => $hub], ['restricted' => true, 'viewers' => []]);
        $sent = $this->sentFrom($this->copyPropsAs($u0001, $source->id));
        $sent['values'][self::TARGET_FIELD] = $hub;
        $this->allowOnly(self::HUB, RecordModuleAction::Read, $u0004);
        $counts = $this->workflowCounts();

        $this->actingAs($u0001);
        $this->post(self::APPLY_URL, $sent)->assertSessionHasErrors('values.'.self::TARGET_FIELD);

        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 送られた値が合わない(文字列でない・解けない・別の人・ほかのフロー)と付き・参照者 = 空。直接の申請も一時保存の新規も同じ。
     */
    public function test_posted_values_that_do_not_fit_restrict_without_viewers(): void
    {
        [$u0001, $u0004] = $this->usersOf('u0001', 'u0004');
        $flowId = $this->copyFlow([$u0001]);
        $source = $this->sourceCase($u0001);
        $sent = $this->sentFrom($this->copyPropsAs($u0001, $source->id));
        $copied = app(CopiedRowAccess::class);
        $unrestricted = ['restricted' => false, 'viewers' => []];
        $posted = [
            ['restricted' => false], 12, 'abc',
            $copied->seal($unrestricted, $u0004->id, $flowId), $copied->seal($unrestricted, $u0001->id, $flowId + 1),
        ];

        foreach ($posted as $value) {
            $case = $this->postApply($u0001, [...$sent, self::COPY_KEY => $value]);
            $this->assertSame([true, []], $this->viewersOf((int) $case->record_id));
            $draft = $this->postDraft($u0001, [...$sent, self::COPY_KEY => $value]);
            $this->assertSame(['restricted' => true, 'viewers' => []], $this->draftCopied($draft));
        }

        $this->assertSame([false, []], $this->viewersOf((int) $this->postApply($u0001, $sent)->record_id));
    }

    /**
     * 受けない入口(そのものを申請する・再申請・一時保存の上書き・一時保存からの申請)は暗号化した値を捨て(422 にしない)、HTTP で送った
     * 平文の参照制限の 2 つのキーと copied_row_access はどの入口でも捨てる。
     */
    public function test_other_entrances_and_plain_keys_are_discarded(): void
    {
        [$u0001, $u0002] = $this->usersOf('u0001', 'u0002');
        $this->copyFlow([$u0001]);
        $source = $this->sourceCase($u0001, [], ['restricted' => true, 'viewers' => []]);
        $sealed = $this->copyPropsAs($u0001, $source->id)['copy']['rowAccess'];
        $plain = ['row_access_restricted' => true, 'row_access_viewers' => [['type' => 'user', 'id' => $u0002->id]],
            'copied_row_access' => ['restricted' => true, 'viewers' => []]];
        $base = [
            'name' => null, 'comment' => null, 'values' => ['title' => '題'], 'details' => [],
            'base_date' => $this->operationDay()->toDateString(),
        ];
        $extra = [self::COPY_KEY => $sealed, ...$plain];
        $this->actingAs($u0001);

        $form = $this->copyFormRecord($u0001, null);
        $version = Record::query()->findOrFail($form)->version();
        $this->post(self::APPLY_URL, [...$base, ...$extra, 'record_id' => $form, 'record_version' => $version])
            ->assertSessionHasNoErrors();
        $this->assertSame([false, []], $this->viewersOf($form));

        $waiting = $this->waitingCase($u0001, null);
        $this->actingAs($u0001);
        $reapplied = [...$base, ...$extra, 'revision' => $this->revisionOf($waiting)];
        $this->post("/workflow/cases/{$waiting->id}/reapply", $reapplied)->assertSessionHasNoErrors();
        $this->assertSame([false, []], $this->viewersOf((int) $waiting->record_id));

        $draft = $this->postDraft($u0001, [...$base, ...$plain]);
        $this->assertFalse($this->draftCopied($draft));
        $this->put("/workflow/drafts/{$draft->id}", [...$base, ...$extra, 'revision' => $this->revisionOf($draft)])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->draftCopied($draft));
        $applied = $this->postDraftApply($u0001, $draft, [...$base, ...$extra]);
        $this->assertSame([false, []], $this->viewersOf((int) $applied->record_id));
        $plainApplied = $this->postApply($u0001, [...$base, ...$plain]);
        $this->assertSame([false, []], $this->viewersOf((int) $plainApplied->record_id));
    }

    /**
     * 写す人と既定の申請者(proxy なら u0002 が u0001 の申請の代理先で、u0002 は承認者として元の申請を閲覧できる)。
     *
     * @return array{User, User|null} 写す人・申請者として渡す代理元
     */
    private function copier(bool $proxy): array
    {
        [$u0001, $u0002] = $this->usersOf('u0001', 'u0002');
        $flowId = $this->copyFlow([$u0001]);

        if (! $proxy) {
            return [$u0001, null];
        }

        $this->addProxy($u0001, $u0002, WorkflowProxyKind::Apply, $flowId);

        return [$u0002, $u0001];
    }

    private function validUser(): User
    {
        $user = User::factory()->create();
        UserTerm::factory()->create(['user_id' => $user->id, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $user;
    }

    /**
     * 有効の終わりを今日にする(半開区間なので今日から無効)。
     */
    private function endToday(User $user): void
    {
        UserTerm::query()->where('user_id', $user->id)->update(['end_date' => $this->operationDay()->toDateString()]);
    }
}
