<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Tests\Architecture\Engine\LedgerMatch;
use Tests\Architecture\Engine\Values;
use UnexpectedValueException;

/**
 * 副作用は台帳の許可表に載せた場所でだけ使える(汎用。判定する呼び出しと台帳は phpstan の設定から受ける)。
 *
 * 場所 = 呼び出しを書いたファイルの名前空間。許可表(種類 → 名前空間の一覧)のどれかと同じか、その配下なら可。
 * 配線のファイル({@see CodeRoots})は許可表に載せられないので、いつも不可。照合しないディレクトリ(DB の初期化・テスト・検査の道具)は判定しない。
 * 判定する呼び出し = 種類ごとの、型(派生・実装・trait の利用を含む)→ メソッド名(* = すべて)と、関数名(バッククォートは shell_exec)。
 * インスタンスのメソッド呼び出しは受け手の型、静的呼び出しはクラス、`new` は作るオブジェクトの型(式で書いたクラス名・匿名クラスの祖先を含む)の
 * コンストラクタ(メソッド名 __construct)で判定する。コンストラクタは、型ごとに __construct を明示したときだけ数える(* には一致させない)。
 * 呼び出しの名前と静的に決まる型だけを見る: 引数の中身(SQL・URL・コマンド名・開くモード)と、型やメソッド名が静的に決まらない呼び出し
 * (動的なメソッド名・型の分からない受け手・高階のプロキシ)は判定しない。抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<Expr>
 */
final class SideEffectsRule implements Rule
{
    private const ANY_METHOD = '*';

    /** `new` を判定するときのメソッド名。`*` には一致させず、型ごとに明示したときだけ数える(生成そのものが副作用になる型) */
    private const CONSTRUCTOR = '__construct';

    private readonly CodeRoots $roots;

