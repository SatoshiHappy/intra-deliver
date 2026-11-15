<?php

namespace App\Queries\Notice;

use App\Queries\Notice\Api\NoticeTypeEntry;
use App\Queries\Notice\Api\NoticeTypes;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * 通知の種類の一覧の全体 = タグ {@see NoticeTypes::TAG} で束ねた実装の答えを登録の順に並べる 1 か所(内部。docs の
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節、ADR 0045 の決定 2)。
 * 送る口と 2 つの画面・2 つの書き込みが同じ一覧を使い、束ねるのは要求(キューのジョブ)ごとに 1 回(#[Scoped])。
 * 答えが同じ節の束ねる時の規則に外れていれば、束ねる時に例外にする(実装し忘れ・書き誤りを最初の要求で見つけるため)。
 */
#[Scoped]
class NoticeTypeCatalog
{
    /** 種類「モジュール.種類」の書式(表 notices と 2 つの設定の表の検査制約と同じ) */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D';

    /** 種類の長さの上限(列 varchar(100)) */
    public const MAX_TYPE_LENGTH = 100;

    /** @var array<string, NoticeTypeEntry>|null 束ねた一覧(種類 → 値。登録の順・答えの順) */
    private ?array $entries = null;

    /**
     * @param  iterable<NoticeTypes>  $noticeTypes
     */
    public function __construct(#[Tag(NoticeTypes::TAG)] private iterable $noticeTypes) {}

    /**
     * 種類が書式に合うか(長さの上限を含む)。
     */
    public static function isWellFormed(string $type): bool
    {
        return strlen($type) <= self::MAX_TYPE_LENGTH && preg_match(self::TYPE_PATTERN, $type) === 1;
    }

    /**
     * 束ねた一覧の全部(切り替えられない種類を含む。登録の順・答えの順)。
     *
     * @return list<NoticeTypeEntry>
     *
     * @throws LogicException 実装の答えが規則に外れるとき
     */
    public function all(): array
    {
        return array_values($this->entries());
    }

    /**
     * 一覧の種類の値(一覧に無ければ null)。
     *
     * @throws LogicException 実装の答えが規則に外れるとき
     */
    public function find(string $type): ?NoticeTypeEntry
    {
        return $this->entries()[$type] ?? null;
    }

    /**
     * 一覧にあり、利用者が切り替えられる種類か(2 つの書き込みが受ける種類)。
     *
     * @throws LogicException 実装の答えが規則に外れるとき
     */
    public function isSwitchable(string $type): bool
    {
        return $this->find($type)->switchable ?? false;
    }

    /**
     * @return array<string, NoticeTypeEntry>
     *
     * @throws LogicException
     */
    private function entries(): array
    {
        return $this->entries ??= $this->build();
    }

    /**
     * @return array<string, NoticeTypeEntry>
     *
     * @throws LogicException
     */
    private function build(): array
    {
        $entries = [];
        $prefixes = [];

        foreach ($this->noticeTypes as $implementation) {
            $answer = $implementation->types();
            $prefix = self::prefixOf($implementation, $answer, $entries);

            if ($prefix !== null && array_key_exists($prefix, $prefixes)) {
                throw new LogicException("通知の種類の接頭辞 {$prefix} を 2 つの実装が答えている({$prefixes[$prefix]}・"
                    .$implementation::class.')');
            }

            if ($prefix !== null) {
                $prefixes[$prefix] = $implementation::class;
            }

            foreach ($answer as $entry) {
                $entries[$entry->type] = $entry;
            }
        }

        return $entries;
    }

    /**
     * 1 つの答えを確かめ、その接頭辞を返す(答えが空なら null)。
     *
     * @param  list<NoticeTypeEntry>  $answer
     * @param  array<string, NoticeTypeEntry>  $known  前の実装までの一覧
     *
     * @throws LogicException
     */
    private static function prefixOf(NoticeTypes $implementation, array $answer, array $known): ?string
    {
        $prefixes = [];

        foreach ($answer as $entry) {
            if (! self::isWellFormed($entry->type)) {
                throw new LogicException("通知の種類「{$entry->type}」が「モジュール.種類」の書式でない(".$implementation::class.')');
            }

            if (array_key_exists($entry->type, $known)) {
                throw new LogicException("通知の種類 {$entry->type} が一覧に 2 度ある(".$implementation::class.')');
            }

            $known[$entry->type] = $entry;
            $prefixes[explode('.', $entry->type, 2)[0]] = true;
        }

        if (count($prefixes) > 1) {
            throw new LogicException('1 つの実装が通知の種類の接頭辞を 2 つ以上答えている('.$implementation::class.')');
        }

        return array_key_first($prefixes);
    }
}
