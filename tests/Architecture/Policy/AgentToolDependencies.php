<?php

namespace Tests\Architecture\Policy;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * 会話の道具と判定の関数が使える口の一覧と規則(Policy。docs の 設計/業務ツール/機械検査.md の検査 (b) と、規則・一覧・理由の正本
 * 設計/AI/道具と権限/道具の判定と今の権限.md の 10.4)。
 *
 * クラスの単位の依存の規則(phpat)に操作の単位の規則(allowedOperation・reflectiveCall)を重ねるのは、許した口のクラスの中の主体の判定をしない
 * 操作を依存の規則では止められないため。置き場の規則(interfacePlacement)は、依存の規則が名前空間の外に置いた実装を止めないため。
 * 規則は抑制の注釈では消せない(nonIgnorable)。一覧に足すときは理由を書く(台帳②と同じ運用)。
 */
final class AgentToolDependencies
{
    /** 汎用の道具の名前空間 */
    public const GENERIC_TOOL_NAMESPACE = 'App\Actions\Ai\Tools';

    /**
     * 製品の道具の名前空間(製品の道具とその部品)。足すときは行の脇に理由を書く。
     *
     * @var list<string>
     */
    public const PRODUCT_TOOL_NAMESPACES = [];

    /** 道具の名前空間(汎用と製品) */
    public const TOOL_NAMESPACES = [self::GENERIC_TOOL_NAMESPACE, ...self::PRODUCT_TOOL_NAMESPACES];

    /** 判定の関数と ③ の共通の部品と包みの名前空間 */
    public const TOOL_CALL_NAMESPACE = 'App\Actions\Ai\ToolCalls';

    /** 置き場を決める道具の約束(継いだ interface を含めて実装を数える) */
    public const TOOL_CONTRACTS = ['App\Actions\Ai\Api\AgentTool'];

    /**
     * 道具の名前空間が依存できる Ai の約束と値の型と部品。
     *
     * @var list<array{class: string, reason: string}>
     */
    public const TOOL_AI_TYPES = [
        ['class' => 'App\Actions\Ai\Api\AgentTool', 'reason' => '道具の約束(10.1)'],
        ['class' => 'App\Actions\Ai\Api\AgentRecordTool', 'reason' => '業務モジュールを読み書きする道具の約束(呼び出しが指す業務モジュールを答える = 10.1)'],
        ['class' => 'App\Actions\Ai\Api\AgentToolScope', 'reason' => '渡せる範囲の値の型(10.1)'],
        ['class' => 'App\Actions\Ai\Api\AgentToolSubject', 'reason' => '判定の主体の文脈(値の型。作るのは ③ の共通の部品 = 10.4)'],
        ['class' => 'App\Actions\Ai\Api\AgentToolResult', 'reason' => '道具の結果の値の型(10.1)'],
        ['class' => 'App\Actions\Ai\Api\RecordMarker', 'reason' => '結果に添えるレコードの印の値の型(10.2)'],
        ['class' => 'App\Actions\Ai\Api\PriorToolCall', 'reason' => '形の外の検証が受ける、同じステップの前の呼び出しの値の型(10.1)'],
        ['class' => 'App\Queries\Ai\Api\RecordMarkers', 'reason' => '③ の印の部品(外から来る値の印の形を直す = 10.2)'],
        ['class' => 'App\Enums\Ai\AiToolAction', 'reason' => '道具の約束の要る権限の列挙(10.1)'],
        ['class' => 'App\Enums\Ai\AiInstructionTemplate', 'reason' => '道具の約束の大きい結果の定型の列挙(10.7)'],
        ['class' => 'App\Enums\Ai\AiToolCallDecision', 'reason' => '前の呼び出しの判定の列挙(10.1)'],
    ];

