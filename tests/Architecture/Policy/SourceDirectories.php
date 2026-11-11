<?php

namespace Tests\Architecture\Policy;

/**
 * 道で対象を選ぶ検査が見るクラスの置き場(アプリのルート起点)。手元だけのリポジトリの移した版は、アプリのルートのリンク
 * {@see self::PRIVATE_ROOT} の下に `app`・`tests` と同じ形で置き、追跡するコードと同じ検査を当てる(docs の ADR 0039 の決定 8)。
 * `app` を見る検査は {@see self::APP} の全部を、`tests` を見る検査は {@see self::TESTS} の全部を見る(リンクが無い木では、無い置き場を飛ばす)。
 * 同じ一覧を書く設定(composer.json の autoload・phpstan.neon の codeLayout・sideEffects・singleClassFile)と、手元のテストを流す
 * 手元の phpunit の設定({@see self::PRIVATE_PHPUNIT_CONFIG})の suite は SourceDirectoriesTest が照合する。
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

    /** 手元だけのリポジトリのテストを流す phpunit の設定(手元のリポジトリの根。suite のディレクトリは設定のファイルの実体の置き場から解く) */
    public const PRIVATE_PHPUNIT_CONFIG = self::PRIVATE_ROOT.'/phpunit.xml';

    /** 道で対象を選ぶ走査がたどるリンク(ほかのリンクはたどらず、在れば走査が落ちる = Engine の SourceTree) */
    public const LINKS = [self::PRIVATE_ROOT];
}
