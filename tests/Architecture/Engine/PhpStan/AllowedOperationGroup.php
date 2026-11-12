<?php

namespace Tests\Architecture\Engine\PhpStan;

use PHPStan\Reflection\ClassReflection;

/**
 * 許す操作の規則の組 1 つ(汎用。{@see AllowedOperationsRule} が phpstan の設定から受ける)。
 *
 * 掛ける場所 = 呼び出しを書いたクラスの名前空間。受け手 = 名前空間(配下を含む)かクラスで指す型と、それを継いだ・実装した型。
 * 許す操作 = 型(継いだ型を含む)→ メソッド名。鍵の引数 = 操作ごとに、無いか null の字句でなければならない引数(名前と位置)。
 * クラスとメソッドの名前は大小文字を区別しない(PHP と同じ)。
 */
final readonly class AllowedOperationGroup
{
    private NamespacePrefixes $callers;

    private NamespacePrefixes $receiverNamespaces;

    /** @var list<string> 小文字のクラス名 */
    private array $receiverClasses;

    /**
     * @param  array{namespaces: list<string>, receiverNamespaces: list<string>, receiverClasses: list<string>,
     *     operations: list<array{class: string, methods: list<string>}>,
     *     keyArguments: list<array{class: string, method: string, parameter: string, position: int}>,
     *     strict: bool}  $group
     */
    public function __construct(private array $group)
    {
        $this->callers = new NamespacePrefixes($group['namespaces']);
        $this->receiverNamespaces = new NamespacePrefixes($group['receiverNamespaces']);
        $this->receiverClasses = array_map(self::key(...), $group['receiverClasses']);
    }

    /** 名前の決まらない呼び出し・受け手の決まらない呼び出し・引数の展開・字句でない callable を受ける呼び出しも止めるか */
    public function isStrict(): bool
    {
        return $this->group['strict'];
    }

    public function covers(?string $namespace): bool
    {
        return $this->callers->contains($namespace);
    }

    public function describeCallers(): string
    {
        return $this->callers->describe();
    }

    /**
     * 型 $class(その祖先を含む)が受け手の型に当たるか。
     */
    public function reaches(ClassReflection $class): bool
    {
        foreach ($class->getAncestors() as $ancestor) {
            $name = $ancestor->getName();
            $namespace = str_contains($name, '\\') ? substr($name, 0, (int) strrpos($name, '\\')) : null;

            $isClass = in_array(self::key($name), $this->receiverClasses, true);

            if ($isClass || $this->receiverNamespaces->contains($namespace)) {
                return true;
            }
        }

        return false;
    }

    public function allows(ClassReflection $class, string $method): bool
    {
        foreach ($this->group['operations'] as $operation) {
            $methods = array_map(self::key(...), $operation['methods']);

            if (self::isA($class, $operation['class']) && in_array(self::key($method), $methods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 操作 $class::$method の鍵の引数。
     *
     * @return list<array{parameter: string, position: int}>
     */
    public function keyArgumentsOf(ClassReflection $class, string $method): array
    {
        $keys = [];

        foreach ($this->group['keyArguments'] as $key) {
            if (self::isA($class, $key['class']) && self::key($method) === self::key($key['method'])) {
                $keys[] = ['parameter' => $key['parameter'], 'position' => $key['position']];
            }
        }

        return $keys;
    }

    /**
     * 型 $class(その祖先を含む)が $name か。
     */
    public static function isA(ClassReflection $class, string $name): bool
    {
        foreach (array_keys($class->getAncestors()) as $ancestor) {
            if (self::key($ancestor) === self::key($name)) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $name): string
    {
        return strtolower(ltrim($name, '\\'));
    }
}
