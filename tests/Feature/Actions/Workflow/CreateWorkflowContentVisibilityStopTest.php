<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CreateWorkflowContent;
use App\Models\Menu\Menu;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use App\Queries\Records\RecordModuleVisibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * コンテンツを作る業務処理が立てる表示の止め(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の「表示の止めを立てる側の保証」。
 * ADR 0031 の決定 2・3): 1 つのトランザクションで、指す業務モジュールを非表示にしてからコンテンツを書く。検証で拒むと 422 で、コンテンツも非表示も
 * 残らない(業務モジュールは表示のまま)。業務モジュールが無い・明細のモジュールは 404 で、表示は変わらない。失敗の優先順 = 404 → 非表示の口の
 * 拒否(422 is_visible)→ 検証(422 code・name・module・target_field)。初期データのプロジェクト(表示中。コンテンツが無い)を使う。
 */
final class CreateWorkflowContentVisibilityStopTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    /** 非表示の口の拒否の文言(コピーの元のアイテムはメニューを消す業務処理が拒む) */
    private const COPY_SOURCE = 'コピーの元のメニューは削除できません: sitemap-main-records-project';

    public function test_creating_a_content_hides_the_visible_module_in_the_same_transaction(): void
    {
        $this->assertTrue($this->isVisible('project'));

        $contentId = $this->create('project');

        $content = WorkflowContent::query()->findOrFail($contentId);
        $this->assertSame($this->moduleId('project'), $content->record_module_id);
        $this->assertFalse($this->isVisible('project'));
        $this->assertSame(0, Menu::query()->where('code', 'like', '%-main-records-project')->count());
    }

    /**
     * 検証の誤り(コードの重なり・名前・その業務モジュールを使うコンテンツが既にある・申請対象の項目)。
     *
     * @return array<string, array{array{code?: string, name?: string, target?: string}, string, bool}>
     */
    public static function rejections(): array
    {
        return [
            'コードの重なり' => [['code' => 'project_plan'], 'code', false],
            '名前が空' => [['name' => ' '], 'name', false],
            'その業務モジュールを使うコンテンツが既にある' => [[], 'module', true],
            '申請対象の項目の誤り' => [['target' => 'no_such_field'], 'target_field', false],
        ];
    }

    /**
     * @param  array{code?: string, name?: string, target?: string}  $input
     */
    #[DataProvider('rejections')]
    public function test_a_rejection_leaves_neither_the_content_nor_the_hiding(
        array $input,
        string $key,
        bool $isTaken,
    ): void {
        if ($isTaken) {
            WorkflowContent::factory()->createOne(['record_module_id' => $this->moduleId('project')]);
        }
        $contents = WorkflowContent::query()->count();

        $errors = $this->validationErrors(fn () => $this->create('project', ...$input));

        $this->assertSame([$key], array_keys($errors));
        $this->assertSame($contents, WorkflowContent::query()->count());
        $this->assertTrue($this->isVisible('project'));
    }

    public function test_a_missing_or_details_module_is_not_found_and_changes_no_visibility(): void
    {
        foreach (['no_such_module', 'project_milestone'] as $code) {
            $this->assertThrows(fn () => $this->create($code), ModelNotFoundException::class);
        }

        $this->assertTrue($this->isVisible('project'));
    }

    /**
     * 404 が非表示の口の拒否より先(明細のモジュールのコードのアイテムをコピーの元にしても、非表示の口まで進まない)。
     */
    public function test_not_found_comes_before_the_rejection_of_the_hiding(): void
    {
        $item = Menu::factory()->item()->create(['code' => 'sitemap-main-records-project_milestone']);
        Menu::factory()->item()->create(['code' => 'example-copy', 'original_menu_id' => $item->id]);

        $create = fn () => $this->create('project_milestone', code: 'project_plan');
        $this->assertThrows($create, ModelNotFoundException::class);
    }

    /**
     * 非表示の口の拒否は is_visible だけを返し、検証の誤り(コードの重なり・名前)を並べない。何も残らない。
     */
    public function test_the_rejection_of_the_hiding_comes_before_the_validation(): void
    {
        $sitemapItem = Menu::query()->where('code', 'sitemap-main-records-project')->sole();
        Menu::factory()->item()->create(['code' => 'example-copy', 'original_menu_id' => $sitemapItem->id]);
        $contents = WorkflowContent::query()->count();

        $errors = $this->validationErrors(fn () => $this->create('project', code: 'project_plan', name: ' '));

        $this->assertSame(['is_visible' => self::COPY_SOURCE], $errors);
        $this->assertSame($contents, WorkflowContent::query()->count());
        $this->assertTrue($this->isVisible('project'));
    }

    private function create(
        string $moduleCode,
        string $code = 'test_content',
        string $name = '申請書',
        ?string $target = null,
    ): int {
        return app(CreateWorkflowContent::class)->execute($moduleCode, $code, $name, $target);
    }

    private function isVisible(string $code): bool
    {
        return app(RecordModuleVisibility::class)->isVisible($code);
    }

    private function moduleId(string $code): int
    {
        return RecordModule::query()->where('code', $code)->sole()->id;
    }
}
