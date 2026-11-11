<?php

namespace Tests\Architecture\Policy;

/**
 * 道で対象を選ぶ検査が見るクラスの置き場(アプリのルート起点)。手元だけのリポジトリの移した版は、アプリのルートのリンク
 * {@see self::PRIVATE_ROOT} の下に `app`・`tests` と同じ形で置き、追跡するコードと同じ検査を当てる(docs の ADR 0039 の決定 8)。
 * `app` を見る検査は {@see self::APP} の全部を、`tests` を見る検査は {@see self::TESTS} の全部を見る(リンクが無い木では、無い置き場を飛ばす)。
 * 同じ一覧を書く設定(composer.json の autoload・phpstan.neon の codeLayout・sideEffects・singleClassFile)は
 * SourceDirectoriesTest が照合する。
 */
final class SourceDirectories
{
    /** 手元だけのリポジトリへのリンク */
    public const PRIVATE_ROOT = 'ai-private';

    /** アプリの名前空間の根と、そのクラスを置くディレクトリ */
    public const APP_NAMESPACE = 'App';

    public const APP = ['app', self::PRIVATE_ROOT.'/app'];

    /** テストの名前空間の根と、そのクラスを置くディレクトリ */
    public const TESTS_NAMESPACE = 'Tests';

    public const TESTS = ['tests', self::PRIVATE_ROOT.'/tests'];

    /** 道で対象を選ぶ走査がたどるリンク(ほかのリンクはたどらず、在れば走査が落ちる = Engine の SourceTree) */
    public const LINKS = [self::PRIVATE_ROOT];
}
