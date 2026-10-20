<?php

namespace Tests\Architecture\Engine\PhpStan;

use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * 公開の口の型の規則({@see ForbiddenPublicApiTypesRule}・{@see ForbiddenPublicApiPropertyTypesRule})が共有する判定(汎用。値は phpstan の設定から受ける):
 * クラスが公開の口(<層>\<モジュール>\<段の名前> とその配下)にあるか、型に禁じた型(とその派生)が現れるか。
 */
final readonly class PublicApiTypes
{
    /**
     * @param  list<string>  $layers  公開の口を持つ層の名前空間(例 = App\Actions)
     * @param  string  $segment  公開の口の段の名前(例 = Api)
     * @param  list<class-string>  $types  禁じる型(クラス・interface。派生を含む)
     */
    public function __construct(private array $layers, private string $segment, private array $types) {}

    /**
     * クラス名 $class が公開の口の名前空間(<層>\<モジュール>\<段の名前> とその配下)にあるか。
     */
    public function contains(string $class): bool
    {
        foreach ($this->layers as $layer) {
            $prefix = trim($layer, '\\').'\\';
            $parts = str_starts_with($class, $prefix) ? explode('\\', substr($class, strlen($prefix))) : [];

            if (count($parts) >= 3 && $parts[1] === $this->segment) {
                return true;
            }
        }

        return false;
    }

    /**
     * 型 $type(PHPDoc を含めて解決した型。配列・総称の型引数・union の中に現れるクラスも数える)に現れる最初の禁じた型。無ければ null。
     *
     * @return class-string|null
     */
    public function forbiddenIn(Type $type): ?string
    {
        foreach ($type->getReferencedClasses() as $class) {
            foreach ($this->types as $forbidden) {
                if ((new ObjectType($forbidden))->isSuperTypeOf(new ObjectType($class))->yes()) {
                    return $forbidden;
                }
            }
        }

        return null;
    }
}
