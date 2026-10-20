/*
 * dependency-cruiser(import の向きの検査。MIT)の設定。実行 = npm run deps:check(N 段 scripts/ci/front が型検査の後に走らせる)。
 * 止める向きの正本は docs の 設計/コード構成/README.md 5 節(「止める向き」の一覧)。規則はその一覧の 1 項目を 1 規則に写し、
 * 加えて循環を止める(K15)。
 * 加えて、テスト専用の手助け(`*.testing.ts`)は `*.test.ts` からだけ import できる。生成物と型(routes・actions・wayfinder・types)は規則の外(グラフに入れない)。
 * resources/js の直下のファイル(app.tsx)= 入口の組み立てで、どこにでも依存できる(from に入れる規則が無い)。
 *
 * 区分(同 5 節):
 *   ui           = components/ui/ の下。
 *   <M>/         = components・lib・hooks の直下のディレクトリのうち ui でないもの(先頭が大文字。pages/ と同じ名前)。pages/<M>/ も同じ名前。
 *                  宣言したモジュールの名前と一致することは Architecture テスト(PlacementTest)が照合する。
 *   共通の直下   = components・lib・hooks の直下のファイル。
 */
const SOURCE = '^resources/js/';
// components・lib・hooks の <M>/ (先頭が大文字のディレクトリ)
const SHARED_MODULE = `${SOURCE}(?:components|lib|hooks)/[A-Z][A-Za-z0-9]*/`;

/** @type {import('dependency-cruiser').IConfiguration} */
module.exports = {
    forbidden: [
        {
            name: 'no-circular',
            comment: '循環する import を作らない(K15)',
            severity: 'error',
            from: {},
            to: { circular: true },
        },
        {
            name: 'ui-only-to-ui-and-shared-lib-hooks',
            comment:
                'components/ui/ は ui 自身と、共通の直下の lib・hooks にだけ依存できる(共通の直下の components と <M>/ に依存しない)',
            severity: 'error',
            from: { path: `${SOURCE}components/ui/` },
            to: {
                path: [
                    `${SOURCE}components/(?!ui/)`,
                    `${SOURCE}(?:lib|hooks)/[A-Z][A-Za-z0-9]*/`,
                ],
            },
        },
        {
            name: 'shared-not-to-module',
            comment:
                'components・lib・hooks の直下(共通)は <M>/ に依存しない(共通の部品は業務モジュールを知らない)',
            severity: 'error',
            from: {
                path: `${SOURCE}(?:components|lib|hooks)/(?!ui/)(?![A-Z][A-Za-z0-9]*/)`,
            },
            to: { path: SHARED_MODULE },
        },
        {
            name: 'module-not-to-other-module',
            comment:
                '<M>/(components・lib・hooks・pages の下)は別の <M>/ に依存しない(同じ <M>/・共通・ui/ にだけ依存できる)',
            severity: 'error',
            from: {
                path: `${SOURCE}(?:pages|components|lib|hooks)/([A-Z][A-Za-z0-9]*)/`,
            },
            to: {
                path: `${SOURCE}(?:pages|components|lib|hooks)/[A-Z][A-Za-z0-9]*/`,
                pathNot: `${SOURCE}(?:pages|components|lib|hooks)/$1/`,
            },
        },
        {
            name: 'shared-ui-module-not-to-layouts-or-pages',
            comment:
                '共通・ui/・<M>/(components・lib・hooks)は layouts・pages に依存しない(組み立ての層へ上らない)',
            severity: 'error',
            from: { path: `${SOURCE}(?:components|lib|hooks)/` },
            to: { path: `${SOURCE}(?:layouts|pages)/` },
        },
        {
            name: 'layouts-not-to-pages',
            comment: 'layouts は pages に依存しない',
            severity: 'error',
            from: { path: `${SOURCE}layouts/` },
            to: { path: `${SOURCE}pages/` },
        },
        {
            name: 'testing-helpers-only-from-tests',
            comment:
                '名前が .testing.ts のテスト専用の手助けは、*.test.ts からだけ import できる(テストの既定値を本番のコードに入れない)',
            severity: 'error',
            from: { pathNot: '\\.test\\.tsx?$' },
            to: { path: '\\.testing\\.tsx?$' },
        },
        {
            name: 'lib-hooks-not-to-components',
            comment:
                'lib・hooks(直下・<M>/ とも)は components(ui/ を含む)に依存しない(層の向き)',
            severity: 'error',
            from: { path: `${SOURCE}(?:lib|hooks)/` },
            to: { path: `${SOURCE}components/` },
        },
    ],
    options: {
        // 規則の外 = 生成物と型。グラフに入れない
        exclude: {
            path: `${SOURCE}(?:routes|actions|wayfinder|types)/`,
        },
        doNotFollow: { path: 'node_modules' },
        tsPreCompilationDeps: true,
        tsConfig: { fileName: 'tsconfig.json' },
        enhancedResolveOptions: {
            exportsFields: ['exports'],
            conditionNames: ['import', 'require', 'node', 'default', 'types'],
            extensions: ['.ts', '.tsx', '.d.ts', '.js', '.jsx', '.json'],
        },
        reporterOptions: { text: { highlightFocused: true } },
    },
};
