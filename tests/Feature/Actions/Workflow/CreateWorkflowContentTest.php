<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CreateWorkflowContent;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * コンテンツを作る業務処理の、業務モジュールの一意と申請対象(docs の 設計/ワークフロー/データモデル/表.md の 2.1 節、
 * 直列化と検査.md の 6 節の「定義を作る業務処理」): 業務モジュールが無い・明細のモジュールは 404 が 422 より先、業務モジュールを指すコンテンツが
 * 既にある(module)・申請対象がこの業務モジュールの他モジュール参照の項目でない(target_field)は 422 で何も残らず、当たるキーを並べて返す。
 * 申請対象なし(null)と申請対象ありで作れ、申請対象ありは項目とその参照先を書く。コード・名前の誤りの 422 は CreateWorkflowDefinitionsTest。
 * 業務モジュールと項目は factory で作り、初期データの業務モジュールを使わない。
 */
final class CreateWorkflowContentTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    /** 申請書の業務モジュールのコード */
    private const FORM = 'test_form';

    /** 業務モジュールを指すコンテンツが既にあるときの文言 */
    private const MODULE_TAKEN = 'この業務モジュールは既にほかのコンテンツが使用しています';

    /** 申請対象の項目の誤りの文言 */
    private const INVALID_TARGET = '申請対象には、この業務モジュールの他モジュール参照の項目を指定してください';

    private RecordField $target;

    /**
     * 申請書の業務モジュールに、他モジュール参照の項目 subject・文字列の項目 title・明細の項目(明細のモジュールに他モジュール参照の項目
     * child_subject)を作り、ほかの業務モジュールに他モジュール参照の項目 other_subject を作る。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $form = RecordModule::factory()->createOne(['code' => self::FORM])->id;
        $fields = RecordField::factory()->state(['record_module_id' => $form]);
        $this->target = $fields->reference()->createOne(['code' => 'subject']);
        $fields->createOne(['code' => 'title']);
        $detail = $fields->details()->createOne(['code' => 'lines'])->child_record_module_id;
        RecordField::factory()->reference()->createOne(['record_module_id' => $detail, 'code' => 'child_subject']);
        RecordField::factory()->reference()->createOne(['code' => 'other_subject']);
    }

    /**
     * 業務モジュールの誤りは、ほかの入力の誤りがあっても 404。
     */
    public function test_a_missing_or_detail_module_is_not_found_before_any_invalid_input(): void
    {
        $detail = RecordModule::query()->where('parent_record_module_id', $this->target->record_module_id)->sole();

        foreach (['no_such_module', $detail->code] as $moduleCode) {
            $this->assertThrows(
                fn () => $this->create('Bad', moduleCode: $moduleCode, target: 'title'),
                ModelNotFoundException::class,
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTargets(): array
    {
        return [
            'ほかの業務モジュールの項目' => ['other_subject'],
            '明細のモジュールの項目' => ['child_subject'],
            '他モジュール参照でない項目' => ['title'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function test_rejects_a_target_that_is_not_a_reference_field_of_the_module(string $target): void
    {
        $contents = WorkflowContent::query()->count();

        $errors = $this->validationErrors(fn () => $this->create(target: $target));

        $this->assertSame(['target_field' => self::INVALID_TARGET], $errors);
        $this->assertSame($contents, WorkflowContent::query()->count());
    }

    public function test_rejects_a_second_content_for_the_same_module(): void
    {
        $this->create('first');
        $contents = WorkflowContent::query()->count();

        $errors = $this->validationErrors(fn () => $this->create('second', target: 'subject'));

        $this->assertSame(['module' => self::MODULE_TAKEN], $errors);
        $this->assertSame($contents, WorkflowContent::query()->count());
    }

    /**
     * 誤りは同じ段で、当たるキーをすべて 1 つの 422 に並べる。
     */
    public function test_lists_every_key_that_is_wrong(): void
    {
        $this->create('first');
        $contents = WorkflowContent::query()->count();

        $this->assertSame(
            ['code' => 'このコードは既に使用されています', 'module' => self::MODULE_TAKEN, 'target_field' => self::INVALID_TARGET],
            $this->validationErrors(fn () => $this->create('first', target: 'title')),
        );
        $this->assertSame($contents, WorkflowContent::query()->count());
    }

    /**
     * 項目のコードの前後の空白は除き、空白だけは null と同じ(ほかの入力と同じ整え方)。
     *
     * @return array<string, array{string|null, bool}>
     */
    public static function targets(): array
    {
        return [
            '申請対象なし' => [null, false],
            '空白だけ' => ['  ', false],
            '他モジュール参照の項目' => ['subject', true],
            '前後に空白のある項目のコード' => [' subject ', true],
        ];
    }

    #[DataProvider('targets')]
    public function test_creates_a_content_with_or_without_a_target(?string $target, bool $hasTarget): void
    {
        $contentId = $this->create(target: $target);

        $content = WorkflowContent::query()->findOrFail($contentId);
        $this->assertSame('test_content', $content->code);
        $this->assertSame($this->target->record_module_id, $content->record_module_id);
        $this->assertSame(
            $hasTarget ? [$this->target->id, $this->target->referenced_record_module_id] : [null, null],
            [$content->target_record_field_id, $content->target_record_module_id],
        );
    }

    private function create(string $code = 'test_content', string $moduleCode = self::FORM, ?string $target = null): int
    {
        return app(CreateWorkflowContent::class)->execute($moduleCode, $code, '申請書', $target);
    }
}
