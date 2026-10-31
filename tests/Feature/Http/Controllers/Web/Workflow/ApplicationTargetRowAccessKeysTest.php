<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 制限した申請対象の申請で、入口に参照制限のキーを送る筋(登録の経路 = 申請対象からの申請、更新の経路 = そのものを申請する。docs の
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 11 節の入口の参照制限のキー)。キー単独の筋は
 * {@see RecordApplicationRowAccessKeysTest}。
 */
final class ApplicationTargetRowAccessKeysTest extends TransactionalTestCase
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
    public function test_an_application_from_a_restricted_target_gets_the_check_whatever_the_keys_say(array $keys): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        Record::query()->whereKey($hub)->update(['row_access_restricted' => true]);
        $this->actingAs($u0001);

        $this->post($this->applyUrl(), $this->payload(['title' => '申請書の題', self::TARGET_FIELD => $hub], $keys))
            ->assertSessionHasNoErrors()->assertRedirect();

        $case = WorkflowCase::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame([true, []], $this->rowAccessOf((int) $case->record_id));
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    #[DataProvider('rowAccessKeys')]
    public function test_an_application_of_the_record_itself_gets_the_check_from_a_restricted_target(array $keys): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        $form = $this->copyFormRecord($u0001, $hub);
        Record::query()->whereKey($hub)->update(['row_access_restricted' => true]);
        $version = Record::query()->findOrFail($form)->version();
        $this->actingAs($u0001);

        $more = ['record_id' => $form, 'record_version' => $version, ...$keys];
        $this->post($this->applyUrl(), $this->payload(['title' => '申請書の題', self::TARGET_FIELD => $hub], $more))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([true, []], $this->rowAccessOf($form));
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
