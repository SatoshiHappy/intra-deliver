<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\CreateRecordModule;
use App\Models\Master\User;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 定義の業務処理が呼び手に依らないこと(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節の「呼び手に依らない」の定義の分、
 * 項目と規則/README.md 2 節の前文、設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3):
 * 同じ入力を HTTP の要求(管理のルート。画面と同じ JSON の本文、システム管理者)と業務処理の直接の呼び出しで
 * 与えると、持つ名称と検証の結果(キーと文言)が同じ。前後に空白を持つ値と空白だけの値を、業務モジュール・項目・選択肢の名称とコードで含める。
 */
final class RecordDefinitionCallerIndependenceTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
    }

    public function test_a_module_keeps_the_same_trimmed_name_whichever_the_caller(): void
    {
        $this->postJson('/records/admin/modules', ['code' => ' equipment_http ', 'name' => '  備品  ', 'description' => '   '])->assertStatus(303);
        app(CreateRecordModule::class)->execute(['code' => ' equipment_direct ', 'name' => '  備品  ', 'description' => '   ']);

        $this->assertSame(
            $this->moduleSummary('equipment_http'),
            $this->moduleSummary('equipment_direct'),
        );
        $this->assertSame(['備品', null], $this->moduleSummary('equipment_direct'));
    }

    public function test_a_field_keeps_the_same_trimmed_names_whichever_the_caller(): void
    {
        $input = fn (string $code) => ['code' => " {$code} ", 'name' => '  品名  ', 'type' => 'choice', 'options' => [['code' => ' a ', 'name' => '  新品  ']], 'default_value' => ' a '];

        $this->postJson('/records/admin/modules/project/fields', $input('item_http'))->assertStatus(303);
        // 項目の名前は同じ業務モジュールの中で重複させられないので、直接の呼び出しは別の業務モジュールに足す
        app(AddRecordField::class)->execute('project_issue', $input('item_direct'));

        $this->assertSame($this->fieldSummary('item_http'), $this->fieldSummary('item_direct'));
        $this->assertSame(['品名', 'a', [['a', '新品']]], $this->fieldSummary('item_direct'));
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>, list<string>}>
     */
    public static function rejectedInputs(): array
    {
        return [
            '業務モジュールの空白だけの名前と空白つきの書式違いのコード' => ['/records/admin/modules', 'module', ['code' => '  Bad  ', 'name' => '   '], ['code', 'name']],
            '項目の空白だけの名前' => ['/records/admin/modules/project/fields', 'field', ['code' => ' memo ', 'name' => '   ', 'type' => 'string'], ['name']],
            '選択肢の空白だけのコードと名称' => ['/records/admin/modules/project/fields', 'field', ['code' => 'kind', 'name' => '種類', 'type' => 'choice', 'options' => [['code' => '  ', 'name' => '  ']]], ['options.0.code', 'options.0.name']],
            '明細のモジュールの空白だけの名前' => ['/records/admin/modules/project_issue/fields', 'field', ['code' => 'steps', 'name' => '手順', 'type' => 'details', 'child_module' => ['code' => ' issue_step ', 'name' => '   ']], ['child_module.name']],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys  失敗のキー
     */
    #[DataProvider('rejectedInputs')]
    public function test_the_http_request_and_the_direct_call_return_the_same_errors(string $url, string $kind, array $input, array $keys): void
    {
        $counts = [RecordModule::query()->count(), RecordField::query()->count()];

        $viaHttp = $this->postJson($url, $input)->assertUnprocessable()->json('errors');
        $direct = $this->directErrors(fn () => $kind === 'module'
            ? app(CreateRecordModule::class)->execute($input)
            : app(AddRecordField::class)->execute(explode('/', $url)[4], $input));

        $this->assertSame($direct, $viaHttp);
        $this->assertSame($keys, array_keys($direct));
        $this->assertSame($counts, [RecordModule::query()->count(), RecordField::query()->count()]);
    }

    /**
     * 業務処理を直接呼んだときの検証の失敗(キー → 文言のすべて)。
     *
     * @param  Closure(): mixed  $action
     * @return array<string, array<int, string>>
     */
    private function directErrors(Closure $action): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('検証の失敗にならなかった');
    }

    /**
     * @return array{string, string|null}
     */
    private function moduleSummary(string $code): array
    {
        $module = RecordModule::query()->where('code', $code)->sole();

        return [$module->name, $module->description];
    }

    /**
     * @return array{string, mixed, list<array{string, string}>}
     */
    private function fieldSummary(string $code): array
    {
        $field = RecordField::query()->with('recordFieldOptions')->where('code', $code)->sole();

        return [$field->name, $field->default_value, array_values($field->recordFieldOptions->map(fn (RecordFieldOption $option) => [$option->code, $option->name])->all())];
    }
}
