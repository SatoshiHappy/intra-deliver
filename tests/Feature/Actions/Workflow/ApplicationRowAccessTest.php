<?php

namespace Tests\Feature\Actions\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 申請・再申請が書き込みの口へ送る参照制限(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.1 節・11 節の
 * 送る参照制限): 送る値の表の 2〜5 行目を、登録の経路(新規・一時保存からの申請)と更新の経路(そのものを申請する・再申請)で、
 * 申請者本人と代理先が申請する代理元のそれぞれについて通す。申請対象からの申請は、入口が新規の申請と同じなので新規の申請で通す。
 */
final class ApplicationRowAccessTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 登録の経路の組(経路・代理か・申請対象の組・業務モジュールの既定)。既定を両方の値で通すのは送らない組だけ
     * (真を送る組では、書き込みの口が既定を使わないため)。
     *
     * @return iterable<string, array{string, bool, string, bool}>
     */
    public static function registrations(): iterable
    {
        foreach (self::crossed(['new', 'draft']) as [$route, $proxy, $scenario]) {
            $sends = ! in_array($scenario, ['empty', 'viewable_unchecked'], true);

            foreach ($sends ? [false] : [false, true] as $default) {
                $name = self::label($route, $proxy, $scenario, 'default='.(int) $default);
                yield $name => [$route, $proxy, $scenario, $default];
            }
        }
    }

    #[DataProvider('registrations')]
    public function test_the_registration_routes_send_the_restriction_by_the_table(
        string $route,
        bool $proxy,
        string $scenario,
        bool $default,
    ): void {
        [$principal, $actor, $asPrincipal] = $this->actors($proxy);
        $this->setDefault($default);
        $hub = $this->targetOf($scenario, $principal);
        $this->markTarget($scenario, $hub);
        $this->limitRead($scenario, $principal, $this->usersOf('u0004')[0]);
        $values = [self::TARGET_FIELD => $hub];
        $apply = fn () => $route === 'new'
            ? $this->applyNew($actor, $asPrincipal, $values)
            : $this->applyFromDraft($actor, $asPrincipal, $values);

        if (in_array($scenario, ['row_hidden', 'read_missing'], true)) {
            $this->assertUnviewableTargetIsRefused($apply, $route === 'draft' ? 1 : 0);

            return;
        }

        $case = $apply();

        $expected = [$scenario === 'viewable_checked' || $default, []];
        $this->assertSame($expected, $this->rowAccessOf((int) $case->record_id));
        $this->assertSame($principal->id, $case->user_id);
    }

    /**
     * 更新の経路の組(経路・代理か・申請対象の組・今のチェック)。今のチェックはどの組も両方の値で通す
     * (真を送る組でも、今のチェックで参照者が元のままか空かが分かれるため)。
     *
     * @return iterable<string, array{string, bool, string, bool}>
     */
    public static function updates(): iterable
    {
        foreach (self::crossed(['record', 'reapply']) as [$route, $proxy, $scenario]) {
            foreach ([false, true] as $current) {
                $name = self::label($route, $proxy, $scenario, 'current='.(int) $current);
                yield $name => [$route, $proxy, $scenario, $current];
            }
        }
    }

    /**
     * 経路 × 本人/代理 × 申請対象の組。
     *
     * @param  list<string>  $routes
     * @return list<array{string, bool, string}>
     */
    private static function crossed(array $routes): array
    {
        $rows = [];

        foreach ($routes as $route) {
            foreach ([false, true] as $proxy) {
                foreach (array_keys(self::SCENARIOS) as $scenario) {
                    $rows[] = [$route, $proxy, $scenario];
                }
            }
        }

        return $rows;
    }

    private static function label(string $route, bool $proxy, string $scenario, string $flag): string
    {
        return "{$route} proxy=".(int) $proxy." {$scenario} {$flag}";
    }

    #[DataProvider('updates')]
    public function test_the_update_routes_send_the_restriction_by_the_table(
        string $route,
        bool $proxy,
        string $scenario,
        bool $current,
    ): void {
        [$principal, $actor, $asPrincipal] = $this->actors($proxy);
        $hub = $this->targetOf($scenario, $principal);
        $values = [self::TARGET_FIELD => $hub];
        $case = $route === 'reapply' ? $this->waitingCase($actor, $asPrincipal, $values) : null;
        $recordId = $case === null ? $this->copyFormRecord($principal, $hub) : (int) $case->record_id;
        $this->setCurrent($recordId, $current);
        $this->markTarget($scenario, $hub);
        $this->limitRead($scenario, $principal, $this->usersOf('u0004')[0]);

        $case === null
            ? $this->applyRecord($actor, $asPrincipal, $recordId, $values)
            : $this->reapplyCase($actor, $asPrincipal, $case, $values);

        $sends = in_array($scenario, ['viewable_checked', 'row_hidden', 'read_missing'], true);
        $this->assertSame($this->expectedUpdate($sends, $current), $this->rowAccessOf($recordId));
    }

    /**
     * 制限した申請対象を入れた一時保存からの申請。
     */
    public function test_an_application_from_a_draft_with_a_restricted_target_gets_the_check(): void
    {
        [$principal] = $this->actors(false);
        $hub = $this->targetOf('viewable_checked', $principal);
        $this->markTarget('viewable_checked', $hub);

        $case = $this->applyFromDraft($principal, null, [self::TARGET_FIELD => $hub]);

        $this->assertSame([true, []], $this->rowAccessOf((int) $case->record_id));
    }

    /**
     * 申請の時点の申請対象で決まる(登録の経路 = 一時保存した後に申請対象へ制限を付ける、更新の経路 = 開いた後に付ける)。
     */
    public function test_the_target_is_read_at_the_time_of_the_application(): void
    {
        [$principal] = $this->actors(false);
        $hub = $this->targetOf('viewable_unchecked', $principal);
        $restrict = fn () => Record::query()->whereKey($hub)->update(['row_access_restricted' => true]);

        $case = $this->applyFromDraft($principal, null, [self::TARGET_FIELD => $hub], $restrict);

        $this->assertSame([true, []], $this->rowAccessOf((int) $case->record_id));

        $other = $this->targetOf('viewable_unchecked', $principal);
        $form = $this->copyFormRecord($principal, $other);
        Record::query()->whereKey($other)->update(['row_access_restricted' => true]);

        $this->applyRecord($principal, null, $form, [self::TARGET_FIELD => $other]);

        $this->assertSame([true, []], $this->rowAccessOf($form));
    }

    /**
     * 閲覧できない申請対象では、申請対象のチェックを読まない(editValues を呼ばない)。
     */
    public function test_the_target_values_are_not_read_when_the_target_cannot_be_viewed(): void
    {
        [$principal] = $this->actors(false);
        $hub = $this->targetOf('row_hidden', $principal);
        $form = $this->copyFormRecord($principal, $hub);
        $this->markTarget('row_hidden', $hub);
        $this->failOnEditValues();

        $apply = fn () => $this->applyNew($principal, null, [self::TARGET_FIELD => $hub]);
        $this->assertUnviewableTargetIsRefused($apply);
        $this->applyRecord($principal, null, $form, [self::TARGET_FIELD => $hub]);

        $this->assertSame([true, []], $this->rowAccessOf($form));
    }

    /**
     * 更新の経路で、入力に申請対象のキーが無いとき。
     */
    public function test_an_update_without_the_target_key_uses_the_current_value(): void
    {
        [$principal] = $this->actors(false);
        $checked = $this->targetOf('viewable_checked', $principal);
        $this->markTarget('viewable_checked', $checked);
        $unchecked = $this->targetOf('viewable_unchecked', $principal);
        [$withChecked, $withUnchecked, $withNone] = array_map(
            fn (?int $hub) => $this->copyFormRecord($principal, $hub), [$checked, $unchecked, null],
        );
        array_map(fn (int $id) => $this->setCurrent($id, false), [$withChecked, $withUnchecked, $withNone]);

        foreach ([$withChecked, $withUnchecked, $withNone] as $id) {
            $this->applyRecord($principal, null, $id, []);
        }

        $this->assertSame(
            [true, false, false],
            array_map(fn (int $id) => $this->rowAccessOf($id)[0], [$withChecked, $withUnchecked, $withNone]),
        );

        $late = $this->targetOf('viewable_unchecked', $principal);
        $case = $this->waitingCase($principal, null, [self::TARGET_FIELD => $late]);
        Record::query()->whereKey($late)->update(['row_access_restricted' => true]);

        $this->reapplyCaseWithout($principal, $case);

        $this->assertTrue($this->rowAccessOf((int) $case->record_id)[0]);
    }

    /**
     * 更新の経路で、入力に申請対象のキーがあって空のとき(申請書のレコードの今の申請対象は制限したもの)。
     */
    public function test_an_update_with_an_empty_target_key_does_not_use_the_current_value(): void
    {
        [$principal] = $this->actors(false);
        $checked = $this->targetOf('viewable_checked', $principal);
        $this->markTarget('viewable_checked', $checked);
        $form = $this->copyFormRecord($principal, $checked);
        $this->setCurrent($form, false);

        $this->applyRecord($principal, null, $form, [self::TARGET_FIELD => null]);

        $this->assertSame([false, []], $this->rowAccessOf($form));

        $case = $this->waitingCase($principal, null, [self::TARGET_FIELD => $checked]);
        $this->setCurrent((int) $case->record_id, false);

        $this->reapplyCase($principal, null, $case, [self::TARGET_FIELD => null]);

        $this->assertSame([false, []], $this->rowAccessOf((int) $case->record_id));
    }

    /**
     * 閲覧できない申請対象の登録が断られ、何も作らないこと(一時保存からの申請では、申請の前に保存した一時保存の案件 1 件だけが残る)。
     *
     * @param  callable(): WorkflowCase  $apply
     */
    private function assertUnviewableTargetIsRefused(callable $apply, int $savedDrafts = 0): void
    {
        $counts = $this->workflowCounts();
        $counts['cases'] += $savedDrafts;

        try {
            $apply();
            $this->fail('閲覧できない申請対象の登録が通った');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('values.'.self::TARGET_FIELD, $exception->errors());
        }

        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 値の口 editValues が呼ばれたら落ちる形に差し替える(ほかの口は本物のまま)。
     */
    private function failOnEditValues(): void
    {
        $real = app(RecordFormQuery::class);
        $guard = new class($real) extends RecordFormQuery
        {
            public function __construct(private RecordFormQuery $real) {}

            public function allows(
                Requester $requester,
                string $moduleCode,
                RecordModuleAction $action,
                DateTimeInterface $date,
            ): bool {
                return $this->real->allows($requester, $moduleCode, $action, $date);
            }

            public function editValues(
                Requester $requester,
                string $moduleCode,
                int $recordId,
                DateTimeInterface $date,
                string $localeCode,
            ): array {
                throw new LogicException('閲覧できない申請対象の値を読んだ');
            }
        };
        app()->instance(RecordFormQuery::class, $guard);
    }
}
