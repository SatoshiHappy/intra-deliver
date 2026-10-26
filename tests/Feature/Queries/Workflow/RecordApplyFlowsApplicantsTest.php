<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\RecordApplyFlow;
use App\Queries\Workflow\RecordApplyFlows;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsTargetFlows;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 既存のデータからの申請の 2 経路(そのものを申請する・申請対象から)の入口(自分か代理元のどれかができるか)と申請者の候補は、同じ組で答えが揃う
 * (docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 8 節の比べるテスト)。入口が真 ⇔ 候補が空でない。
 * 候補は自分が先頭、代理元は昇順で、自分は代理元に入れない(代理の自己指定は表の CHECK で起きない)。
 *
 * @phpstan-type ProxyRow array{string, string, ?string, string}
 */
final class RecordApplyFlowsApplicantsTest extends TransactionalTestCase
{
    use BuildsTargetFlows;
    use BuildsWorkflowCases;
    use RegistersProxies;

    private const DATE = '2026-10-13';

    /**
     * 組 = [申請のノードの対象者, 代理の行(代理元, 始まり, 終わり, 効くフロー), 判定を求める者がゲストか, 期待する候補(自分 → 代理元の昇順)]。
     * 人 = self(判定を求める者)・high と low(代理元。low の方が id が小さい)・other(どれでもない人)。日 d = 2026-10-13(期間は始まり <= d < 終わり)。
     * 効くフロー = flow(このフロー)・all(全フロー = workflow_flow_id が NULL)・other(別のフローだけ)。
     *
     * @return array<string, array{string, list<string>, list<ProxyRow>, bool, list<string>}>
     */
    public static function sets(): array
    {
        $high = ['high', '2026-01-01', null, 'flow'];
        $low = ['low', '2026-01-01', null, 'flow'];
        $sets = [
            '自分だけができる' => [['self'], [$high], false, ['self']],
            '代理元だけができる' => [['high'], [$high], false, ['high']],
            '両方ができる' => [['self', 'high'], [$high], false, ['self', 'high']],
            'どちらもできない' => [['other'], [$high], false, []],
            '代理元が複数(並びは昇順)' => [['high', 'low'], [$high, $low], false, ['low', 'high']],
            '自分と代理元が複数' => [['self', 'high', 'low'], [$high, $low], false, ['self', 'low', 'high']],
            'ゲスト' => [['self', 'high'], [$high], true, []],
            '全フローの代理' => [['high'], [['high', '2026-01-01', null, 'all']], false, ['high']],
            '別のフローだけの代理(代理元はこのフローの対象者)' => [['high'], [['high', '2026-01-01', null, 'other']], false, []],
        ];

        $edges = [
            '始まりが d の前の日' => ['2026-10-12', null, ['high']],
            '始まりが d' => ['2026-10-13', null, ['high']],
            '始まりが d の次の日' => ['2026-10-14', null, []],
            '終わりが d の前の日' => ['2026-01-01', '2026-10-12', []],
            '終わりが d' => ['2026-01-01', '2026-10-13', []],
            '終わりが d の次の日' => ['2026-01-01', '2026-10-14', ['high']],
        ];

        foreach ($edges as $name => [$start, $end, $expected]) {
            $sets["代理の期間の境界: {$name}"] = [['high'], [['high', $start, $end, 'flow']], false, $expected];
        }

        $cases = [];

        foreach (['そのものを申請する' => 'record', '申請対象から' => 'target'] as $label => $route) {
            foreach ($sets as $name => [$applicants, $proxies, $guest, $expected]) {
                $cases["{$label}: {$name}"] = [$route, $applicants, $proxies, $guest, $expected];
            }
        }

        return $cases;
    }

    /**
     * sets() のうち、そのものを申請する経路の組(組の名前をそのまま使う。期待する候補は渡さない)。
     *
     * @return array<string, array{string, list<string>, list<ProxyRow>, bool}>
     */
    public static function recordSets(): array
    {
        $record = array_filter(self::sets(), fn (array $case): bool => $case[0] === 'record');

        return array_map(fn (array $case): array => array_slice($case, 0, 4), $record);
    }

