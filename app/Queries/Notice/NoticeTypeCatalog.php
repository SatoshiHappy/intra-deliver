<?php

namespace App\Queries\Notice;

use App\Queries\Notice\Api\NoticeTypes;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * 通知の種類の一覧の全体 = タグ {@see NoticeTypes::TAG} で束ねた実装の答えを登録の順に並べる 1 か所(内部。docs の
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節、ADR 0050 の決定 2)。
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

    /** @var array<string, true>|null 束ねた一覧(種類 → true。登録の順・答えの順) */
    private ?array $types = null;

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
     * 束ねた一覧の全部(登録の順・答えの順)。
     *
     * @return list<string>
     *
     * @throws LogicException 実装の答えが規則に外れるとき
     */
    public function all(): array
    {
        return array_keys($this->types());
    }

    /**
     * 種類が一覧にあるか(送る口と 2 つの書き込みが受ける種類)。
     *
     * @throws LogicException 実装の答えが規則に外れるとき
     */
    public function has(string $type): bool
    {
        return isset($this->types()[$type]);
    }

    /**
     * @return array<string, true>
     *
     * @throws LogicException
     */
    private function types(): array
    {
        return $this->types ??= $this->build();
    }

    /**
     * @return array<string, true>
     *
     * @throws LogicException
     */
    private function build(): array
    {
        $types = [];
        $prefixes = [];

        foreach ($this->noticeTypes as $implementation) {
            $answer = $implementation->types();
            $prefix = self::prefixOf($implementation, $answer, $types);

            if ($prefix !== null && array_key_exists($prefix, $prefixes)) {
                throw new LogicException("通知の種類の接頭辞 {$prefix} を 2 つの実装が答えている({$prefixes[$prefix]}・"
                    .$implementation::class.')');
            }

            if ($prefix !== null) {
                $prefixes[$prefix] = $implementation::class;
            }

            foreach ($answer as $type) {
                $types[$type] = true;
            }
        }

        return $types;
    }

    /**
     * 1 つの答えを確かめ、その接頭辞を返す(答えが空なら null)。
     *
     * @param  list<string>  $answer
     * @param  array<string, true>  $known  前の実装までの一覧
     *
     * @throws LogicException
     */
    private static function prefixOf(NoticeTypes $implementation, array $answer, array $known): ?string
    {
        $prefixes = [];

        foreach ($answer as $type) {
            if (! self::isWellFormed($type)) {
                throw new LogicException("通知の種類「{$type}」が「モジュール.種類」の書式でない(".$implementation::class.')');
            }

            if (array_key_exists($type, $known)) {
                throw new LogicException("通知の種類 {$type} が一覧に 2 度ある(".$implementation::class.')');
            }

            $known[$type] = true;
            $prefixes[explode('.', $type, 2)[0]] = true;
        }

        if (count($prefixes) > 1) {
            throw new LogicException('1 つの実装が通知の種類の接頭辞を 2 つ以上答えている('.$implementation::class.')');
        }

        return array_key_first($prefixes);
    }
}
