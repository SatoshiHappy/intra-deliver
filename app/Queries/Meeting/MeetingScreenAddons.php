<?php

namespace App\Queries\Meeting;

use App\Enums\Builtin\Meeting;
use App\Enums\Meeting\MeetingProcessingState;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Queries\Records\Api\RecordScreenAddons;
use App\Queries\Upload\Api\UploadLimits;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

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

    public function __construct(
        private MeetingPermissions $permissions,
        private MeetingProcessingQuery $states,
        private MeetingAudioBudget $budget,
        private MeetingRegenerableQuery $regenerables,
        private UploadLimits $limits,
    ) {}

    public function moduleCode(): string
    {
        return Meeting::CODE;
    }

    /**
     * 「音声から作成」は作成の入口と同じ判定を通る見る人にだけ出す。部品には音声 1 本の上限(添付の土台の値)、
     * 音声の取り込みの先の止め方の文(受けられれば null = 公開デモと画面.md の 9.2)を渡す。
     *
     * @return RecordIndexAddons
     */
    public function index(AccessContext $context): array
    {
        $blocked = $this->budget->blockedMessage(MeetingRunOrigin::Create, CarbonImmutable::now());

        return ['actions' => $this->permissions->canCreate($context) ? [[
            'code' => 'create',
            'label' => '音声から作成',
            'dialog' => 'meeting.create',
            'props' => [
                'audioMaxBytes' => $this->limits->maxFileBytes(),
                'audioBlockedMessage' => $blocked,
            ],
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
     * 再作成と注は、押せる会議({@see MeetingRegenerableQuery})にだけ出す(手で作った会議には出さない)。注は見る人の権限によらない。
     *
     * @return RecordShowAddons
     */
    public function show(AccessContext $context, int $recordId): array
    {
        $found = $this->regenerables->of($recordId);
        $regenerable = $found['regenerable'];
        $state = $this->states->statesOf([$recordId])[$recordId] ?? null;
        $canRegenerate = $regenerable && $this->permissions->canRegenerate($context);

        return [
            'actions' => $canRegenerate ? [$this->regenerateAction($recordId, $found['transcriptId'], $state)] : [],
            'badges' => $state === null ? [] : [self::badgeOf($state)],
            'fieldNotes' => $regenerable ? [Meeting::Summary->value => self::MINUTES_NOTE] : [],
        ];
    }

    /**
     * 部品には書き起こしの有無(送り先を分ける = 処理.md の 3.7)と、追加の指示の上限と、音声から作り直すときの先の止め方の文
     * (書き起こしが無く受ける音声があるときだけ。ほかは null = 公開デモと画面.md の 9.3)を渡す。作り直す材料は
     * {@see MeetingRegenerableQuery::materialOf()} で引く(入口と同じ定義。添付を読むので、再作成を出す見る人にだけ)。
     *
     * @return RecordAddonAction
     */
    private function regenerateAction(int $recordId, ?int $transcriptId, ?MeetingProcessingState $state): array
    {
        $material = $this->regenerables->materialOf($recordId, $transcriptId);
        $hasTranscript = $material['transcriptId'] !== null;
        $fromAudio = $material['audioUploadId'] !== null;
        $blocked = $fromAudio
            ? $this->budget->blockedMessage(MeetingRunOrigin::Regenerate, CarbonImmutable::now())
            : null;

        return [
            'code' => 'regenerate',
            'label' => '再作成',
            'dialog' => 'meeting.regenerate',
            'props' => [
                'hasTranscript' => $hasTranscript,
                'instructionsMaxLength' => MeetingLimits::INSTRUCTIONS_MAX_LENGTH,
                'audioBlockedMessage' => $blocked,
            ],
            'disabledReason' => $this->disabledReason($state, $hasTranscript || $fromAudio),
            'changesValues' => true,
        ];
    }

    /**
     * 再作成の押せない理由。処理中は材料の有無によらず押せないので先に返す(公開デモと画面.md の 9.3、処理.md の 3.7)。
     */
    private function disabledReason(?MeetingProcessingState $state, bool $hasMaterial): ?string
    {
        if ($state === MeetingProcessingState::Processing) {
            return self::PROCESSING_REASON;
        }

        return $hasMaterial ? null : MeetingRegenerableQuery::NO_MATERIAL;
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