    /** @var array<string, NamespacePrefixes> 種類 → 許可した場所 */
    private readonly array $allowed;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点)。この外のファイルが配線のファイル
     * @param  list<string>  $exemptDirectories  照合しないディレクトリ(ルート起点)
     * @param  array<string, array{methods: array<string, list<string>>, functions: list<string>}>  $calls  種類 → 判定する呼び出し
     * @param  string  $ledgerFile  許可表を持つ台帳(配列を返す PHP のファイル)
     * @param  string  $ledgerKey  台帳の中の許可表のキー
     *
     * @throws UnexpectedValueException 台帳が読めないとき・許可表の種類が判定する呼び出しの種類と一致しないとき
     */
    public function __construct(
        array $roots,
        array $classDirectories,
        private readonly array $exemptDirectories,
        private readonly array $calls,
        string $ledgerFile,
        string $ledgerKey,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        $this->roots = new CodeRoots($roots, $classDirectories);
        $this->allowed = self::readLedger($ledgerFile, $ledgerKey, array_keys($calls));
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();

        if ($this->roots->relative($file) === null || $this->roots->isUnder($file, $this->exemptDirectories)) {
            return [];
        }

        $errors = [];

        foreach ($this->sideEffects($node, $scope) as [$kind, $call]) {
            if ($this->roots->isWiring($file)) {
                $errors[] = self::error("配線のファイルで副作用({$kind})を使っている: {$call}(配線のファイルは台帳②に載せられない)", $kind, $node);
            } elseif (! $this->allowed[$kind]->contains($scope->getNamespace())) {
                $errors[] = self::error("副作用({$kind})を台帳②に無い場所 ".($scope->getNamespace() ?? '名前空間の外')." で使っている: {$call}", $kind, $node);
            }
        }

        return $errors;
    }

    /**
     * ノードが副作用の呼び出しなら、その種類と呼び出しの表記。
     *
     * @return list<array{string, string}>
     */
    private function sideEffects(Expr $node, Scope $scope): array
    {
        return match (true) {
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall => $this->methodCalls($scope->getType($node->var)->getObjectClassReflections(), $node->name),
            $node instanceof Expr\StaticCall => $this->methodCalls($this->staticClasses($node, $scope), $node->name),
            $node instanceof Expr\New_ => $this->methodCalls($scope->getType($node)->getObjectClassReflections(), new Identifier(self::CONSTRUCTOR)),
            $node instanceof Expr\FuncCall => $node->name instanceof Name ? $this->functionCalls($this->reflectionProvider->resolveFunctionName($node->name, $scope)) : [],
            $node instanceof Expr\ShellExec => $this->functionCalls('shell_exec'),
            default => [],
        };
    }

    /**
     * @param  list<ClassReflection>  $classes
     * @return list<array{string, string}>
     */
    private function methodCalls(array $classes, Node $name): array
    {
        if (! $name instanceof Identifier) {
            return [];
        }

        $found = [];

        foreach ($classes as $class) {
            foreach ($this->calls as $kind => $calls) {
                if ($this->matchesMethod($class, $name->toString(), $calls['methods'])) {
                    $found[$kind] = [$kind, $class->getName().'::'.$name->toString().'()'];
                }
            }
        }

        return array_values($found);
    }

    /**
     * @param  array<string, list<string>>  $methods  型 → メソッド名
     */
    private function matchesMethod(ClassReflection $class, string $method, array $methods): bool
    {
        foreach ($methods as $type => $names) {
            $lowered = array_map(strtolower(...), $names);

            $any = in_array(self::ANY_METHOD, $names, true) && strtolower($method) !== self::CONSTRUCTOR;

            if (($any || in_array(strtolower($method), $lowered, true)) && $this->isOfType($class, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{string, string}>
     */
    private function functionCalls(?string $function): array
    {
        if ($function === null) {
            return [];
        }

        $found = [];

        foreach ($this->calls as $kind => $calls) {
            if (in_array(strtolower($function), array_map(strtolower(...), $calls['functions']), true)) {
                $found[] = [$kind, $function.'()'];
            }
        }

        return $found;
    }

    /**
     * 静的呼び出しのクラス(self・static・parent は書いた場所のクラスで解決する)。
     *
     * @return list<ClassReflection>
     */
    private function staticClasses(Expr\StaticCall $node, Scope $scope): array
    {
        if (! $node->class instanceof Name) {
            return $scope->getType($node->class)->getObjectClassReflections();
        }

        return $this->namedClasses($node->class, $scope);
    }

    /**
     * 名前で書いたクラス(self・static・parent は書いた場所のクラスで解決する)。
     *
     * @return list<ClassReflection>
     */
    private function namedClasses(Name $name, Scope $scope): array
    {
        $class = $scope->resolveName($name);

        return $this->reflectionProvider->hasClass($class) ? [$this->reflectionProvider->getClass($class)] : [];
    }

    /**
     * クラス $class が型 $type(クラス・interface・trait)か、その派生・実装・利用か。
     */
    private function isOfType(ClassReflection $class, string $type): bool
    {
        if ($class->getName() === $type || $class->isSubclassOf($type) || $class->implementsInterface($type)) {
            return true;
        }

        foreach ([$class, ...$class->getParents()] as $candidate) {
            if ($candidate->hasTraitUse($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $kinds
     * @return array<string, NamespacePrefixes>
     *
     * @throws UnexpectedValueException
     */
    private static function readLedger(string $ledgerFile, string $ledgerKey, array $kinds): array
    {
        $ledger = Values::table(is_file($ledgerFile) ? require $ledgerFile : null, "台帳 {$ledgerFile}");
        $table = Values::table($ledger[$ledgerKey] ?? null, "台帳 {$ledgerFile} の {$ledgerKey}");
        $difference = LedgerMatch::equal(array_map('strval', array_keys($table)), $kinds);

        if ($difference->extra !== [] || $difference->missing !== []) {
            throw new UnexpectedValueException("台帳 {$ledgerFile} の {$ledgerKey} の種類が、判定する呼び出しの種類と一致しない: ".$difference->describe());
        }

        $allowed = [];

        foreach ($kinds as $kind) {
            $allowed[$kind] = new NamespacePrefixes(Values::strings($table[$kind], "台帳 {$ledgerFile} の {$ledgerKey}.{$kind}"));
        }

        return $allowed;
    }

    private static function error(string $message, string $kind, Node $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message.'。')
            ->identifier('sideEffect.'.$kind)
            ->line($node->getStartLine())
            ->nonIgnorable()
            ->build();
    }
}
