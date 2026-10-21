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
use InvalidArgumentException;

/**
 * レコードを指す通知の対象の移り先(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 3 節)。対象の種類は 2 つで、
 * 移り先の種類を対象の種類で決める 1 規則: record = レコードの詳細(欄は開かない。会議の完了・失敗の通知などが使う)、
 * record-comment = レコードの詳細のコメントの欄を開いた形(コメントの通知が使う)。
 * 判定を求める者(今の利用者)が見られるレコードだけを移し、表示名「<業務モジュール> / <表示の項目の値か #id>」を表示の時の今の名前で付ける。
 * 見られない・無いレコードは入れない(文の下には unavailableText だけが出る。2 種類で同じ)。起動がタグ {@see NoticeTargets::TAG} で登録する。
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
            ->whereIn('id', $records->pluck('record_module_id')->unique()->all())->get();
        $links = [];

        foreach ($modules as $module) {
            $inModule = $records->where('record_module_id', $module->id);
            $ids = array_values($inModule->pluck('id')->all());
            $viewable = array_flip($this->viewableIds($requester, $module, $ids, $context->date));
            $titleField = RecordField::query()
                ->where('record_module_id', $module->id)->where('is_title', true)->first();

            foreach ($inModule as $record) {
                if (isset($viewable[$record->id])) {
                    $title = $this->reader->title($record, $titleField) ?? "#{$record->id}";
                    $url = route('records.data.show', [
                        'module' => $module->code, 'record' => $record->id, ...self::QUERIES[$targetType],
                    ]);
                    $links[$record->id] = new NoticeTargetLink($url, "{$module->name} / {$title}");
                }
            }
        }

        return $links;
    }

    public function unavailableText(string $targetType): string
    {
        self::assertKnown($targetType);

        return self::UNAVAILABLE_TEXT;
    }

    /**
     * 見られるレコード(この規則の 1 か所。業務モジュールの read と行の判定 = {@see Api\RecordViewAccess::canView()} と同じ。
     * 申請の経路を足すときはここに足す)。
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
     * @throws InvalidArgumentException 答えない対象の種類(束ねる所の誤り)
     */
    private static function assertKnown(string $targetType): void
    {
        if (! array_key_exists($targetType, self::QUERIES)) {
            throw new InvalidArgumentException("レコードは対象の種類 {$targetType} に答えない");
        }
    }
}
