<?php

namespace App\Actions\Meeting;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Enums\Meeting\MeetingNoticeReason;
use App\Enums\Meeting\MeetingNoticeType;
use App\Queries\Records\Api\RecordAudience;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 会議の完了・失敗の通知を送る 1 か所(docs の 設計/業務ツール/会議/通知と削除予定日.md の 6 節)。宛先 = 送る時点の関係者のうち会議を閲覧できる人
 * (入れ物の口で引き、定義を写さない)。件名は送る時の写し。呼び手は会議があることを確かめてから呼ぶ。
 */
class MeetingNotices
{
    /** 通知の対象の種類(レコードの詳細へ移る = 入れ物が移り先を答える。設計/業務データの入れ物/レコードの共通機能/コメント.md の 3 節) */
    public const TARGET_TYPE = 'record';

    public function __construct(
        private SendNotices $sendNotices,
        private RecordAudience $audience,
        private RecordDisplayQuery $records,
    ) {}

    /**
     * @param  int  $actorUserId  起こした人(実行の依頼者)
     * @param  MeetingNoticeReason|null  $reason  失敗の理由(完了は null)
     */
    public function send(
        MeetingNoticeType $type,
        int $recordId,
        int $actorUserId,
        ?MeetingNoticeReason $reason = null,
    ): void {
        $title = $this->records->titleOf($recordId);
        $params = ['title' => $title === null || $title === '' ? "#{$recordId}" : $title];

        if ($reason !== null) {
            $params['reason'] = $reason->value;
        }

        $recipients = $this->audience->relatedViewers($recordId, CarbonImmutable::today(AccessContext::TIMEZONE));
        $message = new NoticeMessage($type->value, self::TARGET_TYPE, $recordId, $actorUserId, $params);
        $this->sendNotices->execute($message, $recipients);
    }
}
