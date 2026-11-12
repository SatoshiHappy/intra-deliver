<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\ToolCalls\AgentTools;
use App\Actions\Ai\ToolCalls\ToolResultText;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Tools\FakeTools;
use Tests\Feature\Ai\Tools\Generic\GenericTool;
use Tests\Feature\Ai\Tools\Generic\PlainTool;
use Tests\Feature\Ai\Tools\Product\Widget\WidgetTool;
use Tests\Feature\Ai\Tools\StrayTool;
use Tests\Support\TransactionalTestCase;

/**
 * 道具の登録の起動の時の確かめ(docs の 設計/AI/データと失敗と検査.md 8 節の会話の型で足すもの・設計/AI/道具と権限/README.md 10.1): 名前と別名の文字・
 * 長さ(64 と 65)・登録したモジュールの頭・重なり(道具の中と道具どうし)・画面の名前の空・性質の起きない組・読む書く道具の業務モジュールを答えない物・
 * 製品の対象の null と空・先頭の長さより 1 少ない上限・大きい結果の外の定型・照らせない引数の形・道具の名前空間の外のクラスで文が出る。ai:check-settings はほかの食い違いと一緒に出して 1 で終わる。
 * 正しい登録と登録の無いときは文が無い。
 */
final class ToolChecksTest extends TransactionalTestCase
{
    /**
     * 名前と別名・画面の名前の確かめ。
     *
     * @return array<string, array{Closure(): list<AgentTool>, list<string>}>
     */
    public static function nameProblems(): array
    {
        $long = 'ai_'.str_repeat('a', 62);
        $form = fn (string $name): string => self::generic($name)."の名前か別名 {$name} が英小文字・数字・_ の 64 文字までの形でない";
        $twice = '道具の名前か別名 ai_r が 2 つ以上ある('.GenericTool::class.'・'.GenericTool::class.')';

        return [
            '文字' => [fn () => [FakeTools::read('ai_Read')], [$form('ai_Read')]],
            '長さ 65' => [fn () => [FakeTools::read($long)], [$form($long)]],
            '汎用の頭' => [
                fn () => [FakeTools::read('xx_read')],
                [self::generic('xx_read').'の名前か別名 xx_read が登録したモジュールの頭 ai_ で始まらない'],
            ],
            '製品の頭' => [
                fn () => [new WidgetTool('ai_w', targets: ['m'])],
                [self::label('ai_w', WidgetTool::class).'の名前か別名 ai_w が登録したモジュールの頭 widget_ で始まらない'],
            ],
            '別名の頭' => [
                fn () => [new GenericTool('ai_r', aliases: ['old_r'])],
                [self::generic('ai_r').'の名前か別名 old_r が登録したモジュールの頭 ai_ で始まらない'],
            ],
            '道具の中の重なり' => [fn () => [new GenericTool('ai_r', aliases: ['ai_r'])], [$twice]],
            '道具どうしの重なり' => [fn () => [FakeTools::read('ai_r'), new GenericTool('ai_s', aliases: ['ai_r'])], [$twice]],
            '画面の名前の空' => [fn () => [new GenericTool('ai_r', displayName: ' ')], [self::generic('ai_r').'の画面に出す名前が空']],
            '道具の名前空間の外(製品の側に数える)' => [fn () => [new StrayTool('ai_r')], [
                self::label('ai_r', StrayTool::class).'のクラスが道具の名前空間に無い',
                self::label('ai_r', StrayTool::class).'は製品の道具だが、対象の業務モジュールを持たない',
            ]],
        ];
    }

    /**
     * 包みが照らせない引数の形(和の型・anyOf・余分な鍵の禁止)。
     *
     * @return array<string, array{Closure(): list<AgentTool>, list<string>}>
     */
    public static function argumentProblems(): array
    {
        $tool = fn (Closure $shape): Closure => fn () => [new GenericTool('ai_r', shape: $shape)];
        $unreadable = fn (string $path, string $kind): string => self::generic('ai_r')."の引数の形 {$path} が照らせない形({$kind})";

        return [
            '和の型' => [
                $tool(fn (JsonSchema $s): array => ['v' => $s->union(['string', 'integer'])]),
                [$unreadable('v', '和の型')],
            ],
            'anyOf(リストの要素)' => [
                $tool(fn (JsonSchema $s) => ['v' => $s->array()->items($s->anyOf([$s->string(), $s->number()]))]),
                [$unreadable('v[]', 'anyOf')],
            ],
            '余分な鍵の禁止(入れ子)' => [
                $tool(fn (JsonSchema $s): array => [
                    'v' => $s->object(fn (JsonSchema $s) => ['w' => $s->object()->withoutAdditionalProperties()]),
                ]),
                [$unreadable('v.w', '余分な鍵の禁止')],
            ],
        ];
    }

