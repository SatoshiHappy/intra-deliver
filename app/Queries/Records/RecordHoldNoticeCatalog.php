<?php

namespace App\Queries\Records;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordHoldLink;
use App\Queries\Records\Api\RecordHoldNotices;
use App\Queries\Records\Api\RecordHoldState;
use Closure;
use DateTimeInterface;
use Illuminate\Container\Attributes\Tag;
use LogicException;
use Throwable;

/**
 * 持ち主の案内の実装を束ねる 1 か所(内部。束ね方と失敗の扱い = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3)。案内は表示だけなので、実装の失敗で画面を落とさない。
 *
 * @phpstan-type HoldLinkProps array{label: string, url: string}
 */
class RecordHoldNoticeCatalog
{
    /**
     * @param  iterable<RecordHoldNotices>  $notices
     */
    public function __construct(#[Tag(RecordHoldNotices::TAG)] private iterable $notices) {}

    /**
     * 呼び手はレコードの閲覧か編集の画面の判定を済ませてから呼ぶ。
     */
    public function stateOf(Requester $requester, string $holder, DateTimeInterface $date): ?RecordHoldState
    {
        foreach ($this->notices as $notice) {
            $state = rescue(
                fn () => $notice->stateOf($requester, $holder, $date),
                $this->closedBy($notice, 'stateOf', null),
                report: false,
            );

            if ($state !== null && $this->isValidState($notice, $state)) {
                return $state;
            }
        }

        return null;
    }

    /**
     * 渡した版の id → リンク。
     *
     * @param  list<int>  $revisionIds
     * @return array<int, RecordHoldLink>
     */
    public function revisionLinks(int $recordId, array $revisionIds): array
    {
        if ($revisionIds === []) {
            return [];
        }

        $links = [];

        foreach ($this->notices as $notice) {
            $found = rescue(
                fn () => $notice->revisionLinks($recordId, $revisionIds),
                $this->closedBy($notice, 'revisionLinks', []),
                report: false,
            );

            foreach ($this->validLinks($notice, $found, $revisionIds) as $revisionId => $link) {
                $links[$revisionId] ??= $link;
            }
        }

        return $links;
    }

    /**
     * 業務モジュールの添付の許可を外す前の確かめの文。
     *
     * @return list<string>
     */
    public function attachmentDisableNotices(int $recordModuleId): array
    {
        $texts = [];

        foreach ($this->notices as $notice) {
            $text = rescue(
                fn () => $notice->attachmentDisableNotice($recordModuleId),
                $this->closedBy($notice, 'attachmentDisableNotice', null),
                report: false,
            );

            if ($text !== null) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * 画面の札の色の段階に無い色を出さない。
     */
    private function isValidState(RecordHoldNotices $notice, RecordHoldState $state): bool
    {
        if (in_array($state->tone, RecordHoldState::TONES, true)) {
            return true;
        }

        report(new LogicException($notice::class."::stateOf() の札の色 {$state->tone} が決まった値の外"));

        return false;
    }

    /**
     * ほかのレコードの版のリンクを出さない。
     *
     * @param  array<int, RecordHoldLink>  $found
     * @param  list<int>  $revisionIds
     * @return array<int, RecordHoldLink>
     */
    private function validLinks(RecordHoldNotices $notice, array $found, array $revisionIds): array
    {
        $valid = [];

        foreach ($found as $revisionId => $link) {
            if (in_array($revisionId, $revisionIds, true)) {
                $valid[$revisionId] = $link;
            } else {
                report(new LogicException($notice::class."::revisionLinks() が渡していない版 {$revisionId} を返した"));
            }
        }

        return $valid;
    }

    /**
     * rescue() の代わりの値の関数。元の例外を包んで自分で報告する(Handler が記録しない型の例外も残すため、rescue() の報告は止める)。
     *
     * @template T
     *
     * @param  T  $fallback
     * @return Closure(Throwable): T
     */
    private function closedBy(RecordHoldNotices $notice, string $method, mixed $fallback): Closure
    {
        return function (Throwable $e) use ($notice, $method, $fallback): mixed {
            report(new LogicException($notice::class."::{$method}() が例外を投げた", 0, $e));

            return $fallback;
        };
    }

    /**
     * 持ち主の画面へのリンクの props(持ち主の 1 行と更新履歴の欄で同じ形にするため、ここ 1 か所で作る)。
     *
     * @return HoldLinkProps
     */
    public static function linkProps(RecordHoldLink $link): array
    {
        return ['label' => $link->label, 'url' => $link->url];
    }
}
