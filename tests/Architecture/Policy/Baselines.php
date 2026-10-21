<?php

namespace Tests\Architecture\Policy;

/**
 * ベースラインを持つ検査(行の長さ・画面側の部品の大きさ・ほかのモジュールの内部への依存の数・経緯の語)の、
 * ベースラインの置き場・台帳⑧の検査名・上限の値(docs の 設計/コード構成/検査/README.md 6.3)。
 */
final class Baselines
{
    public const MAIN_BRANCH = 'main';

    public const LINE_LENGTH_FILE = 'tests/Architecture/baselines/line-length.json';

    public const LINE_LENGTH_CHECK = '#3';

    public const COMPONENT_SIZE_FILE = 'tests/Architecture/baselines/component-size.json';

    public const COMPONENT_SIZE_CHECK = '#3';

    public const MODULE_INTERNALS_FILE = 'tests/Architecture/baselines/module-internals.json';

    public const MODULE_INTERNALS_CHECK = 'B1';

    public const HISTORY_WORDS_FILE = 'tests/Architecture/baselines/history-words.json';

    public const HISTORY_WORDS_CHECK = DocsReferences::EXCLUSION_CHECK;

    /** 行の長さの対象の拡張子のうち、画面側(vp lint の対象と同じ範囲。PHP は .php = phpstan の解析の対象と同じ範囲) */
    public const SCRIPT_EXTENSIONS = ['ts', 'tsx', 'js', 'mjs', 'cjs'];

    /** 画面側の対象から外すパターンを読む設定(lint の節の ignorePatterns) */
    public const VITE_CONFIG = 'vite.config.ts';

    /** 物理行の字数の上限(UTF-8 の文字の数。全角も 1) */
    public const MAX_LINE_LENGTH = 120;

    /** 画面側のファイルと関数の物理行の上限(コメントと空行を含む。PHP のクラスとメソッドの上限 = phpstan.neon の physicalLines と同じ値) */
    public const MAX_FILE_LINES = 300;

    public const MAX_FUNCTION_LINES = 50;

    /** 画面側の部品の大きさを数える oxlint を固定の版で持つ包みのパッケージ(直接依存。この包みが解決する oxlint を起動する) */
    public const OXLINT_WRAPPER = 'vite-plus';
}