    /**
     * 道具の名前空間が依存できる口と、呼べる操作(10.4 の (1) の表。行の判定を通る集計の口は足すときに足す)。
     *
     * @var list<array{class: string, methods: list<string>, reason: string}>
     */
    public const TOOL_OPERATIONS = [
        [
            'class' => 'App\Queries\Records\Api\RecordViewAccess',
            'methods' => ['canView'],
            'reason' => '閲覧の判定(読む・直すの呼ばれた時)',
        ],
        ['class' => 'App\Queries\Records\Api\RecordListQuery', 'methods' => ['list'], 'reason' => '一覧の読み取り(行の判定で絞る)'],
        [
            'class' => 'App\Actions\Records\Api\CreateRecord',
            'methods' => ['execute'],
            'reason' => '判定する登録(作るの書く時。契機 = AI)',
        ],
        [
            'class' => 'App\Actions\Records\Api\UpdateRecord',
            'methods' => ['execute'],
            'reason' => '判定する更新(直すの書く時。持ち主の鍵を渡さない = TOOL_KEY_ARGUMENTS)',
        ],
        ['class' => 'App\Queries\Records\Api\RecordDisplayQuery', 'methods' => ['show'], 'reason' => '詳細の値(閲覧の判定の後だけ)'],
        [
            'class' => 'App\Queries\Records\Api\RecordFormQuery',
            'methods' => ['draftValues'],
            'reason' => '書く値を画面の初期値の形にする(判定の関数が業務モジュールの権限を見た後だけ)',
        ],
        [
            'class' => 'App\Queries\Records\Api\RecordActivityQuery',
            'methods' => ['comments', 'revisions'],
            'reason' => 'コメントの一覧と更新履歴の一覧(閲覧の判定の後だけ)',
        ],
        [
            'class' => 'App\Queries\Records\Api\RecordAttachmentQuery',
            'methods' => ['forRecord'],
            'reason' => '添付の一覧(閲覧の判定の後だけ。件数だけを返す countFor は含めない)',
        ],
    ];

    /**
     * 道具の名前空間が依存できる値の型と列挙(10.4 の (1) の操作の引数と戻り値の物。クラスの名前で並べる)。
     *
     * @var list<array{class: string, reason: string}>
     */
    public const TOOL_VALUE_TYPES = [
        ['class' => 'App\Actions\Records\Api\RecordInput', 'reason' => '登録・更新の入力'],
        ['class' => 'App\Actions\Records\Api\RecordDetailRowInput', 'reason' => '登録・更新の入力の明細の行'],
        ['class' => 'App\Actions\Records\Api\RecordRevisionOrigin', 'reason' => '登録・更新の契機(AI = 10.5 の更新履歴)'],
        ['class' => 'App\Enums\Records\RecordModuleAction', 'reason' => '業務モジュールの操作の列挙'],
        ['class' => 'App\Queries\Authz\Api\Requester', 'reason' => '判定を求める者(読み取りの口が受ける)'],
        ['class' => 'App\Support\AccessContext', 'reason' => '判定の入力の値の型(書き込みの口が受ける)'],
    ];

    /**
     * 呼べる操作の鍵の引数(無いか null の字句であること)。
     *
     * @var list<array{class: string, method: string, parameter: string, position: int, reason: string}>
     */
    public const TOOL_KEY_ARGUMENTS = [
        [
            'class' => 'App\Actions\Records\Api\UpdateRecord',
            'method' => 'execute',
            'parameter' => 'holder',
            'position' => 5,
            'reason' => '持ち主の鍵を渡す更新は (1) の操作でない(ワークフローが保持する間の書き込みだけが渡す鍵)',
        ],
    ];

    /** 道具の名前空間が呼べる操作を絞る Records の公開の口の型の名前空間(継いだ型を含む) */
    public const RECORDS_API_NAMESPACES = ['App\Actions\Records\Api', 'App\Queries\Records\Api'];

    /**
     * 判定の関数の名前空間が依存できる Records と Master の口(10.4 の (2) の表)。
     *
     * @var list<array{class: string, reason: string}>
     */
    public const TOOL_CALL_APIS = [
        [
            'class' => 'App\Queries\Records\Api\RecordFormQuery',
            'reason' => '業務モジュールの権限の判定と定義の読み取り(allows・definition だけ)',
        ],
        [
            'class' => 'App\Queries\Records\Api\RecordModuleDirectory',
            'reason' => '業務モジュールの一覧・表示の止め・項目と選択肢の名前の読み取り(レコードを返さない)',
        ],
        ['class' => 'App\Queries\Master\Api\MasterCandidates', 'reason' => 'ユーザー・組織の名前の解決'],
        ['class' => 'App\Queries\Master\Api\EffectiveUsers', 'reason' => '依頼者の有効・ユーザーの id の確かめ'],
        ['class' => 'App\Queries\Master\Api\DepartmentTree', 'reason' => '組織の候補に添える上位の組織・組織の id の確かめ'],
        ['class' => 'App\Queries\Master\Api\MasterNames', 'reason' => '確かめた id の名前と主所属'],
    ];

    /** 判定の関数の名前空間の依存を (2) に絞る Records と Master の名前空間 */
    public const TOOL_CALL_GUARDED_NAMESPACES = [
        'App\Actions\Records',
        'App\Queries\Records',
        'App\Actions\Master',
        'App\Queries\Master',
    ];

    /**
     * 判定の関数の名前空間が呼べる RecordFormQuery の操作(同じクラスのレコードの値を返す判定しない操作を止める)。
     *
     * @var list<array{class: string, methods: list<string>, reason: string}>
     */
    public const TOOL_CALL_OPERATIONS = [
        [
            'class' => 'App\Queries\Records\Api\RecordFormQuery',
            'methods' => ['allows', 'definition'],
            'reason' => '業務モジュールの権限の判定と定義の読み取り(10.4 の (2))',
        ],
    ];

