<?php

namespace Tests\Architecture\Policy;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * レコードを書く口と判定を呼び手に任せる口の呼び手の 6 つの一覧と規則(Policy。phpat の規則クラスで、phpstan.neon に phpat.test として登録する。docs の 設計/業務ツール/機械検査.md の
 * 検査 (b))。(1) 登録・更新の口: 業務データの入れ物の CreateRecord・UpdateRecord に依存できるのは、一覧 {@see self::CALLERS} の場所だけ
 * (AI の処理や業務ツールのモジュールが、決めた口を通らずにレコードを書く経路を止める)。(2) 判定しない削除の口: DeleteRecordUnchecked に依存できるのは、
 * 一覧 {@see self::UNCHECKED_DELETE_CALLERS} の場所だけ(主体の判定をしないので、呼び手が操作の判定を済ませる場所に絞る)。
 * (3) 判定しない更新の口: UpdateRecordUnchecked に依存できるのは、一覧 {@see self::UNCHECKED_UPDATE_CALLERS} の場所だけ。
 * (4) 判定しない読み取りの口: RecordShowUnchecked に依存できるのは、一覧 {@see self::UNCHECKED_SHOW_CALLERS} の場所だけ
 * ((3)・(4) は主体の判定を依頼の時点にずらす口なので、AI の 2 つの口に絞る = ADR 0029 の決定 7)。
 * (5) AI の依頼の口: RequestPrompt・RequestTranscription に依存できるのは、一覧 {@see self::REQUEST_CALLERS} の場所だけ
 * (依頼者の権限は呼び手の入口が依頼の時点に確かめるので、入口の確かめを持つ呼び手に絞る = 設計/AI/公開の口.md 3 節の「依頼の時点の確かめ」の (b))。
 * (6) その呼び手だけが使う部品 MeetingAudioRequest に依存できるのは、一覧 {@see self::MEETING_AUDIO_REQUEST_CALLERS} の場所だけ。
 * AI の書き込みの口は (1) の一覧に無く、(3) の判定しない更新だけを使う(ADR 0029 の決定 10)。この規則が保証するのは直接呼べる場所の
 * 制限までで、AI の読み書きを完了の受け手の中だけに絞る守りは、書き込みの口と読む口が持つ(設計/AI/公開の口.md の 3.2・3.3「呼べる時」)。
 *
 * 規則を掛けるのはアプリのコード(App\ と database/ の下)。tests/ は対象外(テストの準備と確かめは書く口を直接呼ぶ)。規則は抑制の注釈では消せない(nonIgnorable)。
 * 一覧に足すときは理由を書く(台帳②と同じ運用。場所の種類 type は namespace(その名前空間と配下)・class(そのクラスだけ)・path(ファイルのパスの正規表現))。
 */
final class RecordWriters
{
    /** レコードを書く口(業務データの入れ物の公開の口) */
    public const WRITE_APIS = ['App\Actions\Records\Api\CreateRecord', 'App\Actions\Records\Api\UpdateRecord'];

    /** 主体の判定をしない削除の口(業務データの入れ物の公開の口) */
    public const UNCHECKED_DELETE_APIS = ['App\Actions\Records\Api\DeleteRecordUnchecked'];

    /** 主体の判定をしない更新の口(業務データの入れ物の公開の口) */
    public const UNCHECKED_UPDATE_APIS = ['App\Actions\Records\Api\UpdateRecordUnchecked'];

    /** 主体の判定をしない読み取りの口(業務データの入れ物の公開の口) */
    public const UNCHECKED_SHOW_APIS = ['App\Queries\Records\Api\RecordShowUnchecked'];

    /** AI の依頼の口(依頼者の権限を判定しない Ai の公開の口) */
    public const REQUEST_APIS = ['App\Actions\Ai\Api\RequestPrompt', 'App\Actions\Ai\Api\RequestTranscription'];

    /** 会議の作成と再作成だけが使う、音声の依頼の部品 */
    public const MEETING_AUDIO_REQUEST_APIS = ['App\Actions\Meeting\MeetingAudioRequest'];

