<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\RecordRule;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 複数選べる参照の項目を条件にした条件つき必須(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.2 の演算子の表と
 * 空のときの意味): 空の配列・null・キーなしは空(empty が真)、相手が 1 つ以上なら not_empty が真。
 */
final class MultipleReferenceRuleEvaluationTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /**
     * 演算子と members の入力 → 条件が真か。画面の評価と共有する表の複数選べる参照の行(入力の相手の id はテストが作るユーザーに置き換える)と、
     * 画面が送らない形の入力の行(キーなし = missing)。
     *
     * @return array<string, array{string, mixed, bool}>
     */
    public static function expressions(): array
    {
        $json = (string) file_get_contents(__DIR__.'/record-condition-expressions.json');
        /** @var array<string, array{isMultiple?: bool, op: string, server: mixed, holds: bool}> $shared */
        $shared = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $multiple = array_filter($shared, fn (array $row): bool => $row['isMultiple'] ?? false);

        return [
            ...array_map(fn (array $row): array => [$row['op'], $row['server'], $row['holds']], $multiple),
            'empty キーが無いとき真' => ['empty', 'missing', true],
        ];
    }

    #[DataProvider('expressions')]
    public function test_the_target_is_required_when_it_holds(string $operator, mixed $input, bool $holds): void
    {
        $module = $this->recordModule('projects');
        $target = $this->field($module, 'title');
        $this->field($module, 'members', RecordFieldType::User, ['is_multiple' => true]);
        RecordRule::factory()->create([
            'record_module_id' => $module->id,
            'kind' => RecordRuleKind::RequiredWhen,
            'target_record_field_id' => $target->id,
            'condition' => ['all' => [['field' => 'members', 'op' => $operator]]],
        ]);
        $values = match (true) {
            $input === 'missing' => [],
            is_array($input) => ['members' => array_map(fn (): int => $this->user()->id, $input)],
            default => ['members' => $input],
        };

        $errors = $this->errorsOf($values);

        $this->assertSame($holds ? ['values.title' => 'titleを入力してください'] : [], $errors);
    }

    /**
     * ゲストとして登録したときの検証の失敗(通れば空)。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function errorsOf(array $values): array
    {
        try {
            app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput($values));
        } catch (ValidationException $exception) {
            return array_map(fn (array $messages): string => (string) $messages[0], $exception->errors());
        }

        return [];
    }
}
