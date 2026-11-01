<?php

namespace App\Queries\Workflow;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use JsonException;

/**
 * 案件からの複製で引き継ぐ参照制限(元の印と参照者)の読み書きの 1 か所(docs の
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/写す値.md の 13.2 節)。
 * 開いた時の値は暗号化した値で画面に渡し、一時保存では draft_input のサーバーだけが書くキーに持つ。解けない値・形の合わない値は
 * 止めずに強める向き(付き・参照者 = 空)にだけ倒す。中身は JSON の文字列で暗号化し、解くときに PHP の unserialize を通さない。
 *
 * @phpstan-type CopiedParty array{type: 'user'|'department', id: int}
 * @phpstan-type CopiedRestriction array{restricted: bool, viewers: list<CopiedParty>}
 */
class CopiedRowAccess
{
    /** 一時保存の入力の最上位の、サーバーだけが書くキー */
    public const DRAFT_KEY = 'copied_row_access';

    /** 暗号化した値の用途の印(ほかの用途で作った暗号化した値を受けないため) */
    private const PURPOSE = 'workflow.case-copy.row-access';

    /** 解けない・合わない値の読み替え(付き・参照者 = 空) */
    private const RESTRICTED_WITHOUT_VIEWERS = ['restricted' => true, 'viewers' => []];

    public function __construct(private StringEncrypter $encrypter) {}

    /**
     * 開いた時の元の印と参照者を、写す人とフローに結んだ暗号化した値にする。
     *
     * @param  CopiedRestriction  $restriction
     */
    public function seal(array $restriction, ?int $copierUserId, int $flowId): string
    {
        $payload = [
            'purpose' => self::PURPOSE,
            'restricted' => $restriction['restricted'],
            'viewers' => $restriction['viewers'],
            'copier' => $copierUserId,
            'flow' => $flowId,
        ];

        return $this->encrypter->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * 新規の申請・一時保存の新規の POST が送り返した暗号化した値の元の印と参照者(読み方の表 = 13.2 節の POST での読み方)。
     *
     * @param  mixed  $sealed  入口が受けた値のまま
     * @param  int|null  $userId  今の利用者(写す人と比べる。ゲストは null で、どの値とも合わない)
     * @return CopiedRestriction|null null = 案件からの複製でない
     */
    public function open(mixed $sealed, ?int $userId, int $flowId): ?array
    {
        if ($sealed === null || $sealed === '') {
            return null;
        }

        $payload = is_string($sealed) ? $this->decrypted($sealed) : null;
        $restriction = self::restrictionOf($payload);

        return $restriction !== null && self::isBound($payload, $userId, $flowId)
            ? $restriction
            : self::RESTRICTED_WITHOUT_VIEWERS;
    }

    /**
     * 一時保存の入力が持つ元の印と参照者(形の合わない値は付き・参照者 = 空)。
     *
     * @return CopiedRestriction|null null = キーが無い(案件からの複製でない・機能の前の一時保存)
     */
    public function ofDraft(mixed $draftInput): ?array
    {
        if (! is_array($draftInput) || ! array_key_exists(self::DRAFT_KEY, $draftInput)) {
            return null;
        }

        $value = $draftInput[self::DRAFT_KEY];
        $restriction = is_array($value) && count($value) === 2 ? self::restrictionOf($value) : null;

        return $restriction ?? self::RESTRICTED_WITHOUT_VIEWERS;
    }

    /**
     * 一時保存の上書きが保つ、サーバーだけが書くキー(持った値を変えない。キーが無ければ空)。
     *
     * @return array<string, mixed>
     */
    public function keptInDraft(mixed $draftInput): array
    {
        return is_array($draftInput) && array_key_exists(self::DRAFT_KEY, $draftInput)
            ? [self::DRAFT_KEY => $draftInput[self::DRAFT_KEY]]
            : [];
    }

    /**
     * 解いた値が用途の印と 5 つのキーの形を持ち、写す人が今の利用者で、フローが URL のフローか(別の人・ほかのフローに使い回させない)。
     */
    private static function isBound(mixed $payload, ?int $userId, int $flowId): bool
    {
        return is_array($payload) && count($payload) === 5 && ($payload['purpose'] ?? null) === self::PURPOSE
            && $userId !== null && ($payload['copier'] ?? null) === $userId && ($payload['flow'] ?? null) === $flowId;
    }

    /**
     * 解いた JSON(解けない・JSON でなければ null)。
     */
    private function decrypted(string $sealed): mixed
    {
        try {
            return json_decode($this->encrypter->decryptString($sealed), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }
    }

    /**
     * 印と参照者の形(restricted = 真偽、viewers = 相手 {type, id} の配列)に合えば、キーの順をそろえた値。合わなければ null。
     *
     * @return CopiedRestriction|null
     */
    private static function restrictionOf(mixed $value): ?array
    {
        if (! is_array($value) || ! is_bool($value['restricted'] ?? null) || ! is_array($value['viewers'] ?? null)) {
            return null;
        }

        $viewers = array_map(self::partyOf(...), array_values($value['viewers']));

        return array_is_list($value['viewers']) && ! in_array(null, $viewers, true)
            ? ['restricted' => $value['restricted'], 'viewers' => $viewers]
            : null;
    }

    /**
     * @return CopiedParty|null
     */
    private static function partyOf(mixed $value): ?array
    {
        if (! is_array($value) || count($value) !== 2 || ! is_int($value['id'] ?? null)) {
            return null;
        }

        return match ($value['type'] ?? null) {
            'user' => ['type' => 'user', 'id' => $value['id']],
            'department' => ['type' => 'department', 'id' => $value['id']],
            default => null,
        };
    }
}
