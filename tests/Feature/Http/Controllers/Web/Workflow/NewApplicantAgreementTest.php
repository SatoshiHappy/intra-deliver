<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 新規の申請の申請できる人(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 3.1 節)を使う 4 か所が同じ答えになる: 入口の 403・申請者の
 * 選択肢(案件の画面.md の 6.1 節)・フローの一覧・参照項目の候補の「代理元としてフォームを開ける」(4.5 節)。選択肢が空なのに入口が通る組を作らないため。
 * 代理元 A = u0009、申請の代理先 B = u0002(フローに効く申請の代理)。申請できるか = 申請書の業務モジュールの create を許すか。
 */
final class NewApplicantAgreementTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    /** フォームの参照の項目の参照先(A だけが read を持つ) */
    private const TARGET = 'project';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->createFlow(approvals: [[$this->userCondition('u0003')]]);
    }

    /**
     * [申請できる人, 入口, 選択肢, 一覧に出る, A を指した候補の入口]
     *
     * @return array<string, array{list<string>, int, list<string>, bool, int}>
     */
    public static function applicants(): array
    {
        return [
            'only the user' => [['b'], 200, ['b'], true, 403],
            'only the principal' => [['a'], 200, ['a'], true, 200],
            'both' => [['a', 'b'], 200, ['b', 'a'], true, 200],
            'neither' => [[], 403, [], false, 403],
        ];
    }

    /**
     * 入口が通る ⇔ 選択肢が空でない ⇔ 一覧に出る。候補の入口は A が選択肢にいるときだけ A を判定を求める者にする(read は A だけなので 200)。
     *
     * @param  list<string>  $canApply
     * @param  list<string>  $expectedApplicants
     */
    #[DataProvider('applicants')]
    public function test_the_entry_the_choices_the_list_and_the_candidates_agree(
        array $canApply,
        int $entryStatus,
        array $expectedApplicants,
        bool $listed,
        int $candidateStatus,
    ): void {
        $people = ['a' => $this->seededUser('u0009'), 'b' => $this->seededUser('u0002')];
        $this->addProxy($people['a'], $people['b'], WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $who = fn (array $keys) => array_map(fn (string $key) => $people[$key], $keys);
        $this->allowOnly($this->projectFormModuleCode(), RecordModuleAction::Create, ...$who($canApply));
        $this->allowOnly(self::TARGET, RecordModuleAction::Read, $people['a']);
        $this->actingAs($people['b']);

        $entry = $this->get('/workflow/flows/'.self::FLOW_CODE.'/apply');
        $applicants = $entry->status() === 200
            ? array_column($entry->viewData('page')['props']['applicants'], 'id') : [];
        $codes = array_column($this->get('/workflow/flows')->assertOk()->viewData('page')['props']['flows'], 'code');
        $query = http_build_query(['flow' => self::FLOW_CODE, 'applicant' => (string) $people['a']->id]);
        $candidates = $this->getJson('/workflow/record-candidates/'.self::TARGET.'?'.$query);

        $this->assertSame($entryStatus, $entry->status());
        $this->assertSame(array_map(fn ($user) => $user->id, $who($expectedApplicants)), $applicants);
        $this->assertSame($listed, in_array(self::FLOW_CODE, $codes, true));
        $this->assertSame($candidateStatus, $candidates->status());
    }
}
