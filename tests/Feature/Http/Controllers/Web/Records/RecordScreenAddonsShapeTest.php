<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Records\Record;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\FixedRecordScreenAddons;
use Tests\Support\RegistersRecordScreenAddons;
use Tests\Support\TransactionalTestCase;

/**
 * 業務の一覧とレコードの詳細に後ろのモジュールが足すものの形の誤り(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5):
 * 実装の作りの誤りなので、応答を作るときに LogicException になる。テストの実装をタグで登録し、今日を 2026-10-11 に固定する。
 */
final class RecordScreenAddonsShapeTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RegistersRecordScreenAddons;

    private const BADGE = ['label' => '処理中', 'tone' => 'pending'];

    /** @var list<int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $module = $this->recordModule('addon_check');
        $this->ids = [Record::factory()->create(['record_module_id' => $module->id])->id];
    }

    /**
     * @param  list<FixedRecordScreenAddons>  $implementations
     */
    #[DataProvider('misshapenAddons')]
    public function test_misshapen_addons_are_a_logic_error(string $path, array $implementations, string $message): void
    {
        foreach ($implementations as $addons) {
            $this->registerRecordScreenAddons($addons);
        }

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        $this->get(str_replace('{id}', (string) $this->ids[0], $path));
    }

    /**
     * @return array<string, array{string, list<FixedRecordScreenAddons>, string}>
     */
    public static function misshapenAddons(): array
    {
        $show = fn (array $actions) => ['actions' => $actions, 'badges' => [], 'fieldNotes' => []];

        return [
            'a dialog without the module prefix' => ['/records/data/addon_check', [
                new FixedRecordScreenAddons('addon_check', [
                    'actions' => [self::addonAction('a', ['dialog' => 'create'])],
                ]),
            ], 'dialog の形が違う'],
            'codes repeated across implementations' => ['/records/data/addon_check', [
                new FixedRecordScreenAddons('addon_check', ['actions' => [self::addonAction('same')]]),
                new FixedRecordScreenAddons('addon_check', ['actions' => [self::addonAction('same')]]),
            ], 'code same が重なる'],
            'codes repeated in the detail' => ['/records/data/addon_check/{id}', [
                new FixedRecordScreenAddons(
                    'addon_check',
                    show: $show([self::addonAction('a'), self::addonAction('a')]),
                ),
            ], 'code a が重なる'],
            'a badge for a row not passed' => ['/records/data/addon_check', [
                new FixedRecordScreenAddons('addon_check', badges: [999999 => [self::BADGE]], badgesForAnyId: true),
            ], '渡していない行の id を返した: 999999'],
            'an action without changesValues' => ['/records/data/addon_check', [
                new FixedRecordScreenAddons('addon_check', ['actions' => [
                    array_diff_key(self::addonAction('a'), ['changesValues' => true]),
                ]]),
            ], 'index() の形が違う'],
            'a badge with an unknown tone' => ['/records/data/addon_check/{id}', [
                new FixedRecordScreenAddons('addon_check', show: [
                    'actions' => [], 'badges' => [['label' => '札', 'tone' => 'red']], 'fieldNotes' => [],
                ]),
            ], 'show() の形が違う'],
        ];
    }
}
