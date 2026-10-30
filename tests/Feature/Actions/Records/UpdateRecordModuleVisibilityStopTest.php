<?php

namespace Tests\Feature\Actions\Records;

use App\Enums\Records\RecordModuleVisibilityChange;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use App\Queries\Records\Api\RecordModuleVisibilityStops;
use App\Queries\Records\RecordModuleVisibility;
use App\Queries\Records\RecordModuleVisibilityStopCatalog;
use App\Queries\Workflow\WorkflowRecordModuleVisibilityStops;
use Database\Seeders\WorkflowApplicationModules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールを変える業務処理の表示の止め(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節の
 * 「表示の止め」の表。ADR 0031 の決定 3): 止めがあれば is_visible の真は 422(キー is_visible、文言 = 止めの理由)で何も書かない。偽は非表示にし、
 * 未指定は今の表示のまま(表示のままの行は、コンテンツを作る業務処理を通さずにコンテンツの行を入れたデータでだけ来る)。明細のモジュールは止めを問わない。
 * 止めの実装を登録しない(束ねる口が空)ときは、止めの無い業務モジュールの規則のまま。止めの実装は起動が登録したワークフローの実装
 * (コンテンツが指す業務モジュール)。初期データの申請書の業務モジュール(コンテンツが指し、非表示)とプロジェクト(表示)を使う。
 */
final class UpdateRecordModuleVisibilityStopTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsRecordModuleMenus;

    /** 止めのある業務モジュール(初期データのコンテンツが指す) */
    private const STOPPED = WorkflowApplicationModules::PLAN;

    public function test_without_a_stop_the_module_follows_the_usual_rules(): void
    {
        $this->assertSame(RecordModuleVisibilityChange::Hidden, $this->update('project', ['is_visible' => false]));
        $this->assertSame(RecordModuleVisibilityChange::Shown, $this->update('project', ['is_visible' => true]));
        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update('project', ['name' => '案件']));
        $this->assertTrue($this->isVisible('project'));
    }

    /**
     * 止めがあり真: 今の表示によらず 422 で、ほかのキーの変更も残らない(表示中 = コンテンツの行を直接入れたプロジェクト)。
     *
     * @return array<string, array{bool}>
     */
    public static function currentVisibilities(): array
    {
        return ['今が非表示' => [false], '今が表示' => [true]];
    }

    #[DataProvider('currentVisibilities')]
    public function test_a_stop_rejects_showing_with_the_reason_and_writes_nothing(bool $isVisibleNow): void
    {
        $code = $isVisibleNow ? $this->stopWithoutHiding('project') : self::STOPPED;
        $before = [$this->moduleValues($code), $this->authorizationAndMenus()];

        $errors = $this->validationErrors(fn () => $this->update($code, ['name' => '案件', 'is_visible' => true]));

        $this->assertSame(['is_visible' => WorkflowRecordModuleVisibilityStops::REASON], $errors);
        $this->assertSame($before, [$this->moduleValues($code), $this->authorizationAndMenus()]);
        $this->assertSame($isVisibleNow, $this->isVisible($code));
    }

    /**
     * 止めがあり偽: 非表示にする(今が表示なら隠し、今が非表示ならそのまま)。名前などの変更は通る。
     */
    public function test_a_stop_passes_hiding_whatever_the_current_visibility_is(): void
    {
        $change = $this->update(self::STOPPED, ['name' => '計画の申請', 'is_visible' => false]);
        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $change);
        $this->assertSame('計画の申請', $this->moduleValues(self::STOPPED)[0]);
        $this->assertFalse($this->isVisible(self::STOPPED));

        $code = $this->stopWithoutHiding('project');
        $this->assertSame(RecordModuleVisibilityChange::Hidden, $this->update($code, ['is_visible' => false]));
        $this->assertSame([], $this->items($code));
    }

    /**
     * 止めがあり未指定: 今の表示のまま手順 1〜4 を行う(非表示のまま・表示のまま)。
     */
    public function test_a_stop_keeps_the_current_visibility_when_is_visible_is_not_given(): void
    {
        $change = $this->update(self::STOPPED, ['name' => '計画の申請', 'is_visible' => null]);
        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $change);
        $this->assertSame(['計画の申請', false], [$this->moduleValues(self::STOPPED)[0], $this->isVisible(self::STOPPED)]);

        $code = $this->stopWithoutHiding('project');
        $change = $this->update($code, ['name' => '案件', 'icon' => 'truck']);
        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $change);
        $this->assertSame([
            'global-nav-main-records-project' => [['en' => '案件', 'ja' => '案件'], 'truck'],
            'sitemap-main-records-project' => [['en' => '案件', 'ja' => '案件'], 'truck'],
        ], $this->items($code));
    }

    /**
     * 明細のモジュールは表示の状態を持たないので止めを問わない(どの業務モジュールも止める実装を足しても、名前は変わり、is_visible の誤りは明細の文言)。
     */
    public function test_a_details_module_is_not_asked_for_a_stop(): void
    {
        $this->app->instance('test.stops.all', new class implements RecordModuleVisibilityStops
        {
            public function stopReason(int $recordModuleId): string
            {
                return 'すべて止める';
            }
        });
        $this->app->tag(['test.stops.all'], RecordModuleVisibilityStops::TAG);
        $details = self::STOPPED.'_milestones';

        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update($details, ['name' => '節目']));
        $this->assertSame('節目', $this->moduleValues($details)[0]);
        $this->assertSame(
            ['is_visible' => '明細のモジュールには表示の状態を設定できません'],
            $this->validationErrors(fn () => $this->update($details, ['is_visible' => true])),
        );
    }

    /**
     * 止めの実装を登録しないとき(束ねる口が空): 申請書の業務モジュールも止めの無い業務モジュールの規則で表示にでき、また隠せる(回帰)。
     */
    public function test_without_any_stop_implementation_an_application_module_can_be_shown(): void
    {
        $this->app->instance(RecordModuleVisibilityStopCatalog::class, new RecordModuleVisibilityStopCatalog([]));

        $this->assertSame(RecordModuleVisibilityChange::Shown, $this->update(self::STOPPED, ['is_visible' => true]));
        $items = ['global-nav-main-records-'.self::STOPPED, 'sitemap-main-records-'.self::STOPPED];
        $this->assertSame($items, array_keys($this->items(self::STOPPED)));
        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update(self::STOPPED, ['name' => '計画の申請']));
        $this->assertSame(RecordModuleVisibilityChange::Hidden, $this->update(self::STOPPED, ['is_visible' => false]));
    }

    /**
     * コンテンツを作る業務処理を通さずに、表示中の業務モジュールを指すコンテンツの行を入れる(止めがあり表示中の形)。業務モジュールのコードを返す。
     */
    private function stopWithoutHiding(string $code): string
    {
        $moduleId = RecordModule::query()->where('code', $code)->sole()->id;
        WorkflowContent::factory()->createOne(['record_module_id' => $moduleId]);
        $this->assertTrue($this->isVisible($code));

        return $code;
    }

    private function isVisible(string $code): bool
    {
        return app(RecordModuleVisibility::class)->isVisible($code);
    }
}
