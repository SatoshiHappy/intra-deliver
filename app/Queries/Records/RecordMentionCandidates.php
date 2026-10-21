<?php

namespace App\Queries\Records;

use App\Queries\Master\Api\MasterCandidates;
use DateTimeInterface;

/**
 * コメントの宛先の候補(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 2 節): 語に当たる今日に有効なユーザーを(共通マスタの
 * 候補と同じ規則で)引き、そのレコードを閲覧できる人だけに絞り、共通マスタの候補と同じ並び(sort_key・code)のまま 20 件まで返す。絞ってから 20 件に
 * 切るので、閲覧できない人がその並びで先に並んでも、閲覧できる人が候補に出る。呼び手が閲覧の判定を済ませてから呼ぶ。語が空なら空(共通マスタの候補と同じ)。
 */
class RecordMentionCandidates
{
    /** 返す件数の上限 */
    public const LIMIT = 20;

    /** 語に当たるユーザーを引く件数の上限(規模の前提 = ユーザーは数十。無制限に引かない安全弁) */
    private const MATCH_LIMIT = 500;

    public function __construct(
        private MasterCandidates $users,
        private RecordMentionViewers $viewers,
    ) {}

    /**
     * @return list<array{id: int, name: string, department: string|null}> name = 名前(無ければコード)、department = 主所属の組織の名前
     */
    public function candidates(
        string $moduleCode,
        int $recordId,
        string $keyword,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $matched = $this->users->usersOn($keyword, $date, $localeCode, self::MATCH_LIMIT);
        $viewable = array_flip($this->viewers->among($moduleCode, $recordId, array_column($matched, 'id'), $date));
        $rows = [];

        foreach ($matched as $user) {
            if (isset($viewable[$user['id']])) {
                $rows[] = [
                    'id' => $user['id'],
                    'name' => $user['name'] ?? $user['code'],
                    'department' => $user['mainDepartmentName'],
                ];
            }
        }

        return array_slice($rows, 0, self::LIMIT);
    }
}