    /**
     * 登録・更新の口に依存できる場所と理由。
     *
     * @var list<array{type: 'namespace'|'class'|'path', place: string, reason: string}>
     */
    public const CALLERS = [
        ['type' => 'namespace', 'place' => 'App\Http\Controllers\Web\Records', 'reason' => '業務データの入れ物の入口(人が画面でレコードを登録・更新する)'],
        ['type' => 'namespace', 'place' => 'App\Actions\Workflow', 'reason' => 'ワークフローの申請・再申請(人の操作で申請フォームのレコードを書く)'],
        [
            'type' => 'class',
            'place' => 'App\Actions\Meeting\MeetingRecordWriter',
            'reason' => '人の操作の会議の作成が会議のレコードを作る 1 か所(docs の 設計/業務ツール/会議/口と検査.md の 12 節)',
        ],
        ['type' => 'path', 'place' => LayerRules::SEEDERS_PATH, 'reason' => '初期データ(seeder は業務処理を呼んでレコードを入れる)'],
    ];

    /**
     * 判定しない削除の口に依存できる場所と理由。
     *
     * @var list<array{type: 'namespace'|'class'|'path', place: string, reason: string}>
     */
    public const UNCHECKED_DELETE_CALLERS = [
        [
            'type' => 'namespace',
            'place' => 'App\Actions\Workflow',
            'reason' => '案件の削除で申請書のレコードを一緒に消す(ADR 0025 の決定 8)。保存期間の自動削除は作るときに足す',
        ],
    ];

    /**
     * 判定しない更新の口に依存できる場所と理由。
     *
     * @var list<array{type: 'namespace'|'class'|'path', place: string, reason: string}>
     */
    public const UNCHECKED_UPDATE_CALLERS = [
        [
            'type' => 'class',
            'place' => 'App\Actions\Ai\Api\WriteRecordFromRun',
            'reason' => 'AI の書き込みの口(種類の完了の受け手の中から、実行の対象のレコードを書く。依頼者の更新は依頼の時点に'
                .'呼び手の入口が判定済み = ADR 0029 の決定 6・7)',
        ],
    ];

    /**
     * 判定しない読み取りの口に依存できる場所と理由。
     *
     * @var list<array{type: 'namespace'|'class'|'path', place: string, reason: string}>
     */
    public const UNCHECKED_SHOW_CALLERS = [
        [
            'type' => 'class',
            'place' => 'App\Actions\Ai\Api\ReadRecordForRun',
            'reason' => 'AI の実行の対象のレコードを読む口(種類の完了の受け手の中から、完了中の実行の対象 1 件を読む。依頼者の閲覧は'
                .'依頼の時点に呼び手の入口が判定済み = ADR 0029 の決定 5・7)',
        ],
    ];

    /**
     * AI の依頼の口に依存できる場所と理由。
     *
     * @var list<array{type: 'namespace'|'class'|'path', place: string, reason: string}>
     */
    public const REQUEST_CALLERS = [
        [
            'type' => 'class',
            'place' => 'App\Actions\Meeting\CreateMeeting',
            'reason' => '会議の作成(人の操作の入口。入口の判定 = docs の 設計/業務ツール/会議/処理.md の 3.11 の表)',
        ],
        [
            'type' => 'class',
            'place' => 'App\Actions\Meeting\RegenerateMinutes',
            'reason' => '議事録の再作成(人の操作の入口。入口の判定 = 同じ 3.11 の表)',
        ],
        [
            'type' => 'class',
            'place' => 'App\Actions\Meeting\MeetingTranscriptionKind',
            'reason' => '会議の文字起こしの種類の完了の受け手(2 段目の議事録の依頼。完了中の実行の依頼者と会議で出す = '
                .'設計/AI/公開の口.md 3 節の「依頼の時点の確かめ」の (c))',
        ],
        [
            'type' => 'class',
            'place' => 'App\Actions\Meeting\MeetingAudioRequest',
            'reason' => '作成と再作成だけが使う音声の依頼の部品(依存できる場所は MEETING_AUDIO_REQUEST_CALLERS で絞る)',
        ],
    ];

