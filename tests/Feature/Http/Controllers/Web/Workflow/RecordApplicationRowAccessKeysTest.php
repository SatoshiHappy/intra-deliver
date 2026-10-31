<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRowAccessViewer;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローの入口は参照制限の 2 つのキーを読まない(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 2 節の入口、
 * 申請対象からの申請.md 11 節の「10.1 の入口の参照制限のキー」のうち送る値の表に依らない組): 申請対象からの申請(登録の経路)は参照制限の既定が
 * 「する」の申請書の業務モジュールで、そのものを申請する(更新の経路)はチェックの付いた申請書のレコードで、row_access_restricted: false を送っても
 * チェックが付いたまま、空でない row_access_viewers を送っても参照者は変わらない(登録は空、更新は今のまま)。形の誤った値でも 422 にならない。
 * 制限した申請対象の組は {@see ApplicationTargetRowAccessKeysTest}(10.1 の送る値の表)。
 */
final class RecordApplicationRowAccessKeysTest extends TransactionalTestCase
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
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rowAccessKeys(): array
    {
        return WorkflowRowAccessKeysTest::rowAccessKeys();
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    #[DataProvider('rowAccessKeys')]
    public function test_an_application_from_the_target_keeps_the_default_and_no_viewers(array $keys): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        RecordModule::query()->where('code', self::COPY_FORM)->update(['row_access_restricted_by_default' => true]);
        $this->actingAs($u0001);
        $count = WorkflowCase::query()->count();

        $this->post($this->applyUrl(), $this->payload(['title' => '申請書の題', self::TARGET_FIELD => $hub], $keys))
            ->assertSessionHasNoErrors()->assertRedirect();

        $case = WorkflowCase::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame($count + 1, WorkflowCase::query()->count());
        $this->assertSame([true, []], $this->rowAccessOf((int) $case->record_id));
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    #[DataProvider('rowAccessKeys')]
    public function test_an_application_of_the_record_itself_keeps_the_check_and_the_current_viewers(array $keys): void
    {
        [$u0001, $u0005] = $this->usersOf('u0001', 'u0005');
        $this->copyFlow([$u0001]);
        $form = $this->copyFormRecord($u0001, null);
        Record::query()->whereKey($form)->update(['row_access_restricted' => true]);
        RecordRowAccessViewer::query()->create(['record_id' => $form, 'user_id' => $u0005->id, 'sort_key' => 0]);
        $version = Record::query()->findOrFail($form)->version();
        $this->actingAs($u0001);

        $more = ['record_id' => $form, 'record_version' => $version, ...$keys];
        $this->post($this->applyUrl(), $this->payload(['title' => '申請書の題'], $more))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([true, [$u0005->id]], $this->rowAccessOf($form));
    }

    private function applyUrl(): string
    {
        return '/workflow/flows/'.self::COPY_FLOW.'/apply';
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function payload(array $values, array $more = []): array
    {
        return ['values' => $values, 'details' => [], 'base_date' => $this->operationDay()->toDateString(), ...$more];
    }

    /**
     * @return array{bool, list<int|null>}
     */
    private function rowAccessOf(int $recordId): array
    {
        $record = Record::query()->findOrFail($recordId);

        return [$record->row_access_restricted, array_values($record->rowAccessViewers()->pluck('user_id')->all())];
    }
}
