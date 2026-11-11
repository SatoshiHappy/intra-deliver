<?php

namespace Tests\Architecture\Policy;

use Tests\Architecture\Engine\InternalDependencies;

/**
 * ほかのモジュールの内部への依存の数(tests/Architecture/ModuleInternalsTest.php)の依存する側と内部の置き場
 * (数える範囲 = docs の 設計/コード構成/検査/README.md 6.2・設計/コード構成/README.md 2 節)。移行中の一覧でないモジュールの業務処理・読み取りの内部は、
 * 公開の口の規則({@see PublicApiRules})が止めるので重ねない。走査するクラスの置き場 = {@see SourceDirectories::APP}(手元だけのリポジトリの移した版を含む)。
 */
final class ModuleInternals
{
    /**
     * 依存する側として走査するファイルの glob(アプリのルート起点)。
     *
     * @return list<string>
     */
    public static function filePatterns(): array
    {
        return array_map(fn (string $directory): string => $directory.'/**/*.php', SourceDirectories::APP);
    }

    public static function dependencies(): InternalDependencies
    {
        $business = array_map(
            fn (string $layer): array => [
                'place' => $layer,
                'modules' => Modules::TRANSITIONAL,
                'public' => Modules::PUBLIC_API,
            ],
            [LayerRules::ACTIONS, LayerRules::QUERIES],
        );
        $isEntrance = fn (string $place): bool => str_starts_with($place, LayerRules::ENTRANCE.'\\');
        $entrances = array_map(
            fn (string $place): array => ['place' => $place, 'modules' => Modules::names(), 'public' => null],
            array_values(array_filter(Modules::NAMESPACES, $isEntrance)),
        );
        $internalPlaces = [...$business, ...$entrances];

        return new InternalDependencies(
            Modules::names(),
            Modules::NAMESPACES,
            $internalPlaces,
            SourceDirectories::APP_NAMESPACE,
            SourceDirectories::APP,
        );
    }
}