    /**
     * 音声の依頼の部品に依存できる場所と理由。
     *
     * @var list<array{type: 'namespace'|'class'|'path', place: string, reason: string}>
     */
    public const MEETING_AUDIO_REQUEST_CALLERS = [
        ['type' => 'class', 'place' => 'App\Actions\Meeting\CreateMeeting', 'reason' => '会議の作成(音声から作る)'],
        ['type' => 'class', 'place' => 'App\Actions\Meeting\RegenerateMinutes', 'reason' => '議事録の再作成(音声を差し替える)'],
    ];

    /** 規則を掛けるアプリのコード(App\ の名前空間と、database/ の下のファイル = 名前空間を持たないマイグレーションを含む) */
    private const APP_NAMESPACE = 'App';

    private const DATABASE_PATH = '#/database/.+\.php$#D';

    #[TestRule]
    public function recordWriterDependencies(): Rule
    {
        return self::dependenciesOnlyFrom(
            self::CALLERS,
            self::WRITE_APIS,
            '機械検査 (b): CreateRecord・UpdateRecord に依存できるのは呼び手の一覧(RecordWriters::CALLERS)の場所だけ',
        );
    }

    #[TestRule]
    public function uncheckedDeleteDependencies(): Rule
    {
        return self::dependenciesOnlyFrom(
            self::UNCHECKED_DELETE_CALLERS,
            self::UNCHECKED_DELETE_APIS,
            '機械検査 (b): DeleteRecordUnchecked に依存できるのは呼び手の一覧(RecordWriters::UNCHECKED_DELETE_CALLERS)の場所だけ',
        );
    }

    #[TestRule]
    public function uncheckedUpdateDependencies(): Rule
    {
        return self::dependenciesOnlyFrom(
            self::UNCHECKED_UPDATE_CALLERS,
            self::UNCHECKED_UPDATE_APIS,
            '機械検査 (b): UpdateRecordUnchecked に依存できるのは呼び手の一覧(RecordWriters::UNCHECKED_UPDATE_CALLERS)の場所だけ',
        );
    }

    #[TestRule]
    public function uncheckedShowDependencies(): Rule
    {
        return self::dependenciesOnlyFrom(
            self::UNCHECKED_SHOW_CALLERS,
            self::UNCHECKED_SHOW_APIS,
            '機械検査 (b): RecordShowUnchecked に依存できるのは呼び手の一覧(RecordWriters::UNCHECKED_SHOW_CALLERS)の場所だけ',
        );
    }

    #[TestRule]
    public function runRequestDependencies(): Rule
    {
        return self::dependenciesOnlyFrom(
            self::REQUEST_CALLERS,
            self::REQUEST_APIS,
            '機械検査 (b): RequestPrompt・RequestTranscription に依存できるのは呼び手の一覧(RecordWriters::REQUEST_CALLERS)の場所だけ',
        );
    }

    #[TestRule]
    public function meetingAudioRequestDependencies(): Rule
    {
        return self::dependenciesOnlyFrom(
            self::MEETING_AUDIO_REQUEST_CALLERS,
            self::MEETING_AUDIO_REQUEST_APIS,
            '機械検査 (b): MeetingAudioRequest に依存できるのは呼び手の一覧(RecordWriters::MEETING_AUDIO_REQUEST_CALLERS)の場所だけ',
        );
    }

    /**
     * アプリのコードのうち、一覧の場所と口のクラス自身の外から、口に依存するものを止める規則。
     *
     * @param  list<array{type: 'namespace'|'class'|'path', place: non-empty-string, reason: string}>  $callers
     * @param  non-empty-list<class-string>  $apis
     */
    private static function dependenciesOnlyFrom(array $callers, array $apis, string $because): Rule
    {
        $apiSelectors = array_map(fn (string $class): SelectorInterface => Selector::classname($class), $apis);

        return PHPat::rule()
            ->classes(Selector::inNamespace(self::APP_NAMESPACE), Selector::withFilepath(self::DATABASE_PATH, true))
            ->excluding(...array_map(self::callerSelector(...), $callers), ...$apiSelectors)
            ->shouldNot()->dependOn()
            ->classes(...$apiSelectors)
            ->because($because)
            ->nonIgnorable();
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
}