    /**
     * @param  list<string>  $applicants
     * @param  list<ProxyRow>  $proxies
     * @param  list<string>  $expected
     */
    #[DataProvider('sets')]
    public function test_the_entrance_and_the_applicants_agree_and_follow_the_order(
        string $route,
        array $applicants,
        array $proxies,
        bool $guest,
        array $expected,
    ): void {
        $people = $this->people();
        $flow = $this->flowWithProxies($route, $people, $applicants, $proxies);
        $requester = new Requester($guest ? null : $people['self']->id, null);
        $entrance = $this->entrance($route, $requester, $flow->id);
        $applicantIds = $this->applicantIds($route, $requester, $flow->id);

        $this->assertSame($expected !== [], $entrance);
        $this->assertSame($entrance, $applicantIds !== []);
        $this->assertSame(array_map(fn (string $key) => $people[$key]->id, $expected), $applicantIds);
        $this->assertSame(array_unique($applicantIds), $applicantIds);
    }

    /**
     * そのものを申請する入口(与えた申請者が使えるフロー = flowsAllowing)にフローがある ⇔ その人が申請者の候補にいる。入口の組と同じ組
     * (全フロー・別のフロー・期間の境界の代理を含む)で、申請者に自分・代理元・どちらでもない人を与える。
     *
     * @param  list<string>  $applicants
     * @param  list<ProxyRow>  $proxies
     */
    #[DataProvider('recordSets')]
    public function test_the_given_applicants_entrance_agrees_with_the_applicants(
        string $route,
        array $applicants,
        array $proxies,
        bool $guest,
    ): void {
        $people = $this->people();
        $flow = $this->flowWithProxies($route, $people, $applicants, $proxies);
        $requester = new Requester($guest ? null : $people['self']->id, null);
        $candidates = $this->applicantIds($route, $requester, $flow->id);

        foreach ($people as $who => $person) {
            $allowing = $this->flows()->flowsAllowing($requester, $person->id, "m_{$flow->code}", self::day());

            $this->assertSame(
                in_array($person->id, $candidates, true),
                array_map(fn (RecordApplyFlow $allowed) => $allowed->id, $allowing) === [$flow->id],
                $who,
            );
        }
    }

    /**
     * 経路ごとのフロー(申請のノードの対象者 = $applicants)を作り、代理を登録する。効くフローが別のフロー(other)の代理があるときだけ、
     * そのおとりのフローを作る。
     *
     * @param  array{self: User, high: User, low: User, other: User}  $people
     * @param  list<string>  $applicants
     * @param  list<ProxyRow>  $proxies
     */
    private function flowWithProxies(string $route, array $people, array $applicants, array $proxies): WorkflowFlow
    {
        $flow = $this->flowOn($route, 'subject', array_map(fn (string $key) => $people[$key], $applicants));
        $scopes = ['flow' => $flow->id, 'all' => null];

        if (in_array('other', array_column($proxies, 3), true)) {
            $scopes['other'] = $this->flowOn($route, 'decoy', [$people['other']])->id;
        }

        foreach ($proxies as [$principal, $start, $end, $scope]) {
            $kind = WorkflowProxyKind::Apply;
            $this->addProxy($people[$principal], $people['self'], $kind, $scopes[$scope], $start, $end);
        }

        return $flow;
    }

