<?php

namespace App\Queries\Workflow;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;

/**
 * つながる申請の群(見出しと、当てる申請書のレコードの id)を並びの順に作る(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md
 * 6 節の出すかの表・群・群の見出し)。表で出さない・起きない組み合わせの群は作らない。案件の閲覧の判定と、同じ案件を先の群だけに出すのは
 * 呼び手({@see RelatedCases})。
 *
 * @phpstan-import-type RelatedOrigin from RelatedCaseOrigins
 * @phpstan-import-type RelatedLink from RelatedCaseOrigins
 *
 * @phpstan-type RelatedGroupSource array{heading: string, recordIds: list<int>}
 */
class RelatedCaseGroups
{
    /** たどり方(参照先・参照元)。並びは群の並び */
    private const WAYS = ['referenced', 'referencing'];

    public function __construct(
        private RecordViewAccess $viewAccess,
        private ApplicationTargetCases $applicationTargetCases,
    ) {}

    /**
     * 群の並び(写しとつながる申請.md の 6 節)。当てる申請書のレコードの無い群は返さない。
     *
     * @param  RelatedOrigin|null  $applicationTargetOrigin  申請対象の起点(無い・閲覧できなければ null)
     * @param  RelatedOrigin|null  $application  申請書のレコードの起点(recordAccess が偽なら null)
     * @return list<RelatedGroupSource>
     */
    public function of(
        Requester $requester,
        DateTimeInterface $date,
        ?array $applicationTargetOrigin,
        ?array $application,
    ): array {
        $fieldIds = $this->applicationTargetCases->fieldIdsByModule();
        $groups = [];

        if ($applicationTargetOrigin !== null) {
            $groups[] = [
                'heading' => "同じ{$applicationTargetOrigin['moduleName']}を申請対象にする申請",
                'recordIds' => $this->applicationTargetCases->applicationRecordIdsOf(
                    $applicationTargetOrigin['recordId'], $fieldIds[$applicationTargetOrigin['moduleCode']] ?? [],
                ),
            ];
        }

        foreach (self::WAYS as $way) {
            foreach (array_filter([$applicationTargetOrigin, $application]) as $origin) {
                $groups = [...$groups, ...$this->linkGroups($requester, $date, $way, $origin, $fieldIds)];
            }
        }

        return array_values(array_filter($groups, fn (array $group) => $group['recordIds'] !== []));
    }

    /**
     * 起点 1 つ・たどり方 1 つの群(並びは 6 節の群の並び)。
     *
     * @param  'referenced'|'referencing'  $way
     * @param  RelatedOrigin  $origin
     * @param  array<string, list<int>>  $fieldIds  業務モジュールのコード → 申請対象の項目の id
     * @return list<RelatedGroupSource>
     */
    private function linkGroups(
        Requester $requester,
        DateTimeInterface $date,
        string $way,
        array $origin,
        array $fieldIds,
    ): array {
        $applicationTargetGroups = [];
        $applicationRecordGroups = [];

        foreach ($origin[$way] as $link) {
            $applicationTargetGroups[] = [
                'heading' => self::heading($way, true, $origin['moduleName'], $link),
                'recordIds' => $this->applicationRecordIdsWithApplicationTarget(
                    $requester, $date, $link, $fieldIds[$link['moduleCode']] ?? [],
                ),
            ];
            $applicationRecordGroups[] = [
                'heading' => self::heading($way, false, $origin['moduleName'], $link),
                'recordIds' => $link['recordIds'],
            ];
        }

        return [...$applicationTargetGroups, ...$applicationRecordGroups];
    }

    /**
     * たどった先のレコードのうち見る人が閲覧できるものを申請対象にする、申請書のレコードの id(申請対象の参照の値を、閲覧できない人に見せない)。
     * 申請対象の項目の無い業務モジュールでは閲覧の判定もしない。
     *
     * @param  RelatedLink  $link
     * @param  list<int>  $fieldIds  たどった先の業務モジュールを申請対象の参照先にする申請対象の項目の id
     * @return list<int>
     */
    private function applicationRecordIdsWithApplicationTarget(
        Requester $requester,
        DateTimeInterface $date,
        array $link,
        array $fieldIds,
    ): array {
        if ($fieldIds === []) {
            return [];
        }

        $ids = [];

        foreach ($link['recordIds'] as $recordId) {
            if ($this->viewAccess->canView($requester, $link['moduleCode'], $recordId, $date)) {
                $ids = [...$ids, ...$this->applicationTargetCases->applicationRecordIdsOf($recordId, $fieldIds)];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 群の見出し(業務モジュールと項目の名前で書き、レコードの値は出さない)。
     *
     * @param  'referenced'|'referencing'  $way
     * @param  RelatedLink  $link
     */
    private static function heading(string $way, bool $asApplicationTarget, string $originName, array $link): string
    {
        $field = $link['field'];

        return match ([$way, $asApplicationTarget]) {
            ['referenced', true] => "{$originName}の「{$field}」を申請対象にする申請",
            ['referenced', false] => "{$originName}の「{$field}」が指す申請",
            ['referencing', true] => "「{$field}」で{$originName}を指す{$link['moduleName']}を申請対象にする申請",
            default => "「{$field}」で{$originName}を指す{$link['moduleName']}",
        };
    }
}
