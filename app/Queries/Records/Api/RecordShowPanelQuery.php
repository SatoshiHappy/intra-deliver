<?php

namespace App\Queries\Records\Api;

use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordCommentQuery;
use App\Queries\Records\RecordHoldCatalog;
use App\Queries\Records\RecordRevisionRows;
use App\Queries\Records\RecordShowSectionCatalog;
use App\Queries\Records\RecordValueReader;
use App\Queries\Upload\Api\UploadLimits;
use App\Support\AccessContext;
use LogicException;

/**
 * レコードの詳細と申請の詳細が共有するサイドパネルの読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の
 * 2 節・6.2 節): 帯の項目と業務ツールの節の名札と件数、開いた欄の中身。どちらも閲覧の判定({@see RecordViewAccess})が偽なら null
 * (明細の行の id・ほかの業務モジュールのレコードも偽)。ダウンロード・プレビューの URL は持たない(ルートの名前は入口が持つ)。
 *
 * @phpstan-import-type AttachmentItem from RecordAttachmentQuery
 * @phpstan-import-type ReferenceValue from RecordValueReader
 * @phpstan-import-type CommentsContent from RecordCommentQuery
 * @phpstan-import-type RevisionRow from RecordRevisionRows
 * @phpstan-import-type SectionPresence from RecordShowSectionCatalog
 * @phpstan-import-type SectionContent from RecordShowSectionCatalog
 *
 * @phpstan-type PanelItem array{key: string, name: string, shortName: string, count: int|null,
 *     kind: 'attachments'|'comments'|'revisions'|'section'}
 * @phpstan-type AttachmentRow array{id: int, uploadId: int, name: string, size: int, mime: string, previewable: bool,
 *     attachedBy: ReferenceValue|null, attachedAt: string|null, purgeDueOn: string|null}
 * @phpstan-type AttachmentsContent array{kind: 'attachments', rows: list<AttachmentRow>, hasMore: bool,
 *     notice: string|null, guide: string}
 * @phpstan-type RevisionsContent array{kind: 'revisions', rows: list<RevisionRow>, hasMore: bool}
 * @phpstan-type SectionPanelContent array{kind: 'section', code: string, content: SectionContent|null}
 */
class RecordShowPanelQuery
{
    /** Records の欄の 1 回に読む件数(「さらに表示」の 1 回も同じ) */
    public const PAGE_SIZE = 5;

    public const UNUSED_NOTICE = 'この業務モジュールでは、添付ファイルを追加・削除できません。付いている添付ファイルは表示できます。';

    public const HELD_NOTICE = '申請中のため、添付ファイルを追加・削除できません。付いている添付ファイルは表示できます。';

    /** Records の欄(キー → 正式の名前・帯の名前。帯の名前の規則 = 詳細の画面/README.md の 2.1 節) */
    private const PANES = [
        'attachments' => ['添付ファイル', '添付'],
        'comments' => ['コメント', 'コメント'],
        'revisions' => ['更新履歴', '更新履歴'],
    ];

    public function __construct(
        private RecordViewAccess $access,
        private RecordAttachmentQuery $attachments,
        private RecordCommentQuery $comments,
        private RecordRevisionRows $revisions,
        private RecordShowSectionCatalog $sections,
        private RecordHoldCatalog $holds,
        private RecordValueReader $reader,
        private UploadLimits $limits,
    ) {}

    /**
     * 帯の項目(添付ファイル → 更新履歴 → 業務ツールの節)と、業務ツールの節の名札と件数(1 回の呼び出しで 2 つの props に分ける)。
     *
     * @return array{panel: array{items: list<PanelItem>}, sections: list<SectionPresence>}|null
     *
     * @throws LogicException 業務ツールの節の名札の誤り
     */
    public function panel(AccessContext $context, string $moduleCode, int $recordId): ?array
    {
        if (! $this->canView($context, $moduleCode, $recordId)) {
            return null;
        }

        $items = [];
        $attachmentCount = $this->attachments->countFor($recordId);

        if ($this->showsAttachments($moduleCode, $attachmentCount)) {
            $items[] = $this->pane('attachments', $attachmentCount);
        }

        $items[] = $this->pane('comments', $this->comments->countFor($recordId));
        $items[] = $this->pane('revisions', null);
        $sections = $this->sections->presences($context, $moduleCode, $recordId);

        foreach ($sections as $section) {
            $items[] = [
                'key' => $section['code'],
                'name' => $section['heading'],
                'shortName' => $section['shortHeading'],
                'count' => $section['count'],
                'kind' => 'section',
            ];
        }

        return ['panel' => ['items' => $items], 'sections' => $sections];
    }

