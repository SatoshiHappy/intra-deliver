<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\Api\NoticeTargets;
use App\Support\AccessContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * レコードを指す通知の対象の移り先(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 3 節)。対象の種類は 2 つで、
 * 移り先の種類を対象の種類で決める 1 規則: record = レコードの詳細(欄は開かない。会議の完了・失敗の通知などが使う)、
 * record-comment = レコードの詳細のコメントの欄を開いた形(コメントの通知が使う)。
 * 判定を求める者(今の利用者)が見られるレコードだけを移し、表示名「<業務モジュール> / <表示の項目の値か #id>」を表示の時の今の名前で付ける。
 * 申請の経路だけで見る人には、申請の経路({@see RecordAccessGrantCatalog})が返す画面へ移す(開けないレコードの詳細へ移さないため)。
 * 見られない・無いレコードは入れない(文の下には unavailableText だけが出る。2 種類で同じ)。
 * 起動がタグ {@see NoticeTargets::TAG} で登録する。
 */
class RecordNoticeTargets implements NoticeTargets
{
    /** 対象の種類: レコード(詳細へ。欄は開かない) */
    public const TARGET_TYPE = 'record';

    /** 対象の種類: レコードのコメント(詳細のコメントの欄を開いた形へ) */
    public const TARGET_TYPE_COMMENT = 'record-comment';

    /** 対象の種類 → 詳細の URL のクエリ(開く欄) */
    private const QUERIES = [
        self::TARGET_TYPE => [],
        self::TARGET_TYPE_COMMENT => ['panel' => 'comments'],
    ];

    /** レコードが見られないときの 1 行 */
    public const UNAVAILABLE_TEXT = 'このレコードは閲覧できません。';

    public function __construct(
        private RecordModuleGate $gate,
        private RecordRowAccess $rows,
        private RecordValueReader $reader,
        private RecordAccessGrantCatalog $grants,
    ) {}

    public function targetTypes(): array
    {
        return array_keys(self::QUERIES);
    }

    public function resolve(string $targetType, array $targetIds, AccessContext $context): array
    {
        self::assertKnown($targetType);
        $requester = Requester::fromAccessContext($context);
        $records = Record::query()->whereNull('parent_record_id')->whereIn('id', $targetIds)->get();
        $modules = RecordModule::query()->whereNull('parent_record_module_id')
            ->whereIn('id', $records->pluck('record_module_id')->unique()->all())->get()->keyBy('id');
        $urls = [];

        foreach ($modules as $module) {
            $ids = array_values($records->where('record_module_id', $module->id)->pluck('id')->all());

            foreach ($this->viewableIds($requester, $module, $ids, $context->date) as $id) {
                $parameters = ['module' => $module->code, 'record' => $id, ...self::QUERIES[$targetType]];
                $urls[$id] = route('records.data.show', $parameters);
            }
        }

        $inModules = $records->whereIn('record_module_id', $modules->keys()->all())->modelKeys();
        $rest = array_values(array_diff($inModules, array_keys($urls)));

        foreach ($this->grants->grantedViews($requester, $rest, $context->date) as $id => $url) {
            $urls[$id] = self::withQuery($url, self::QUERIES[$targetType]);
        }

        return $this->links($records, $modules, $urls);
    }

    public function unavailableText(string $targetType): string
    {
        self::assertKnown($targetType);

        return self::UNAVAILABLE_TEXT;
    }

    /**
     * 業務モジュールの read と行の判定で見られるレコード(多数のレコードを 1 回で絞るため。1 件の判定 {@see Api\RecordViewAccess::canViewInModule()} と同じ規則)。
     *
     * @param  list<int>  $recordIds  この業務モジュールの親の無いレコード
     * @return list<int>
     */
    private function viewableIds(
        Requester $requester,
        RecordModule $module,
        array $recordIds,
        DateTimeInterface $date,
    ): array {
        if (! $this->gate->allows($requester, $module, RecordModuleAction::Read, $date)) {
            return [];
        }

        $records = Record::query()->where('record_module_id', $module->id)->whereIn('id', $recordIds);

        $visible = $this->rows->constrain($records, $requester, $module, $date)->pluck('id')->all();

        return array_values(array_map('intval', $visible));
    }

    /**
     * 移り先のあるレコードに、表示名を表示の時の今の名前で付ける。
     *
     * @param  Collection<int, Record>  $records
     * @param  Collection<int, RecordModule>  $modules  業務モジュールの id → 業務モジュール
     * @param  array<int, string>  $urls  レコードの id → 移り先
     * @return array<int, NoticeTargetLink>
     */
    private function links(Collection $records, Collection $modules, array $urls): array
    {
        $links = [];

        foreach ($modules as $module) {
            $titleField = RecordField::query()
                ->where('record_module_id', $module->id)->where('is_title', true)->first();

            foreach ($records->where('record_module_id', $module->id) as $record) {
                if (isset($urls[$record->id])) {
                    $title = $this->reader->title($record, $titleField) ?? "#{$record->id}";
                    $links[$record->id] = new NoticeTargetLink($urls[$record->id], "{$module->name} / {$title}");
                }
            }
        }

        return $links;
    }

    /**
     * 申請の経路の画面に、開く欄のクエリを足す(クエリが無ければそのまま)。
     *
     * @param  array<string, string>  $query
     */
    private static function withQuery(string $url, array $query): string
    {
        return $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * @throws InvalidArgumentException 答えない対象の種類(束ねる所の誤り)
     */
    private static function assertKnown(string $targetType): void
    {
        if (! array_key_exists($targetType, self::QUERIES)) {
            throw new InvalidArgumentException("レコードは対象の種類 {$targetType} に答えない");
        }
    }
}
