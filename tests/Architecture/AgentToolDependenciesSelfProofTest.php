<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\Finding;

/**
 * 機械検査 (b) の会話の道具の規則(docs の 設計/業務ツール/機械検査.md、正本 = 設計/AI/道具と権限/道具の判定と今の権限.md の 10.4。
 * 一覧と規則 = tests/Architecture/Policy/AgentToolDependencies.php)の自己証明。
 * どの違反の例も新しい規則の識別子で見るのは、ほかの規則の指摘で代えて通らないようにするため(抑制の注釈を付けた例を含む)。書き込みの通る例は、
 * 道具の名前空間が登録・更新の口の呼び手の一覧(RecordWriters::CALLERS)にまだ無いので、その規則の指摘だけが残ることを見る。
 * fixture の設定は共通の設定から置き場の規則のルートだけを差し替える(AgentTools/phpstan.neon)。
 */
final class AgentToolDependenciesSelfProofTest extends PhpStanSelfProofTestCase
{
    private const CONFIG = 'AgentTools/phpstan.neon';

    private const DEPENDENCIES = 'phpat.agentToolDependencies';

    private const CALL_DEPENDENCIES = 'phpat.agentToolCallDependencies';

    private const OPERATION = 'allowedOperation';

    private const PLACEMENT = 'interfacePlacement';

    private const REFLECTIVE = 'reflectiveCall';

    /** 通る書き込みの fixture に残ってよい、今の規則の指摘 */
    private const WRITER_RULE = 'phpat.recordWriterDependencies';

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function violations(): array
    {
        return [
            '道具が Upload の実体のファイルの場所の読み取りに依存する' => [self::DEPENDENCIES, 'Actions/Ai/Tools/DependsOnUploads.php', 14],
            '道具が UploadResponses に依存する' => [self::DEPENDENCIES, 'Actions/Ai/Tools/DependsOnUploads.php', 15],
            '抑制の注釈を付けた依存(消せない)' => [self::DEPENDENCIES, 'Actions/Ai/Tools/SuppressedDependency.php', 13],
            '判定の関数が RecordListQuery に依存する' => [self::CALL_DEPENDENCIES, 'Actions/Ai/ToolCalls/DependsOnList.php', 13],
            '題の読み取りをインスタンスで呼ぶ' => [self::OPERATION, 'Actions/Ai/Tools/CallsTitleOf.php', 20],
            '題の読み取りを callable の配列で渡す' => [self::OPERATION, 'Actions/Ai/Tools/CallsTitleOf.php', 26],
            '題の読み取りを first-class callable で渡す' => [self::OPERATION, 'Actions/Ai/Tools/CallsTitleOf.php', 31],
            '題の読み取りを静的に呼ぶ' => [self::OPERATION, 'Actions/Ai/Tools/CallsTitleOf.php', 36],
            '題の読み取りを callable の文字列で渡す' => [self::OPERATION, 'Actions/Ai/Tools/CallsTitleOf.php', 41],
            '申請の経路を含めない閲覧の判定' => [self::OPERATION, 'Actions/Ai/Tools/CallsTitleOf.php', 46],
            '抑制の注釈を付けた題の読み取り(消せない)' => [self::OPERATION, 'Actions/Ai/Tools/CallsTitleOf.php', 52],
            '許した口の型を継いだ型の受け手' => [self::OPERATION, 'Actions/Ai/Tools/CallsThroughSubclass.php', 14],
            '受け手が mixed' => [self::OPERATION, 'Actions/Ai/Tools/LooseCalls.php', 17],
            '名前が字句でない' => [self::OPERATION, 'Actions/Ai/Tools/LooseCalls.php', 22],
            '引数の展開' => [self::OPERATION, 'Actions/Ai/Tools/LooseCalls.php', 28],
            'call_user_func' => [self::OPERATION, 'Actions/Ai/Tools/LooseCalls.php', 33],
            'コンテナの call' => [self::OPERATION, 'Actions/Ai/Tools/LooseCalls.php', 38],
            'コンテナのファサードの call' => [self::OPERATION, 'Actions/Ai/Tools/LooseCalls.php', 43],
            'Closure でない値の呼び出し' => [self::OPERATION, 'Actions/Ai/Tools/LooseCalls.php', 48],
            '持ち主の鍵を位置で渡す更新' => [self::OPERATION, 'Actions/Ai/Tools/PassesHolder.php', 20],
            '持ち主の鍵を名前つきで渡す更新' => [self::OPERATION, 'Actions/Ai/Tools/PassesHolder.php', 25],
            '鍵の引数を決められない callable の更新' => [self::OPERATION, 'Actions/Ai/Tools/PassesHolder.php', 30],
            'editValues を判定の関数が呼ぶ' => [self::OPERATION, 'Actions/Ai/ToolCalls/ReadsEditValues.php', 19],
            'リフレクションのクラスを作る' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 20],
            'ReflectionMethod::invoke' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 25],
            'ReflectionMethod::invokeArgs' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 30],
            'ReflectionMethod::getClosure' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 35],
            'getMethod()->getClosure()' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 41],
            'ReflectionFunction::invoke' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 46],
            'Closure::bind' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 51],
            'Closure::bindTo' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 56],
            'Closure::call' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 61],
            'Closure::fromCallable' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 66],
            'ReflectionProperty::setValue(名前によらない)' => [self::REFLECTIVE, 'Actions/Ai/Tools/UsesReflection.php', 71],
            '名前空間の外で継いだ interface を実装する' => [self::PLACEMENT, 'Actions/Ai/OutsideRecordTool.php', 10],
            '抑制の注釈を付けた名前空間の外の実装(消せない)' => [self::PLACEMENT, 'Actions/Ai/OutsideTool.php', 11],
            '名前空間の外で基底のクラスを継ぐ' => [self::PLACEMENT, 'Actions/Meeting/ExtendsBaseOutside.php', 10],
            '名前空間の頭だけが同じ別の名前空間' => [self::PLACEMENT, 'Actions/Ai/ToolsLookalike/LookalikeTool.php', 10],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_violation_of_the_agent_tool_rules(string $rule, string $file, int $line): void
    {
        $analysis = self::analyse('AgentTools/violating', self::CONFIG);

        $this->assertDetects($rule, 'AgentTools/violating/'.$file, $line, $analysis);
    }

    public function test_passes_the_allowed_dependencies_operations_and_placements(): void
    {
        $this->assertClean(self::analyse('AgentTools/passing', self::CONFIG));
    }

    public function test_passes_writes_with_the_origin_and_without_the_holder(): void
    {
        $findings = $this->findings(self::analyse('AgentTools/passing-writes', self::CONFIG));
        $others = array_filter($findings, fn (Finding $finding): bool => $finding->ruleId !== self::WRITER_RULE);

        $this->assertNotSame([], $findings, '書き込みの fixture が今の規則の指摘を出さない(fixture が読まれていない)');
        $this->assertSame([], array_values(array_map(fn (Finding $finding): string => $finding->describe(), $others)));
    }
}
