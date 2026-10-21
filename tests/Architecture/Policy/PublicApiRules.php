<?php

namespace Tests\Architecture\Policy;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * 公開の口の規則(Policy。phpat の規則クラスで、phpstan.neon に phpat.test として登録する。docs の 設計/コード構成/README.md 2 節「公開の口」、
 * 検査/README.md 6.2): モジュールの業務処理・読み取りに、ほかのモジュールは App\Actions\<M>\Api・App\Queries\<M>\Api の下にだけ依存できる。
 * 内部(Api の下以外)に依存できるのは、同じモジュールの層・起動(App\Providers)・seeder(database/seeders)で、どれもほかのモジュールの選択
 * ({@see Modules::selectors()})に入らないので規則に当たらない。tests/ は対象外。モデル・列挙への依存はモジュールの規則のまま
 * ({@see LayerRules::moduleDependencies()})。
 *
 * 移行中の一覧({@see Modules::TRANSITIONAL})のモジュールには掛けない(載っている間は内部への依存を許す)。宣言のほかのモジュールは最初から対象。
 * 規則は抑制の注釈では消せない(nonIgnorable)。
 */
final class PublicApiRules
{
    /**
     * @param  array<string, list<string>>  $modules  モジュールの宣言(自己証明の fixture 用の phpstan 設定だけが差し替える)
     * @param  list<string>  $transitionalModules  移行中の一覧(同じく fixture 用の設定だけが差し替える)
     */
    public function __construct(
        private readonly array $modules = Modules::DECLARATION,
        private readonly array $transitionalModules = Modules::TRANSITIONAL,
    ) {}

    /**
     * 依存される側のモジュールごとに 1 規則。規則 ID の末尾が、依存される側のモジュールの名前になる。
     *
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function publicApiDependencies(): iterable
    {
        $names = array_keys($this->modules);

        foreach (array_diff($names, $this->transitionalModules) as $module) {
            $others = array_merge(...array_map(Modules::selectors(...), array_values(array_diff($names, [$module]))));

            yield $module => PHPat::rule()
                ->classes(...$others)
                ->shouldNot()->dependOn()
                ->classes(Selector::inNamespace(self::place(LayerRules::ACTIONS, $module)), Selector::inNamespace(self::place(LayerRules::QUERIES, $module)))
                ->excluding(
                    Selector::inNamespace(self::place(LayerRules::ACTIONS, $module).'\\'.Modules::PUBLIC_API),
                    Selector::inNamespace(self::place(LayerRules::QUERIES, $module).'\\'.Modules::PUBLIC_API),
                )
                ->because('コード構成 2 節: ほかのモジュールの業務処理・読み取りには Api の下にだけ依存する(移行中の一覧のモジュールを除く)')
                ->nonIgnorable();
        }
    }

    /**
     * @return non-empty-string
     */
    private static function place(string $layer, string $module): string
    {
        return $layer.'\\'.$module;
    }
}
