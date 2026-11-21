<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentDefinitionCandidates;
use App\Actions\Ai\Api\AgentDefinitionInput;
use App\Actions\Ai\Api\AgentToolCandidate;
use App\Actions\Ai\Api\SaveAgentDefinition;
use App\Actions\Ai\Conversation\AgentDefinitionValidator;
use App\Enums\Ai\AiToolNature;
use App\Queries\Ai\Api\AgentDefinitionQuery;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * エージェントの定義の口(docs の 設計/AI/設定とプロンプト/README.md 11.1〜11.3・設計/AI/公開の口.md 3 節の定義の読み書きの行): 作る・直す・
 * 読むと、保存の時の確かめ(欄ごとの理由を 1 つの 422 で返す)と、定義に選べる候補。
 */
final class AgentDefinitionsTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;
    use StopsRecordModules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
    }

    public function test_a_definition_is_created_updated_and_read(): void
    {
        $save = app(SaveAgentDefinition::class);
        $id = $save->create(new AgentDefinitionInput('営業', '丁寧に', ['ai_read_record'], ['m_tasks']));
        $save->update($id, new AgentDefinitionInput('営業 2', '', ['ai_search_records'], []));

        $view = app(AgentDefinitionQuery::class)->find($id);

        $read = [$view?->name, $view?->instructions, $view?->tools, $view?->knowledge];
        $this->assertSame(['営業 2', '', ['ai_search_records'], []], $read);
        $ids = array_map(fn ($definition) => $definition->id, app(AgentDefinitionQuery::class)->list());
        $this->assertContains($id, $ids);
        $this->assertNull(app(AgentDefinitionQuery::class)->find($id + 1000));
        $this->expectException(ModelNotFoundException::class);
        $save->update($id + 1000, new AgentDefinitionInput('無い', '', [], []));
    }

    /**
     * 保存の時の確かめの各行(長さは境目とその両側)。
     *
     * @return array<string, array{Closure(self): AgentDefinitionInput, list<string>}>
     */
    public static function inputs(): array
    {
        $long = AgentDefinitionValidator::INSTRUCTIONS_MAX;

        return [
            '名前が空' => [self::input(''), ['name']],
            '名前が空白だけ' => [self::input('  '), ['name']],
            '名前が上限ちょうど' => [self::input(str_repeat('名', AgentDefinitionValidator::NAME_MAX)), []],
            '名前が上限より 1 長い' => [self::input(str_repeat('名', AgentDefinitionValidator::NAME_MAX + 1)), ['name']],
            '指示文が空' => [self::input('名前', ''), []],
            '指示文が上限ちょうど' => [self::input('名前', str_repeat('指', $long)), []],
            '指示文が上限より 1 長い' => [self::input('名前', str_repeat('指', $long + 1)), ['instructions']],
            '登録に無い道具' => [self::input('名前', '', ['ai_read_record', 'ai_gone']), ['tools']],
            '無い業務モジュール' => [self::input('名前', '', [], ['m_gone']), ['knowledge']],
            '明細の業務モジュール' => [fn (self $test) => new AgentDefinitionInput('名前', '', [], [
                $test->childOf($test->taskFields['lines'])->code,
            ]), ['knowledge']],
            '表示の止めのある業務モジュール' => [function (self $test): AgentDefinitionInput {
                $test->stopModule($test->refs->id);

                return new AgentDefinitionInput('名前', '', [], ['m_refs']);
            }, ['knowledge']],
            '大きさが上限を超える' => [function (self $test): AgentDefinitionInput {
                config(['ai_runs.conversation.purposes.conversation.context_window_tokens' => 10]);

                return new AgentDefinitionInput('名前', '', ['ai_read_record'], ['m_tasks']);
            }, ['tools']],
            '合わない欄が 2 つ以上' => [self::input('', '', ['ai_gone'], ['m_gone']), ['name', 'tools', 'knowledge']],
        ];
    }

    /**
     * @param  list<string>  $tools
     * @param  list<string>  $knowledge
     * @return Closure(self): AgentDefinitionInput
     */
    private static function input(
        string $name = '名前',
        string $instructions = '',
        array $tools = [],
        array $knowledge = [],
    ): Closure {
        $input = new AgentDefinitionInput($name, $instructions, $tools, $knowledge);

        return static fn (self $test): AgentDefinitionInput => $input;
    }

    /**
     * @param  Closure(self): AgentDefinitionInput  $input
     * @param  list<string>  $fields
     */
    #[DataProvider('inputs')]
    public function test_the_save_checks_return_every_field_in_one_refusal(Closure $input, array $fields): void
    {
        $errors = [];

        try {
            app(SaveAgentDefinition::class)->create($input($this));
        } catch (ValidationException $refused) {
            $errors = array_keys($refused->errors());
        }

        $this->assertSame($fields, $errors);
    }

    /** 候補: 道具は登録の全部(名前・画面に出す名前・性質)、ナレッジは表示の止めの無い親の無い業務モジュール */
    public function test_the_candidates_are_the_registered_tools_and_the_open_modules(): void
    {
        $this->stopModule($this->refs->id);

        $tools = app(AgentDefinitionCandidates::class)->tools();
        $named = array_values(array_filter($tools, fn (AgentToolCandidate $tool) => $tool->name === 'ai_read_record'));
        $read = $named[0] ?? $this->fail('読む道具が候補に無い');
        $codes = array_column(app(AgentDefinitionCandidates::class)->knowledge(), 'code');

        $this->assertSame([AiToolNature::Read, '読み取り'], [$read->nature, $read->displayName]);
        $this->assertContains('m_tasks', $codes);
        $this->assertNotContains('m_refs', $codes);
    }
}
