<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ReleaseCase;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\WorkflowPeople;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 詳細のだれとして処理するか(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節・5 節、案件の操作/README.md の 4.3 節・4.5 節):
 * nodeActions の principals・hold・release・transfer、代理の 1 行 proxyNote(4 節の表の各行。本人として処理できるか × 代理元の人数を展開)、
 * pullBackNodes の principalUserId、casePrincipal と代理元としての取止め、処理実行者の表示。案件は本物の業務処理で作り、人は初期データの案件と
 * 代理に関わらない利用者を使う(申請者 u0008)。ステップ c1 の処理担当者 = u0005・u0006・u0007、c2 = u0013。案件の管理者 = u0004(システム管理者)。
 */
final class CaseDetailProcessorsTest extends TransactionalTestCase
{
    use ReadsCaseLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addLine($this->listNode('c1', ['u0005', 'u0006', 'u0007']), $this->listNode('c2', ['u0013']));
    }

    /**
     * 本人だけで当たる人は本人 1 つ、代理元だけで当たる人は代理元、両方で当たる人は両方(自分が先、代理元は昇順)。承認待ちのステップは
     * 保留できて保留解除できない(保留解除のキーを持たない)。
     */
    public function test_principals_list_the_self_and_the_principals_who_can_process_each_operation(): void
    {
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0007'));
        $this->addProxy($this->seededUser('u0006'), $this->seededUser('u0007'));
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0011'));
        $case = $this->applied();
        $waiting = ['approve', 'approveEarly', 'reject', 'sendBack', 'hold', 'transfer'];

        $this->assertSame(array_fill_keys($waiting, ['u0005 self']), $this->principalsOf('u0005', $case));
        $both = ['u0007 self', 'u0005', 'u0006'];
        $this->assertSame(array_fill_keys($waiting, $both), $this->principalsOf('u0007', $case));
        $this->assertSame(array_fill_keys($waiting, ['u0005']), $this->principalsOf('u0011', $case));
        $action = $this->show('u0007', $case)['nodeActions'][0];
        $this->assertSame([true, false, true], [$action['hold'], $action['release'], $action['transfer']]);
    }

    /**
     * 保留中のステップ(u0005 が本人として保留)では操作ごとに選択肢が違う: 案件の管理者 u0004(u0005 の代理先)は本人として保留解除できるが、
     * 承認・担当変更は保留者側の代理元としてだけ。保留はキーを持たない。業務処理も同じ結果(u0004 は本人として保留解除できる)。
     */
    public function test_on_a_held_step_the_choices_differ_by_operation(): void
    {
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0004'));
        $case = $this->applied();
        $this->holdOn($case, 'c1', 'u0005');

        $principals = $this->principalsOf('u0004', $case);
        $action = $this->show('u0004', $case)['nodeActions'][0];

        $this->assertSame(['u0005'], $principals['approve']);
        $this->assertSame(['u0005'], $principals['transfer']);
        $this->assertSame(['u0004 self', 'u0005'], $principals['release']);
        $this->assertSame([false, true], [$action['hold'], $action['release']]);
        $this->assertArrayNotHasKey('hold', $principals);
        $manager = $this->contextOf($this->seededUser('u0004'));
        $c1 = $this->labelIds($case)['c1'];
        $release = fn () => app(ReleaseCase::class)->execute($manager, $case->id, $this->revisionOf($case), $c1, null);
        $this->assertSame('success', $this->attemptOf($release));
    }

    /**
     * 代理の 1 行(4 節の表): 本人として処理できる操作があれば代理元の人数(0・1・2)に依らず null。無ければ 0 人は null、1 人は
     * 「{代理元}さんの代理として処理します。」、2 人以上は「{代理元}さん、{代理元}さんの代理として処理できます。」(並び = 選択肢)。
     */
    public function test_the_proxy_note_follows_the_table_of_the_design(): void
    {
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0006'));
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0007'));
        $this->addProxy($this->seededUser('u0006'), $this->seededUser('u0007'));
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0011'));
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0012'));
        $this->addProxy($this->seededUser('u0007'), $this->seededUser('u0012'));
        $case = $this->applied();
        $processed = $this->applied();
        $this->approve($processed, 'c1', 'u0005');
        [$u0005, $u0007] = [$this->nameOf('u0005'), $this->nameOf('u0007')];

        $this->assertSame([
            'self with no principal' => null,
            'self with one principal' => null,
            'self with two principals' => null,
            'no operation' => null,
            'only one principal' => "{$u0005}さんの代理として処理します。",
            'only two principals' => "{$u0005}さん、{$u0007}さんの代理として処理できます。",
        ], [
            'self with no principal' => $this->show('u0005', $case)['proxyNote'],
            'self with one principal' => $this->show('u0006', $case)['proxyNote'],
            'self with two principals' => $this->show('u0007', $case)['proxyNote'],
            'no operation' => $this->show('u0008', $processed)['proxyNote'],
            'only one principal' => $this->show('u0011', $case)['proxyNote'],
            'only two principals' => $this->show('u0012', $case)['proxyNote'],
        ]);
    }

    /**
     * 引戻しの処理者 = h の処理者(自分なら null、代理元として当たるなら代理元)。casePrincipal = 自分が申請者なら null、ほかは申請者。
     */
    public function test_the_pull_back_principal_is_the_processor_of_the_history_row(): void
    {
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0011'));
        $case = $this->applied();
        $this->approve($case, 'c1', 'u0005');
        $c1 = $this->labelIds($case)['c1'];
        $applicant = $this->seededUser('u0008')->id;

        $own = $this->show('u0005', $case);
        $proxy = $this->show('u0011', $case);

        $this->assertSame([[$c1, null]], $this->pullBacksOf($own));
        $this->assertSame([[$c1, $this->seededUser('u0005')->id]], $this->pullBacksOf($proxy));
        $this->assertSame([true, true], [$own['can']['pullBack'], $proxy['can']['pullBack']]);
        $this->assertSame("{$this->nameOf('u0005')}さんの代理として処理します。", $proxy['proxyNote']);
        $applicantOwn = $this->show('u0008', $case)['casePrincipal'];
        $principals = [$own['casePrincipal'], $proxy['casePrincipal'], $applicantOwn];
        $this->assertSame([$applicant, $applicant, null], $principals);
    }

    /**
     * 申請者 u0008 の申請の代理先 u0012 は、再申請待ちの案件を申請者として取止めできる(casePrincipal = 申請者)。申請者本人は null。
     */
    public function test_the_applicant_proxy_withdraws_as_the_applicant(): void
    {
        $this->addProxy($this->seededUser('u0008'), $this->seededUser('u0012'), WorkflowProxyKind::Apply);
        $case = $this->applied();
        $this->approve($case, 'c1', 'u0005');
        $this->sendBackOn($case, 'c2', 'u0013', 'apply');

        $proxy = $this->show('u0012', $case);
        $own = $this->show('u0008', $case);

        $this->assertSame([true, $this->seededUser('u0008')->id], [$proxy['can']['withdraw'], $proxy['casePrincipal']]);
        $this->assertSame("{$this->nameOf('u0008')}さんの代理として処理します。", $proxy['proxyNote']);
        $this->assertSame([true, null, null], [$own['can']['withdraw'], $own['casePrincipal'], $own['proxyNote']]);
    }

    /**
     * 代理で処理したステップの処理実行者(処理者 = 代理元、実行者 = 代理先)と、現在のステップの処理担当者(自分の印)。履歴の行も同じ。
     */
    public function test_processed_steps_and_histories_show_the_executor(): void
    {
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0011'));
        $case = $this->applied();
        $ids = $this->labelIds($case);
        $u0005 = $this->seededUser('u0005')->id;
        $proxy = $this->contextOf($this->seededUser('u0011'));
        app(ApproveCase::class)->execute($proxy, $case->id, $this->revisionOf($case), $ids['c1'], null, [], $u0005);

        $detail = $this->show('u0013', $case);
        $nodes = array_column($detail['nodes'], null, 'id');
        $history = $detail['histories'][array_key_last($detail['histories'])];

        [$c1, $c2] = [$nodes[$ids['c1']], $nodes[$ids['c2']]];
        $targets = array_map(fn (array $person) => [$person['id'], $person['isMe']], $c2['targets']);

        $processed = [$c1['processed']['action'], ...$this->actorOf($c1['processed'])];
        $this->assertSame(['approve', 'u0005', 'u0011'], $processed);
        $this->assertSame([[$this->seededUser('u0013')->id, true]], $targets);
        $this->assertSame([[], null], [$c1['targets'], $c2['processed']]);
        $this->assertSame(['approve', 'u0005', 'u0011'], [$history['action'], ...$this->actorOf($history)]);
    }

    private function applied(): WorkflowCase
    {
        return $this->applyAs($this->seededUser(self::APPLICANT));
    }

    /**
     * @return array<string, mixed>
     */
    private function show(string $userCode, WorkflowCase $case): array
    {
        return app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser($userCode)), $case->id);
    }

    /**
     * 先頭のステップの操作ごとの処理担当者(「コード self」= 自分、ほかは代理元のコード)。
     *
     * @return array<mixed>
     */
    private function principalsOf(string $userCode, WorkflowCase $case): array
    {
        $codes = fn (array $principals) => array_map(
            fn (array $principal) => $this->codeOf($principal['id']).($principal['isSelf'] ? ' self' : ''),
            $principals,
        );

        return array_map($codes, $this->show($userCode, $case)['nodeActions'][0]['principals']);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<mixed>
     */
    private function pullBacksOf(array $detail): array
    {
        return array_map(fn (array $node) => [$node['id'], $node['principalUserId']], $detail['pullBackNodes']);
    }

    /**
     * @param  array{actor: array{id: int}, executor: array{id: int}|null}  $view
     * @return array{string, string|null}
     */
    private function actorOf(array $view): array
    {
        $executor = $view['executor'];

        return [$this->codeOf($view['actor']['id']), $executor === null ? null : $this->codeOf($executor['id'])];
    }

    private function codeOf(int $userId): string
    {
        return (string) User::query()->whereKey($userId)->value('code');
    }

    private function nameOf(string $userCode): string
    {
        $id = $this->seededUser($userCode)->id;

        return (string) app(WorkflowPeople::class)->users([$id], $this->operationDay(), 'ja')[$id]['name'];
    }
}
