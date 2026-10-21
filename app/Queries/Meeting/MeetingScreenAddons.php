<?php

namespace App\Queries\Meeting;

use App\Enums\Builtin\Meeting;
use App\Enums\Meeting\MeetingProcessingState;
use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use App\Queries\Records\Api\RecordScreenAddons;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;

/**
 * 会議の一覧と詳細に足す操作・札・注(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.1・9.3。押せる人・押せる会議・押せる時 =
 * 処理.md の 3.2・3.7、札 = 3.8)。操作はダイアログを開くだけで、送った先の入口がもう一度判定する。
 *
 * @phpstan-import-type RecordAddonAction from RecordScreenAddons
 * @phpstan-import-type RecordAddonBadge from RecordScreenAddons
 * @phpstan-import-type RecordIndexAddons from RecordScreenAddons
 * @phpstan-import-type RecordShowAddons from RecordScreenAddons
 */
class MeetingScreenAddons implements RecordScreenAddons
{
    /** 再作成の押せない理由(処理中) */
    public const PROCESSING_REASON = '処理中です';

    /** 要約の欄の上の注(再作成のできる会議だけ) */
    public const MINUTES_NOTE = '議事録の 5 欄は、再作成で上書きされます。前の値は更新履歴で確認できます。';

    public function __construct(private MeetingPermissions $permissions, private MeetingProcessingQuery $states) {}

    public function moduleCode(): string
    {
        return Meeting::CODE;
    }

    /**
     * 「音声から作成」は作成の入口と同じ判定を通る見る人にだけ出す。部品には取り込むテキストの上限を渡す。
     *
     * @return RecordIndexAddons
     */
    public function index(AccessContext $context): array
    {
        return ['actions' => $this->permissions->canCreate($context) ? [[
            'code' => 'create',
            'label' => '音声から作成',
            'dialog' => 'meeting.create',
            'props' => ['textMaxBytes' => MeetingLimits::TEXT_MAX_BYTES],
            'disabledReason' => null,
            'changesValues' => false,
        ]] : []];
    }

    public function badges(AccessContext $context, array $recordIds): array
    {
        return array_map(
            fn (MeetingProcessingState $state) => [self::badgeOf($state)],
            $this->states->statesOf($recordIds),
        );
    }

    /**
     * 再作成と注は、書き起こしか会議と実行の対応のある会議にだけ出す(手で作った会議には出さない)。注は見る人の権限によらない。
     *
     * @return RecordShowAddons
     */
    public function show(AccessContext $context, int $recordId): array
    {
        $found = DB::query()
            ->selectSub(Transcript::query()->select('id')->where('record_id', $recordId)->limit(1), 'transcript_id')
            ->selectSub(MeetingRun::query()->select('id')->where('record_id', $recordId)->limit(1), 'run_id')
            ->first();
        $hasTranscript = $found?->transcript_id !== null;
        $regenerable = $hasTranscript || $found?->run_id !== null;
        $state = $this->states->statesOf([$recordId])[$recordId] ?? null;
        $canRegenerate = $regenerable && $this->permissions->canRegenerate($context);

        return [
            'actions' => $canRegenerate ? [self::regenerateAction($hasTranscript, $state)] : [],
            'badges' => $state === null ? [] : [self::badgeOf($state)],
            'fieldNotes' => $regenerable ? [Meeting::Summary->value => self::MINUTES_NOTE] : [],
        ];
    }

    /**
     * 部品には書き起こしの有無(送り先を分ける = 処理.md の 3.7)と、追加の指示の上限を渡す。
     *
     * @return RecordAddonAction
     */
    private static function regenerateAction(bool $hasTranscript, ?MeetingProcessingState $state): array
    {
        return [
            'code' => 'regenerate',
            'label' => '再作成',
            'dialog' => 'meeting.regenerate',
            'props' => [
                'hasTranscript' => $hasTranscript,
                'instructionsMaxLength' => MeetingLimits::INSTRUCTIONS_MAX_LENGTH,
            ],
            'disabledReason' => $state === MeetingProcessingState::Processing ? self::PROCESSING_REASON : null,
            'changesValues' => true,
        ];
    }

    /**
     * @return RecordAddonBadge
     */
    private static function badgeOf(MeetingProcessingState $state): array
    {
        return match ($state) {
            MeetingProcessingState::Processing => ['label' => '処理中', 'tone' => 'pending'],
            MeetingProcessingState::Failed => ['label' => '失敗', 'tone' => 'rejected'],
        };
    }
}
