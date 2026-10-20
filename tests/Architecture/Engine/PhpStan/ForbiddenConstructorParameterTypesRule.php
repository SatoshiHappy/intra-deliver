<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * 対象の名前空間のコンストラクタ引数に、禁止した型(とその派生・実装)が現れないこと(汎用。対象の名前空間と禁止する型は phpstan の設定から受ける)。
 *
 * 引数の宣言型(ネイティブの型)を nullable・union・intersection(DNF を含む)の中まで展開し、現れたクラスごとに
 * 禁止した型そのものか、その派生クラス・実装クラスかを見る。昇格プロパティも引数として同じに扱う。
 * trait のコンストラクタは使う側のクラスの中で判定する(名前空間は trait の宣言側)。抑制注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<ClassMethod>
 */
final readonly class ForbiddenConstructorParameterTypesRule implements Rule
{
    private NamespacePrefixes $namespaces;

    /**
     * @param  list<string>  $namespaces  対象の名前空間(その配下すべて)
     * @param  list<string>  $types  禁止する型(クラス名・インターフェース名)
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        array $namespaces,
        private array $types,
    ) {
        $this->namespaces = new NamespacePrefixes($namespaces);
    }

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name->toLowerString() !== '__construct' || ! $this->namespaces->contains($scope->getNamespace())) {
            return [];
        }

        $errors = [];

        foreach ($node->params as $param) {
            $errors = [...$errors, ...$this->check($param, $scope)];
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function check(Param $param, Scope $scope): array
    {
        $errors = [];

        foreach (self::classNames($param->type, $scope) as $className) {
            $forbidden = $this->forbiddenTypeOf($className);

            if ($forbidden === null) {
                continue;
            }

            $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? '$'.$param->var->name : '(引数)';
            $errors[] = RuleErrorBuilder::message(sprintf('コンストラクタ引数 %s の型 %s は禁止した型 %s(またはその派生・実装)。実行時の引数かリゾルバのクロージャで渡す。', $name, $className, $forbidden))
                ->identifier('constructor.forbiddenParameterType')
                ->line($param->getStartLine())
                ->nonIgnorable()
                ->build();
        }

        return $errors;
    }

    /**
     * 型の宣言に現れるクラス名を、nullable・union・intersection の中まで展開して返す(スカラーなどの組み込み型は含めない)。
     *
     * @return list<string>
     */
    private static function classNames(?Node $type, Scope $scope): array
    {
        if ($type instanceof Name) {
            return [$scope->resolveName($type)];
        }

        if ($type instanceof NullableType) {
            return self::classNames($type->type, $scope);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            return array_merge(...array_map(fn (Node $inner): array => self::classNames($inner, $scope), $type->types));
        }

        return [];
    }

    /**
     * クラス $className が禁止した型のどれかそのものか派生・実装なら、その禁止した型を返す。
     */
    private function forbiddenTypeOf(string $className): ?string
    {
        if (! $this->reflectionProvider->hasClass($className)) {
            return in_array($className, $this->types, true) ? $className : null;
        }

        $class = $this->reflectionProvider->getClass($className);

        foreach ($this->types as $type) {
            if ($class->is($type)) {
                return $type;
            }
        }

        return null;
    }
}
