<?php

namespace App\Actions\Records;

use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use LogicException;

/**
 * 1 回の保存の中で、参照の項目が新しく指す相手を確かめた結果(修正方針 A6・D5)。確かめた相手と受ける相手を分けて持ち、確かめていない相手を
 * 引くと例外にする(確かめの漏れ・ずれを、黙った 422 や 1 値ずつの問い合わせに戻さず表に出す)。{@see ReferenceTargets::prepare()} が、親と明細の全行の変わった
 * 参照の値を相手の種類(ユーザー・組織・参照先の業務モジュール)ごとに 1 本の問い合わせで確かめて入れ、各行の読み取りが引く。
 * 保存ごとに作って捨てる(業務処理のサービスに状態を持たせない)。
 */
final class ReferenceCheck
{
    /** @var array<string, array<int, true>> 相手の種類 → 確かめた相手の id */
    private array $asked = [];

    /** @var array<string, array<int, true>> 相手の種類 → 受ける相手の id */
    private array $accepted = [];

    public function __construct(
        public readonly Requester $requester,
        public readonly DateTimeInterface $date,
    ) {}

    /**
     * 確かめた結果を入れる(前の結果に足す)。
     *
     * @param  list<int>  $askedIds  確かめた相手の id
     * @param  list<int>  $acceptedIds  そのうち受ける相手の id
     */
    public function remember(string $group, array $askedIds, array $acceptedIds): void
    {
        $this->asked[$group] = ($this->asked[$group] ?? []) + array_fill_keys($askedIds, true);
        $this->accepted[$group] = ($this->accepted[$group] ?? []) + array_fill_keys($acceptedIds, true);
    }

    /**
     * 相手を受けるか。
     *
     * @throws LogicException 確かめていない相手のとき(保存の前に ReferenceTargets::prepare で全部を確かめる不変条件が崩れた = 修正方針 D5)
     */
    public function accepts(string $group, int $id): bool
    {
        if (! isset($this->asked[$group][$id])) {
            throw new LogicException("参照の相手 {$group} {$id} を、保存の前に確かめていない");
        }

        return isset($this->accepted[$group][$id]);
    }
}
