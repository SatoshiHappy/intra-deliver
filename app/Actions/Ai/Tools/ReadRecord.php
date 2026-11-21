<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordMarker;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use App\Queries\Records\Api\RecordActivityQuery;
use App\Queries\Records\Api\RecordAttachmentQuery;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 汎用の道具「読む」(docs の 設計/AI/道具と権限/README.md 10.2 の読むの判定・読むが返す範囲)。閲覧の判定を先に呼び、通らなければほかの口を
 * 呼ばない(判定の前に値を読むと、見えないレコードの有無が漏れる = 設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4)。判定の後の口が
 * 見つからない例外を投げたら、消えたのがレコードか業務モジュールかを問わず見つからないにする(10.3 の B3 の競合)。
 */
final class ReadRecord implements AgentRecordTool
{
    /** 返す部分(`parts` で選ぶ。いつも返す物 = 印・id・表示の名前・作成と更新の日時と人・参照制限は含めない) */
    public const PARTS = ['values', 'details', 'comments', 'revisions', 'attachments'];

    /** 明細の行の 1 ページの件数(明細の表ごと。コメントと同じ) */
    public const DETAILS_PER_PAGE = 20;

    /** コメントの 1 ページの件数 */
    public const COMMENTS_PER_PAGE = 20;

    /** 更新履歴の 1 ページの件数 */
    public const REVISIONS_PER_PAGE = 10;

    /** 返す添付の数の上限(続きは返さず、残りの有無だけを添える) */
    public const ATTACHMENT_LIMIT = 100;

    public function __construct(
        private readonly RecordViewAccess $access,
        private readonly RecordDisplayQuery $display,
        private readonly RecordActivityQuery $activity,
        private readonly RecordAttachmentQuery $attachments,
    ) {}

    public function name(): string
    {
        return 'ai_read_record';
    }

    public function aliases(): array
    {
        return [];
    }

    public function displayName(): string
    {
        return '読み取り';
    }