    /**
     * 性質・業務モジュール・結果の確かめ。
     *
     * @return array<string, array{Closure(): list<AgentTool>, list<string>}>
     */
    public static function shapeProblems(): array
    {
        $plain = self::label('ai_p', PlainTool::class)
            .'は業務モジュールを読み書きするが、呼び出しが指す業務モジュールを答えない(AgentRecordTool でない)';
        $impossible = fn (string $pair): string => self::generic('ai_r')."の読むだけか{$pair}の組は起きない";
        $noTargets = self::label('widget_r', WidgetTool::class).'は製品の道具だが、対象の業務モジュールを持たない';

        return [
            '読むだけで登録' => [
                fn () => [new GenericTool('ai_r', action: AiToolAction::Create)],
                [$impossible('(真)と要る権限(create)')],
            ],
            '読むだけで更新' => [
                fn () => [new GenericTool('ai_r', action: AiToolAction::Update)],
                [$impossible('(真)と要る権限(update)')],
            ],
            '読むだけで無し' => [fn () => [new GenericTool('ai_r', action: null)], [$impossible('(真)と要る権限(無し)')]],
            '書くで閲覧' => [fn () => [new GenericTool('ai_r', readOnly: false)], [$impossible('(偽)と要る権限(view)')]],
            '書くで無し(ファイルを作る道具はまだ無い)' => [
                fn () => [new GenericTool('ai_r', readOnly: false, action: null)],
                [$impossible('(偽)と要る権限(無し)')],
            ],
            '読む道具が業務モジュールを答えない' => [fn () => [new PlainTool('ai_p', true, AiToolAction::View)], [$plain]],
            '書く道具が業務モジュールを答えない' => [fn () => [new PlainTool('ai_p', false, AiToolAction::Create)], [$plain]],
            '製品の対象が null' => [fn () => [new WidgetTool('widget_r')], [$noTargets]],
            '製品の対象が空' => [fn () => [new WidgetTool('widget_r', targets: [])], [$noTargets]],
            '上限が先頭の長さより 1 少ない' => [
                fn () => [new GenericTool('ai_r', maxLength: ToolResultText::PREVIEW_LENGTH - 1)],
                [self::generic('ai_r').'の結果の長さの上限 1999 が先頭の長さ 2000 未満'],
            ],
            '大きい結果の外の定型' => [
                fn () => [new GenericTool('ai_r', largeTemplate: AiInstructionTemplate::EmptyResult)],
                [self::generic('ai_r').'の大きい結果の定型 empty_result が大きい結果の定型でない'],
            ],
        ];
    }

    /**
     * @param  Closure(): list<AgentTool>  $tools
     * @param  list<string>  $expected
     */
    #[DataProvider('nameProblems')]
    #[DataProvider('argumentProblems')]
    #[DataProvider('shapeProblems')]
    public function test_each_wrong_registration_has_its_problem(Closure $tools, array $expected): void
    {
        $this->assertSame($expected, FakeTools::registry($tools())->problems());
    }

    /** 正しい登録(64 字の名前・別名・製品の対象・上限 1)と登録の無いときは文が無い */
    public function test_a_right_registration_and_no_registration_have_no_problem(): void
    {
        $tools = FakeTools::registry([
            new GenericTool('ai_'.str_repeat('a', 61), aliases: ['ai_old'], maxLength: ToolResultText::PREVIEW_LENGTH),
            FakeTools::create(),
            FakeTools::update(),
            FakeTools::productRead(['m']),
        ]);

        $this->assertSame([[], []], [$tools->problems(), FakeTools::registry([])->problems()]);
        $this->assertSame([], app(AgentTools::class)->problems());
    }

    public function test_the_check_command_lists_the_tool_problems_with_the_others(): void
    {
        config(['queue.connections.database.retry_after' => 3340]);
        $this->app->instance(AgentTools::class, FakeTools::registry([new GenericTool('ai_r', maxLength: 1999)]));

        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $this->assertSame(
            '道具 ai_r('.GenericTool::class.")の結果の長さの上限 1999 が先頭の長さ 2000 未満\n"
            ."種類 meeting.transcription のジョブの時間 3340 秒がキューの retry_after 3340 秒以上(config/queue.php)\n",
            Artisan::output(),
        );
    }

    private static function label(string $name, string $class): string
    {
        return "道具 {$name}({$class})";
    }

    private static function generic(string $name): string
    {
        return self::label($name, GenericTool::class);
    }
}
