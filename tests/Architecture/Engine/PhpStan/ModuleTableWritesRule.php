<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;

/**
 * モジュールのモデルの書き込みは、同じモジュールの持ち主の置き場からだけ(汎用。置き場・書き込みのメソッド・モデルの型引数は phpstan の設定から受ける)。
 *
 * モデル = モデルの置き場 <models>\<M>\… のクラス(<M> がモジュール)。持ち主の置き場 = <owners>\<M> とその配下。
 * 書き込み = 型(派生を含む)ごとのメソッド名(大小文字を区別しない)。インスタンスのメソッド呼び出しは受け手の型、静的呼び出しはクラスで判定する。
 * 和の型(null を含む型を含む)は各型に分けて判定し、式で書いた静的呼び出しのクラスは class-string ならその対象のクラスで判定する。
 * 書く表のモデル = 型引数の表($modelTemplates。型 → 型引数の名前)にある型なら、その型引数、無い型なら受け手そのもの。
 * モジュールの分からないモデル(置き場の直下・置き場の外)と、型が静的に決まらない呼び出しは判定しない。照合しないディレクトリも判定しない。
 * 抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<Expr>
 */
final readonly class ModuleTableWritesRule implements Rule
{
    private CodeRoots $roots;

    /**
     * @param  list<string>  $roots  アプリのルート(絶対パス)
     * @param  list<string>  $classDirectories  クラスを置くディレクトリ(ルート起点。{@see CodeRoots})
     * @param  list<string>  $exemptDirectories  照合しないディレクトリ(ルート起点)
     * @param  string  $modelsNamespace  モデルの置き場(<models>\<M>\…)
     * @param  string  $ownersNamespace  書いてよい置き場(<owners>\<M>)
     * @param  array<class-string, list<string>>  $writes  型 → 書き込みのメソッド名
     * @param  array<class-string, string>  $modelTemplates  型 → 書く表のモデルを持つ型引数の名前
     */
    public function __construct(
        array $roots,
        array $classDirectories,
        private array $exemptDirectories,
        private string $modelsNamespace,
        private string $ownersNamespace,
        private array $writes,
        private array $modelTemplates,
    ) {
        $this->roots = new CodeRoots($roots, $classDirectories);
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall) || ! $node->name instanceof Identifier) {
            return [];
        }

        $file = $scope->getFile();

        if ($this->roots->relative($file) === null || $this->roots->isUnder($file, $this->exemptDirectories)) {
            return [];
        }

        $method = $node->name->toString();
        $errors = [];

        foreach ($this->writtenModels(self::receiverOf($node, $scope), $method) as $model) {
            $module = $this->moduleOf($model);

            if ($module !== null && ! $this->isOwner($scope->getNamespace(), $module)) {
                $errors[] = $this->error($model, $module, $method, $node);
            }
        }

        return $errors;
    }

    /**
     * インスタンスのメソッド呼び出しは受け手の型、静的呼び出しはクラス(self・static・parent は書いた場所のクラスで解決する。
     * 式で書いたクラスは、オブジェクトならその型、class-string ならその対象の型)。
     */
    private static function receiverOf(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $node, Scope $scope): Type
    {
        if (! $node instanceof Expr\StaticCall) {
            return $scope->getType($node->var);
        }

        return $node->class instanceof Name ? $scope->resolveTypeByName($node->class) : $scope->getType($node->class)->getObjectTypeOrClassStringObjectType();
    }

    /**
     * 受け手 $receiver の $method が書き込みなら、書く表のモデルのクラス名(和の型は各型で判定する)。
     *
     * @return list<string>
     */
    private function writtenModels(Type $receiver, string $method): array
    {
        $models = [];

        foreach (TypeUtils::flattenTypes($receiver) as $part) {
            array_push($models, ...$this->writtenModelsOf($part, $method));
        }

        return array_values(array_unique($models));
    }

    /**
     * 和でない型 $receiver の $method が書き込みなら、書く表のモデルのクラス名。
     *
     * @return list<string>
     */
    private function writtenModelsOf(Type $receiver, string $method): array
    {
        $models = [];

        foreach ($this->writes as $type => $methods) {
            if (! in_array(strtolower($method), array_map(strtolower(...), $methods), true) || ! (new ObjectType($type))->isSuperTypeOf($receiver)->yes()) {
                continue;
            }

            $model = isset($this->modelTemplates[$type]) ? $receiver->getTemplateType($type, $this->modelTemplates[$type]) : $receiver;
            array_push($models, ...$model->getObjectClassNames());
        }

        return $models;
    }

    /**
     * モデルのモジュール(<models>\<M>\… の <M>)。置き場の外・直下なら null。
     */
    private function moduleOf(string $model): ?string
    {
        $prefix = trim($this->modelsNamespace, '\\').'\\';

        if (! str_starts_with($model, $prefix)) {
            return null;
        }

        $segments = explode('\\', substr($model, strlen($prefix)));

        return count($segments) >= 2 ? $segments[0] : null;
    }

    private function isOwner(?string $namespace, string $module): bool
    {
        return (new NamespacePrefixes([trim($this->ownersNamespace, '\\').'\\'.$module]))->contains($namespace);
    }

    private function error(string $model, string $module, string $method, Node $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message("モジュール {$module} の表({$model})を書けるのは {$this->ownersNamespace}\\{$module} の業務処理だけ: {$model}::{$method}()。")
            ->identifier('moduleTableWrite')
            ->line($node->getStartLine())
            ->nonIgnorable()
            ->build();
    }
}