    public function description(AgentToolScope $scope): string
    {
        return RecordToolDescriptions::read($scope);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('業務モジュールのコード。')->required(),
            'id' => $schema->integer()->description('レコードの id。')->required(),
            'parts' => $schema->array()->items($schema->string()->enum(self::PARTS))->description('返す部分の列(省くと全部)。'),
            'details_page' => $schema->integer()->description('明細の行のページの番号(1 から。明細の表ごと)。'),
            'comments_page' => $schema->integer()->description('コメントのページの番号(1 から)。'),
            'revisions_page' => $schema->integer()->description('更新履歴のページの番号(1 から)。'),
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isConcurrencySafe(): bool
    {
        return true;
    }

    public function requiredAction(): AiToolAction
    {
        return AiToolAction::View;
    }

    public function targetModules(): ?array
    {
        return null;
    }

    public function validate(array $arguments, AgentToolScope $scope, array $priorCalls): ?string
    {
        return null;
    }

    public function maxResultLength(): ?int
    {
        return null;
    }

    public function largeResultTemplate(): AiInstructionTemplate
    {
        return AiInstructionTemplate::LargeResultRead;
    }

    public function moduleOf(array $arguments): ?string
    {
        return is_string($arguments['module'] ?? null) ? $arguments['module'] : null;
    }

    public function partyArguments(): array
    {
        return [];
    }

    public function execute(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): AgentToolResult {
        $module = $this->moduleOf($arguments) ?? '';
        $id = is_int($arguments['id'] ?? null) ? $arguments['id'] : 0;

        if (! $this->access->canView($subject->requester(), $module, $id, $subject->date())) {
            return AgentToolResult::notFound();
        }

        try {
            $titleCode = RecordToolValues::titleCode($scope->modules[$module]['definition'] ?? []);

            return $this->read($module, $id, $arguments, $subject, $titleCode);
        } catch (ModelNotFoundException) {
            return AgentToolResult::notFound();
        }
    }

    /**
     * 閲覧の判定を通ったレコードの、いつも返す物と、選んだ部分。選ばない部分の口は呼ばない。表示の名前は実行の始めの定義の表示の項目で
     * 付け(説明と記録の名前を探すとそろえる = 10.4)、値の名前は今の定義で付ける(詳細の画面と同じ)。
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ModelNotFoundException 判定の後にレコードか業務モジュールが消えたとき
     */
    private function read(
        string $module,
        int $id,
        array $arguments,
        AgentToolSubject $subject,
        ?string $titleCode,
    ): AgentToolResult {
        $parts = self::parts($arguments);
        $detail = $this->display->detail($subject->requester(), $id, $subject->date(), $subject->localeCode());
        $record = $detail['record'];
        $fields = RecordToolValues::fields($detail['module']);
        $name = RecordToolValues::displayName($record['values'], $titleCode, $id);
        $data = [
            'record' => new RecordMarker($module, $id),
            'id' => $id,
            'name' => $name,
            'createdAt' => $record['createdAt'],
            'createdBy' => RecordToolValues::shown($record['createdBy']),
            'updatedAt' => $record['updatedAt'],
            'updatedBy' => RecordToolValues::shown($record['updatedBy']),
            'rowAccess' => RecordToolValues::shown($record['rowAccess']),
        ];
        $page = fn (string $key): int => max(1, is_int($arguments[$key] ?? null) ? $arguments[$key] : 1);
        $details = fn (): mixed => ReadRecordDetails::page($fields, $record['details'], $page('details_page'));

        foreach ($parts as $part) {
            $data[$part] = match ($part) {
                'values' => RecordToolValues::shown(self::named($fields, $record['values'])),
                'details' => RecordToolValues::shown($details()),
                'comments' => $this->comments($id, $page('comments_page'), $subject),
                'revisions' => $this->revisions($id, $page('revisions_page'), $subject),
                'attachments' => $this->attachmentItems($id),
            };
        }

        return new AgentToolResult($data, [['module' => $module, 'id' => $id, 'name' => $name]]);
    }

    /**
     * 選んだ部分(PARTS の並び。省くと全部)。
     *
     * @param  array<string, mixed>  $arguments
     * @return list<value-of<self::PARTS>>
     */
    private static function parts(array $arguments): array
    {
        $chosen = is_array($arguments['parts'] ?? null) ? $arguments['parts'] : self::PARTS;

        return array_values(array_filter(self::PARTS, fn (string $part): bool => in_array($part, $chosen, true)));
    }

    /** コメントの 1 ページ */
    private function comments(int $id, int $page, AgentToolSubject $subject): mixed
    {
        $date = $subject->date();
        $comments = $this->activity->comments($id, $page, self::COMMENTS_PER_PAGE, $date, $subject->localeCode());

        return RecordToolValues::shown($comments);
    }

    /** 更新履歴の 1 ページ */
    private function revisions(int $id, int $page, AgentToolSubject $subject): mixed
    {
        $revisions = $this->activity->revisions(
            $subject->requester(),
            $id,
            $page,
            self::REVISIONS_PER_PAGE,
            $subject->date(),
            $subject->localeCode(),
        );

        return RecordToolValues::shown([...$revisions, 'rows' => self::revisionRows($revisions['rows'])]);
    }

    /**
     * 添付の名前・形式・大きさ(上限まで)と残りの有無。
     *
     * @return array{items: list<array{name: mixed, mime: mixed, size: mixed}>, hasMore: bool}
     */
    private function attachmentItems(int $id): array
    {
        $attachments = $this->attachments->forRecord($id, self::ATTACHMENT_LIMIT);

        return [
            'items' => array_map(fn (array $item): array => [
                'name' => $item['name'],
                'mime' => $item['mime'],
                'size' => $item['size'],
            ], $attachments['items']),
            'hasMore' => $attachments['hasMore'],
        ];
    }

    /**
     * 更新履歴の行の差分の添付の変化を、添付の一覧と同じく名前と形式だけにする(付けたか外したかは残す。大きさは差分に無い。
     * 実体の id・開けるか・消す日はモデルが使える道具の無い内部の値)。
     *
     * @param  list<array<string, mixed>>  $rows  口の更新履歴の行
     * @return list<array<string, mixed>>
     */
    private static function revisionRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $changes = $row['changes'] ?? null;

            if (! is_array($changes) || ! is_array($changes['attachments'] ?? null)) {
                return $row;
            }

            $changes['attachments'] = array_map(fn (mixed $change): mixed => is_array($change) ? [
                'change' => $change['change'] ?? null,
                'name' => $change['name'] ?? null,
                'mime' => $change['mime'] ?? null,
            ] : $change, $changes['attachments']);

            return [...$row, 'changes' => $changes];
        }, $rows);
    }

    /**
     * 項目の値を、定義の並びで、コード・名前・値にする(明細の項目と値の無い項目は入れない)。
     *
     * @param  list<array<array-key, mixed>>  $fields  定義の項目
     * @param  array<string, mixed>  $values  項目のコード → 値
     * @return list<array{code: string, name: mixed, value: mixed}>
     */
    public static function named(array $fields, array $values): array
    {
        $named = [];

        foreach ($fields as $field) {
            $code = $field['code'] ?? null;

            if (is_string($code) && array_key_exists($code, $values) && ($field['type'] ?? null) !== 'details') {
                $named[] = ['code' => $code, 'name' => $field['name'] ?? null, 'value' => $values[$code]];
            }
        }

        return $named;
    }
}
