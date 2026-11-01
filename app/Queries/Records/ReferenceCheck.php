<?php

namespace App\Queries\Records;

use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use LogicException;

/**
 * 1 回の保存(または写しの初期値・参照者の確かめの 1 回の読み取り)の中で、参照の相手を確かめた結果。確かめた相手と受ける相手を分けて持ち、
 * 確かめていない相手を引くと例外にする(確かめの漏れ・ずれを、黙った 422 や 1 値ずつの問い合わせに戻さず表に出す)。
 * {@see ReferenceTargets::prepare()} が相手の種類(ユーザー・組織・参照先の業務モジュール)ごとに 1 本の問い合わせで確かめて入れ、各行の読み取りが引く。
 * 確かめごとに作って捨てる(サービスに状態を持たせない)。参照先の行に鍵を取るのは、業務処理がトランザクションの中で鍵の印を付けて作ったときだけ
 * (docs の 設計/業務データの入れ物/データモデル/値の形と直列化.md 4 節。開く時の確かめは鍵を取らない)。
 */
final class ReferenceCheck
{
    /** @var array<string, array<int, true>> 相手の種類 → 確かめた相手の id */
    private array $asked = [];

    /** @var array<string, array<int, true>> 相手の種類 → 受ける相手の id */
    private array $accepted = [];

    /**
     * @param  Requester|null  $requester  判定を求める者(null = 判定しない更新。判定の要る確かめに届けば作りの誤り)
     * @param  bool  $locksTargets  参照先の行に FOR KEY SHARE を取るか(保存の業務処理がトランザクションの中で作るときだけ真。付け忘れに気づけるよう既定を置かない)
     */
    public function __construct(
        private readonly ?Requester $requester,
        public readonly DateTimeInterface $date,
        public readonly bool $locksTargets,
    ) {}

    /**
     * 参照先の判定に使う、判定を求める者。
     *
     * @throws LogicException 判定しない更新の保存で、判定の要る確かめ(他モジュール参照の相手)に届いたとき
     */
    public function requester(): Requester
    {
        return $this->requester ?? throw new LogicException('判定しない更新の保存で、参照先の判定に届いた');
    }

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
     * @throws LogicException 確かめていない相手のとき(使う前に ReferenceTargets::prepare で全部を確かめる不変条件が崩れた)
     */
    public function accepts(string $group, int $id): bool
    {
        if (! isset($this->asked[$group][$id])) {
            throw new LogicException("参照の相手 {$group} {$id} を、保存の前に確かめていない");
        }

        return isset($this->accepted[$group][$id]);
    }
}