    /**
     * 開いた欄の中身。キーが帯に出る Records の欄か業務ツールの節の code でなければ null。Records の欄は $beforeId より前の 5 件と続きの有無、
     * 業務ツールの節はその節の section() だけを呼ぶ(presence() を呼ばない)。
     *
     * @param  int|null  $beforeId  「さらに表示」の時、今の一覧の最後の行の id
     * @return AttachmentsContent|CommentsContent|RevisionsContent|SectionPanelContent|null
     *
     * @throws LogicException 業務ツールの節の名札の誤り
     */
    public function content(
        AccessContext $context,
        string $moduleCode,
        int $recordId,
        string $key,
        ?int $beforeId,
    ): ?array {
        if (! $this->canView($context, $moduleCode, $recordId)) {
            return null;
        }

        return match ($key) {
            'attachments' => $this->attachmentsContent($context, $moduleCode, $recordId, $beforeId),
            'comments' => $this->comments->content($context, $recordId, self::PAGE_SIZE, $beforeId),
            'revisions' => ['kind' => 'revisions', ...$this->revisions->page(
                Requester::fromAccessContext($context),
                $recordId,
                self::PAGE_SIZE,
                $beforeId,
                $context->date,
                $context->localeCode,
            )],
            default => $this->sectionContent($context, $moduleCode, $recordId, $key),
        };
    }

    /**
     * @return AttachmentsContent|null 欄が帯に出ないとき(使用しない業務モジュールで添付が無い)は null
     */
    private function attachmentsContent(
        AccessContext $context,
        string $moduleCode,
        int $recordId,
        ?int $beforeId,
    ): ?array {
        $enabled = $this->isAttachmentEnabled($moduleCode);

        if (! $enabled && $this->attachments->countFor($recordId) === 0) {
            return null;
        }

        $page = $this->attachments->forRecord($recordId, self::PAGE_SIZE, $beforeId);
        $userIds = array_values(array_unique(array_filter(array_column($page['items'], 'userId'), 'is_int')));
        $users = $this->reader->users($userIds, $context->date, $context->localeCode);

        return [
            'kind' => 'attachments',
            'rows' => array_map(fn (array $item) => $this->attachmentRow($item, $users), $page['items']),
            'hasMore' => $page['hasMore'],
            'notice' => match (true) {
                ! $enabled => self::UNUSED_NOTICE,
                $this->holds->blockingHold($recordId, null) !== null => self::HELD_NOTICE,
                default => null,
            },
            'guide' => $this->limits->guide(),
        ];
    }

    /**
     * @param  AttachmentItem  $item
     * @param  array<int, ReferenceValue>  $users
     * @return AttachmentRow
     */
    private function attachmentRow(array $item, array $users): array
    {
        $userId = $item['userId'];
        unset($item['userId']);

        return [...$item, 'attachedBy' => $userId === null ? null : $users[$userId] ?? null];
    }

    /**
     * @return SectionPanelContent|null
     *
     * @throws LogicException 業務ツールの節の名札の誤り
     */
    private function sectionContent(AccessContext $context, string $moduleCode, int $recordId, string $code): ?array
    {
        $section = $this->sections->content($context, $moduleCode, $recordId, $code);

        return $section === null ? null : ['kind' => 'section', ...$section];
    }

    /**
     * @param  'attachments'|'comments'|'revisions'  $key
     * @return PanelItem
     */
    private function pane(string $key, ?int $count): array
    {
        [$name, $shortName] = self::PANES[$key];

        return [
            'key' => $key,
            'name' => $name,
            'shortName' => $shortName,
            'count' => $count,
            'kind' => $key,
        ];
    }

    /**
     * 添付ファイルの欄を出すか(使用する業務モジュールか、添付が付いている = 添付ファイル/レコードの添付.md の 2 節)。
     */
    private function showsAttachments(string $moduleCode, int $count): bool
    {
        return $count > 0 || $this->isAttachmentEnabled($moduleCode);
    }

    private function isAttachmentEnabled(string $moduleCode): bool
    {
        return RecordModule::query()->where('code', $moduleCode)->whereNull('parent_record_module_id')
            ->value('is_attachment_enabled') === true;
    }

    private function canView(AccessContext $context, string $moduleCode, int $recordId): bool
    {
        return $this->access->canView(Requester::fromAccessContext($context), $moduleCode, $recordId, $context->date);
    }
}
