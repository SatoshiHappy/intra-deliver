<?php

namespace Tests\Architecture\Policy;

use Tests\Architecture\Engine\Placement;

/**
 * 置き場の規則(docs の 設計/コード構成/README.md 1 節・4 節・5 節)= 置き場ごとに、直下に置けるディレクトリとファイル。
 * 照合本体は Engine の {@see Placement}、実行は tests/Architecture/PlacementTest.php。
 */
final class CodeLayout
{
    /** app/Http の直下に置けるディレクトリ(コード構成 1 節。ファイルは置かない) */
    public const HTTP_DIRECTORIES = ['Controllers', 'Requests', 'Middleware'];

    /** app/Http/Controllers の直下に置けるディレクトリとファイル(コード構成 1 節) */
    public const CONTROLLERS_DIRECTORIES = ['Web', 'Api'];

    public const CONTROLLERS_FILES = ['Controller.php'];

    /** 直下にファイルを置ける <M> の置き場(入口(共通)のミドルウェア = Laravel の既定の置き場。コード構成 1 節) */
    public const MODULE_PLACES_WITH_FILES = ['App\Http\Middleware'];

    /** resources/views に置けるファイル = Inertia の根のテンプレートだけ(コード構成 4 節。ディレクトリは置かない) */
    public const VIEWS_FILES = ['app.blade.php'];

    /** resources/js/components の直下に <M> に加えて置けるディレクトリ = 基礎の UI 部品(コード構成 5 節) */
    public const COMPONENTS_EXTRA_DIRECTORIES = ['ui'];

    /**
     * 置き場の規則(アプリのルート起点のパス → 置けるディレクトリとファイル。files が null ならファイルは何でも置ける)。
     *
     * @param  list<string>  $baseDirectories  台帳① app/ の直下に置けるディレクトリ
     * @param  list<string>  $modules  宣言したモジュール({@see Modules::names()})
     * @return array<string, array{directories: list<string>, files: list<string>|null}>
     */
    public static function placements(array $baseDirectories, array $modules): array
    {
        $rules = [
            'app' => ['directories' => $baseDirectories, 'files' => []],
            'app/Http' => ['directories' => self::HTTP_DIRECTORIES, 'files' => []],
            'app/Http/Controllers' => ['directories' => self::CONTROLLERS_DIRECTORIES, 'files' => self::CONTROLLERS_FILES],
        ];

        foreach (Modules::NAMESPACES as $namespace) {
            $rules[self::directoryOf($namespace)] = [
                'directories' => $modules,
                'files' => in_array($namespace, self::MODULE_PLACES_WITH_FILES, true) ? null : [],
            ];
        }

        $rules[Modules::PAGES_DIRECTORY] = ['directories' => $modules, 'files' => []];
        $rules[Modules::COMPONENTS_DIRECTORY] = ['directories' => [...$modules, ...self::COMPONENTS_EXTRA_DIRECTORIES], 'files' => null];
        $rules[Modules::LIB_DIRECTORY] = ['directories' => $modules, 'files' => null];
        $rules[Modules::HOOKS_DIRECTORY] = ['directories' => $modules, 'files' => null];
        $rules['resources/views'] = ['directories' => [], 'files' => self::VIEWS_FILES];

        return $rules;
    }

    /**
     * App\ の名前空間のディレクトリ(アプリのルート起点。App\ = app/)。
     */
    public static function directoryOf(string $namespace): string
    {
        return 'app/'.str_replace('\\', '/', substr($namespace, strlen('App\\')));
    }
}
