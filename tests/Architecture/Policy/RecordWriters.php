<?php

namespace Tests\Architecture\Policy;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * レコードを書く口の呼び手の一覧と規則(Policy。phpat の規則クラスで、phpstan.neon に phpat.test として登録する。docs の 設計/業務ツール/機械検査.md の
 * 検査 (b)): 業務データの入れ物の CreateRecord・UpdateRecord に依存できるのは、一覧 {@see self::CALLERS} の場所だけ(AI の処理や業務ツールの
 * モジュールが、決めた口を通らずにレコードを書く経路を止める)。この規則が保証するのは直接呼べる場所の制限までで、AI の書き込みを
 * 完了の受け手の中から依頼者の権限で書く守りは、書き込みの口が持つ(設計/AI/公開の口.md の 3 節「AI の書き込みの保証」・3.2「呼べる時」)。
 *
 * 規則を掛けるのはアプリのコード(App\ と database/ の下)。tests/ は対象外(テストの準備と確かめは書く口を直接呼ぶ)。規則は抑制の注釈では消せない(nonIgnorable)。
 * 一覧に足すときは理由を書く(台帳②と同じ運用。場所の種類 type は namespace(その名前空間と配下)・class(そのクラスだけ)・path(ファイルのパスの正規表現))。
 */
final class RecordWriters
{
    /** レコードを書く口(業務データの入れ物の公開の口) */
    public const WRITE_APIS = ['App\Actions\Records\Api\CreateRecord', 'App\Actions\Records\Api\UpdateRecord'];

    /**
     * 書く口に依存できる場所と理由。
     *
     * @var list<array{type: 'namespace'|'class'|'path', place: string, reason: string}>
     */
    public const CALLERS = [
        ['type' => 'namespace', 'place' => 'App\Http\Controllers\Web\Records', 'reason' => '業務データの入れ物の入口(人が画面でレコードを登録・更新する)'],
        ['type' => 'namespace', 'place' => 'App\Actions\Workflow', 'reason' => 'ワークフローの申請・再申請(人の操作で申請フォームのレコードを書く)'],
        [
            'type' => 'class',
            'place' => 'App\Actions\Ai\Api\WriteRecordFromRun',
            'reason' => 'AI の書き込みの口(種類の完了の受け手の中から、実行の依頼者の権限で、実行の対象のレコードを書く = ADR 0019 の決定 4)',
        ],
        [
            'type' => 'class',
            'place' => 'App\Actions\Meeting\MeetingRecordWriter',
            'reason' => '人の操作の会議の作成が会議のレコードを作る 1 か所(docs の 設計/業務ツール/会議/口と検査.md の 12 節)',
        ],
        ['type' => 'path', 'place' => LayerRules::SEEDERS_PATH, 'reason' => '初期データ(seeder は業務処理を呼んでレコードを入れる)'],
    ];

    /** 規則を掛けるアプリのコード(App\ の名前空間と、database/ の下のファイル = 名前空間を持たないマイグレーションを含む) */
    private const APP_NAMESPACE = 'App';

    private const DATABASE_PATH = '#/database/.+\.php$#D';

    #[TestRule]
    public function recordWriterDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::APP_NAMESPACE), Selector::withFilepath(self::DATABASE_PATH, true))
            ->excluding(...self::callers(), ...self::writeApis())
            ->shouldNot()->dependOn()
            ->classes(...self::writeApis())
            ->because('機械検査 (b): CreateRecord・UpdateRecord に依存できるのは呼び手の一覧(RecordWriters::CALLERS)の場所だけ')
            ->nonIgnorable();
    }

    /**
     * @return list<SelectorInterface>
     */
    private static function callers(): array
    {
        return array_map(self::callerSelector(...), self::CALLERS);
    }

    /**
     * @param  array{type: 'namespace'|'class'|'path', place: non-empty-string, reason: string}  $caller
     */
    private static function callerSelector(array $caller): SelectorInterface
    {
        return match ($caller['type']) {
            'namespace' => Selector::inNamespace($caller['place']),
            'class' => Selector::classname($caller['place']),
            'path' => Selector::withFilepath($caller['place'], true),
        };
    }

    /**
     * @return list<SelectorInterface>
     */
    private static function writeApis(): array
    {
        return array_map(fn (string $class): SelectorInterface => Selector::classname($class), self::WRITE_APIS);
    }
}
