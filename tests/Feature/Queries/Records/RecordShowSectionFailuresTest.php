<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Queries\Records\Api\RecordShowPanelQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\FixedRecordShowSection;
use Tests\Support\RegistersRecordShowSections;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;
use Throwable;

/**
 * 詳細の節の実装の予期しない例外は、報告して欄に閉じる(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 2 節の 4):
 * presence() の例外は帯が件数なし、section() の例外は中身が失敗の形。どちらも元の例外を previous に持つ LogicException を 1 件報告し、
 * ほかの節は出る。元の例外は、Handler が記録しない型(ModelNotFoundException)でも包んで報告するので残る。
 */
final class RecordShowSectionFailuresTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RegistersRecordShowSections;
    use UsesSeededRecords;

    private Record $record;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $module = $this->recordModule('show_check');
        $this->record = Record::factory()->create(['record_module_id' => $module->id]);
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function unexpectedExceptions(): array
    {
        return [
            '一般の例外' => [new RuntimeException('節の誤り')],
            'Handler が記録しない型' => [new ModelNotFoundException('節の誤り')],
        ];
    }

    #[DataProvider('unexpectedExceptions')]
    public function test_an_exception_of_a_section_is_reported_wrapped_and_closed_as_failed(Throwable $thrown): void
    {
        Exceptions::fake();
        $this->registerRecordShowSection(
            new FixedRecordShowSection('test-throw', '投げる', '投げる', section: $thrown),
        );
        $this->registerRecordShowSection($this->listSection('test-other', '別の節', '別の節', 1));

        $failed = $this->content($this->record->id, 'test-throw');
        $other = $this->content($this->record->id, 'test-other');

        $this->assertSame(['kind' => 'section', 'code' => 'test-throw', 'content' => ['kind' => 'failed']], $failed);
        $this->assertSame('list', $other['content']['kind'] ?? null);
        Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), 'test-throw')
            && str_contains($e->getMessage(), 'section()') && $e->getPrevious() === $thrown);
        Exceptions::assertReportedCount(1);
    }

    #[DataProvider('unexpectedExceptions')]
    public function test_an_exception_of_a_presence_is_reported_wrapped_and_the_band_shows_it_without_a_count(
        Throwable $thrown,
    ): void {
        Exceptions::fake();
        $this->registerRecordShowSection(new FixedRecordShowSection('test-throw', '投げる', '投げる', $thrown));
        $this->registerRecordShowSection($this->listSection('test-other', '別の節', '別の節', 4));

        $panel = $this->panels()->panel($this->contextFor(), 'show_check', $this->record->id);

        $this->assertSame([
            ['code' => 'test-throw', 'heading' => '投げる', 'shortHeading' => '投げる', 'count' => null],
            ['code' => 'test-other', 'heading' => '別の節', 'shortHeading' => '別の節', 'count' => 4],
        ], $panel['sections'] ?? null);
        Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), 'test-throw')
            && str_contains($e->getMessage(), 'presence()') && $e->getPrevious() === $thrown);
        Exceptions::assertReportedCount(1);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function content(int $recordId, string $key): ?array
    {
        return $this->panels()->content($this->contextFor(), 'show_check', $recordId, $key, null);
    }

    private function panels(): RecordShowPanelQuery
    {
        return $this->app->make(RecordShowPanelQuery::class);
    }
}