    /**
     * 操作の単位の規則の組(PHPStan の自作の規則 allowedOperation に渡す。phpstan.neon)。
     * 厳しい組(道具の名前空間)は、名前が字句でない・受け手の型が決まらない・引数の展開・字句でない callable を受ける呼び出しも止める
     * (届く操作と鍵の引数を決められないため = 機械検査 (b))。
     */
    public const OPERATION_GROUPS = [
        [
            'namespaces' => self::TOOL_NAMESPACES,
            'receiverNamespaces' => self::RECORDS_API_NAMESPACES,
            'receiverClasses' => [],
            'operations' => self::TOOL_OPERATIONS,
            'keyArguments' => self::TOOL_KEY_ARGUMENTS,
            'strict' => true,
        ],
        [
            'namespaces' => [self::TOOL_CALL_NAMESPACE],
            'receiverNamespaces' => [],
            'receiverClasses' => ['App\Queries\Records\Api\RecordFormQuery'],
            'operations' => self::TOOL_CALL_OPERATIONS,
            'keyArguments' => [],
            'strict' => false,
        ],
    ];

    /** 字句でない callable を受ける関数(厳しい組で止める) */
    public const CALLABLE_FUNCTIONS = ['call_user_func', 'call_user_func_array'];

    /**
     * 字句でない callable を受けるメソッド(型は継いだ型を含む。厳しい組で止める)。
     *
     * @var list<array{class: string, methods: list<string>, reason: string}>
     */
    public const CALLABLE_METHODS = [
        [
            'class' => 'Illuminate\Contracts\Container\Container',
            'methods' => ['call'],
            'reason' => 'コンテナの call(app()->call を含む)',
        ],
        ['class' => 'Illuminate\Support\Facades\App', 'methods' => ['call'], 'reason' => 'コンテナのファサードの call'],
    ];

    /**
     * 厳しい組で止める、リフレクションの生成(名前の頭が classPrefix の組み込みのクラスと、それを継いだクラスの new)と、
     * 受け手が calls の type を継ぐ型の、methods の呼び出し(null = すべて。名前と受け手を字句で決められずに呼べるため = 機械検査 (b))。
     */
    public const REFLECTIVE_CALLS = [
        'classPrefix' => 'Reflection',
        'calls' => [
            ['type' => 'Reflector', 'methods' => null],
            ['type' => 'Closure', 'methods' => ['bind', 'bindTo', 'call', 'fromCallable']],
        ],
    ];

    /** 置き場の規則が照合しないディレクトリ(理由 = テストの偽の道具は配備に載らず、10.4 の依存の規則を掛ける対象でない) */
    public const PLACEMENT_EXEMPT_DIRECTORIES = ['tests', 'ai-private/tests'];

    #[TestRule]
    public function agentToolDependencies(): Rule
    {
        $classes = array_column([...self::TOOL_AI_TYPES, ...self::TOOL_OPERATIONS, ...self::TOOL_VALUE_TYPES], 'class');

        return PHPat::rule()
            ->classes(...self::namespaceSelectors(self::TOOL_NAMESPACES))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->excluding(...self::namespaceSelectors(self::TOOL_NAMESPACES), ...self::classSelectors($classes))
            ->because('機械検査 (b): 道具の名前空間が依存できる App のクラスは AgentToolDependencies の一覧(10.4 の (1))だけ')
            ->nonIgnorable();
    }

    #[TestRule]
    public function agentToolCallDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::TOOL_CALL_NAMESPACE))
            ->shouldNot()->dependOn()
            ->classes(...self::namespaceSelectors(self::TOOL_CALL_GUARDED_NAMESPACES))
            ->excluding(...self::classSelectors(array_column(self::TOOL_CALL_APIS, 'class')))
            ->because('機械検査 (b): 判定の関数の名前空間が依存できる Records と Master の口は TOOL_CALL_APIS(10.4 の (2))だけ')
            ->nonIgnorable();
    }

    /**
     * @param  list<non-empty-string>  $namespaces
     * @return list<SelectorInterface>
     */
    private static function namespaceSelectors(array $namespaces): array
    {
        return array_map(fn (string $namespace): SelectorInterface => Selector::inNamespace($namespace), $namespaces);
    }

    /**
     * @param  list<non-empty-string>  $classes
     * @return list<SelectorInterface>
     */
    private static function classSelectors(array $classes): array
    {
        return array_map(fn (string $class): SelectorInterface => Selector::classname($class), $classes);
    }
}