    /**
     * 入口の判定(選べるフローの口)は、フロー 1 つでも 3 つでも代理の問い合わせが 1 回(代理元の判定を代理元ごとにまとめ、問い合わせの数がフローの数に
     * 比例しない)。全フローに効く代理(workflow_flow_id が NULL)で、フローは自分ができず代理元ができる。
     */
    public function test_the_entrance_asks_the_proxies_once_whatever_the_number_of_flows(): void
    {
        $people = $this->people();
        $this->addProxy($people['high'], $people['self'], WorkflowProxyKind::Apply);
        $requester = new Requester($people['self']->id, null);
        $record = $this->flowOn('record', 'many_record', [$people['high']]);
        $target = $this->recordModule('t_many', [RecordModuleAction::Read]);
        $this->targetFlow('many_target_0', $target, [RecordModuleAction::Create], [$people['high']]);
        $siblings = 1;

        foreach ([1, 3] as $count) {
            for (; $siblings < $count; $siblings++) {
                $this->siblingFlow($record, "many_record_{$siblings}");
                $this->targetFlow("many_target_{$siblings}", $target, [RecordModuleAction::Create], [$people['high']]);
            }

            $queries = $this->proxyQueries(function () use ($requester, $target, $count) {
                $this->assertCount($count, $this->flows()->flowsFor($requester, 'm_many_record', self::day()));
                $this->assertCount($count, $this->flows()->flowsForTarget($requester, $target->code, self::day()));
            });

            $this->assertCount(2, $queries, "フロー {$count} 件(経路 2 つで 1 回ずつ)");
        }
    }

    /**
     * 代理の表への問い合わせ。
     *
     * @param  Closure(): void  $run
     * @return list<string>
     */
    private function proxyQueries(Closure $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $log = array_map(fn (array $query) => (string) $query['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return array_values(array_filter($log, fn (string $sql) => str_contains($sql, '"workflow_proxies"')));
    }

    /**
     * 同じコンテンツ(= 同じ業務モジュール)で同じ申請のノードの対象者のフローを足す。
     */
    private function siblingFlow(WorkflowFlow $flow, string $code): WorkflowFlow
    {
        $sibling = WorkflowFlow::factory()->create([
            'code' => $code, 'name' => "{$code} の名前", 'workflow_content_id' => $flow->workflow_content_id,
        ]);
        $version = WorkflowFlowVersion::factory()->create(['workflow_flow_id' => $sibling->id]);
        $groupId = WorkflowRouteNode::query()->where('position', 1)->whereHas(
            'workflowFlowVersion', fn ($versions) => $versions->where('workflow_flow_id', $flow->id),
        )->sole()->authz_subject_group_id;
        WorkflowRouteNode::factory()->create([
            'workflow_flow_version_id' => $version->id, 'position' => 1, 'authz_subject_group_id' => $groupId,
        ]);
        WorkflowRouteNode::factory()->create(['workflow_flow_version_id' => $version->id, 'position' => 2]);

        return $sibling;
    }

    private function entrance(string $route, Requester $requester, int $flowId): bool
    {
        return $route === 'record'
            ? $this->flows()->allowsAny($requester, $flowId, self::day())
            : $this->flows()->allowsAnyForTarget($requester, $flowId, self::day());
    }

    /**
     * @return list<int>
     */
    private function applicantIds(string $route, Requester $requester, int $flowId): array
    {
        return $route === 'record'
            ? $this->flows()->applicantIds($requester, $flowId, self::day())
            : $this->flows()->applicantIdsForTarget($requester, $flowId, self::day());
    }

    /**
     * 経路ごとのフロー(申請のノードの対象者 = $applicants)。そのものを申請する = 業務モジュールの read と update を全員に許す。
     * 申請対象から = 申請書の create と、申請対象の参照先 t_<コード> の read を全員に許す。
     *
     * @param  list<User>  $applicants
     */
    private function flowOn(string $route, string $code, array $applicants): WorkflowFlow
    {
        if ($route === 'record') {
            return $this->targetFlow($code, null, [RecordModuleAction::Read, RecordModuleAction::Update], $applicants);
        }

        $target = $this->recordModule("t_{$code}", [RecordModuleAction::Read]);

        return $this->targetFlow($code, $target, [RecordModuleAction::Create], $applicants);
    }

    /**
     * @return array{self: User, high: User, low: User, other: User}
     */
    private function people(): array
    {
        [$self, $high, $low, $other] = $this->seededUsers('u0002', 'u0009', 'u0003', 'u0004');
        $this->assertGreaterThan($low->id, $high->id);

        return ['self' => $self, 'high' => $high, 'low' => $low, 'other' => $other];
    }

    private function flows(): RecordApplyFlows
    {
        return app(RecordApplyFlows::class);
    }

    private static function day(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DATE);
    }
}
