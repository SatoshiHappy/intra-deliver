<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 申請対象からの申請の画面の GET(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.3 節・11 節):
 * 判定の表の各行(403 → 404)、開いた画面の初期値が写しの値で申請対象の欄にそのレコードが入る、target が正の整数でなければ新規の申請、
 * record があれば record の経路、クエリ applicant の代理元が既定の申請者になる(applicants = この経路で申請できる自分と代理元)。
 */
final class ApplicationTargetPageTest extends TransactionalTestCase
{
    use BuildsApplicationTargetCopies;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 新規の申請の画面(initial は null)に、申請対象の項目のコード・写しの値(申請対象の欄 = そのレコード)・開いた業務レコードが入る。
     */
    public function test_the_form_opens_with_the_application_target_and_its_copy(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $ids = $this->hubRecord($u0001);
        $this->actingAs($u0001);

        $props = $this->props($this->get($this->formUrl(['target' => (string) $ids['hub']]))->assertOk());

        $this->assertSame(
            ['new', null, null, self::TARGET_FIELD, ['module' => self::HUB, 'record' => $ids['hub']]],
            [$props['mode'], $props['initial'], $props['recordId'], $props['targetField'], $props['targetEntry']],
        );
        $this->assertSame($this->expectedCopy($ids, refLabel: '参照先'), self::sorted((array) $props['targetValues']));
    }

    /**
     * この経路で申請できる人がいなければ、どのレコードでも 403。いれば、無い・明細の行・参照先の業務モジュールでない・既定の申請者が
     * 閲覧できないレコードは 404。
     */
    public function test_403_comes_before_404(): void
    {
        [$u0001, $u0003] = $this->usersOf('u0001', 'u0003');
        $this->copyFlow([$u0001]);
        [$own, $others] = [$this->hubRecord($u0001)['hub'], $this->hubRecord($u0003)['hub']];
        $form = $this->copyFormRecord($u0001, $own);
        $row = (int) Record::query()->where('parent_record_id', $own)->value('id');
        $missing = (int) Record::query()->max('id') + 1000;
        $this->onlyRelated(self::HUB);
        $applicationTargets = [$missing, $row, $form, $others, $own];

        $statusesOf = fn (User $user) => array_map(
            fn (int $applicationTarget) => $this->statusAs($user, $applicationTarget),
            $applicationTargets,
        );

        $this->assertSame([403, 403, 403, 403, 403], $statusesOf($u0003));
        $this->assertSame([404, 404, 404, 404, 200], $statusesOf($u0001));
    }

    /**
     * target が正の整数でなければ新規の申請の画面(写しなし)。record もあれば record の経路で、target は読まない。
     */
    public function test_an_invalid_application_target_query_opens_the_new_form_and_record_wins(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        $form = $this->copyFormRecord($u0001, null);
        $this->actingAs($u0001);

        foreach (['abc', '0', '-1', ''] as $target) {
            $props = $this->props($this->get($this->formUrl(['target' => $target]))->assertOk());
            $this->assertSame(
                ['new', self::TARGET_FIELD, null, null],
                [$props['mode'], $props['targetField'], $props['targetValues'], $props['targetEntry']],
            );
        }

        $url = $this->formUrl(['record' => (string) $form, 'target' => (string) $hub]);
        $props = $this->props($this->get($url)->assertOk());
        $this->assertSame(
            ['record', $form, null, null],
            [$props['mode'], $props['recordId'], $props['targetValues'], $props['targetEntry']],
        );
    }

    /**
     * クエリ applicant の代理元が既定の申請者になり、その人が閲覧できれば開く(自分の既定では閲覧できず 404)。自分がこの経路で申請できなければ、
     * クエリが無くても代理元が既定。
     */
    public function test_the_applicant_query_makes_the_principal_the_default_applicant(): void
    {
        [$u0001, $principal] = $this->usersOf('u0001', 'u0009');
        $flowId = $this->copyFlow([$u0001, $principal]);
        $this->addProxy($principal, $u0001, WorkflowProxyKind::Apply, $flowId);
        $principals = $this->hubRecord($principal)['hub'];
        $this->onlyRelated(self::HUB);
        $asPrincipal = ['applicant' => (string) $principal->id];

        $this->assertSame(200, $this->statusAs($u0001, $principals, $asPrincipal));
        $this->assertSame(404, $this->statusAs($u0001, $principals));
        $props = $this->props($this->get($this->formUrl(['target' => (string) $principals, ...$asPrincipal])));
        $this->assertSame($principals, $props['targetValues']['values'][self::TARGET_FIELD]['id'] ?? null);
        $both = [array_column($props['applicants'], 'id'), $props['applicant']];
        $this->assertSame([[$u0001->id, $principal->id], $principal->id], $both);

        $this->allowOnly(self::HUB, RecordModuleAction::Read, $principal);
        $this->assertSame(200, $this->statusAs($u0001, $principals));
        $props = $this->props($this->get($this->formUrl(['target' => (string) $principals])));
        $onlyPrincipal = [array_column($props['applicants'], 'id'), $props['applicant']];
        $this->assertSame([[$principal->id], $principal->id], $onlyPrincipal);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function formUrl(array $query): string
    {
        return '/workflow/flows/'.self::COPY_FLOW.'/apply?'.http_build_query($query);
    }

    /**
     * @param  array<string, string>  $query  足すクエリ
     */
    private function statusAs(User $user, int $applicationTargetId, array $query = []): int
    {
        $this->actingAs($user);

        return $this->get($this->formUrl(['target' => (string) $applicationTargetId, ...$query]))->status();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
