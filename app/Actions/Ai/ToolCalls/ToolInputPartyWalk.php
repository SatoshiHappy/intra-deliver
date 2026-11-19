<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentToolSubject;
use App\Enums\Ai\AiToolPartyForm;

/**
 * 書く道具の引数の相手の値を 1 回の引き当ての間たどり、直した値と、見つけた相手と、誤りの文をためる({@see ToolInputParties} の中だけで使う)。
 *
 * @phpstan-import-type PartyType from ToolPartyFields
 * @phpstan-import-type Slot from ToolInputParties
 * @phpstan-import-type Found from PartyCandidates
 */
final class ToolInputPartyWalk
{
    /** @var list<Slot> */
    public array $slots = [];

    /** @var list<string> */
    public array $errors = [];

    /** 名前の決まらない誤りを含むか(誤りの文の終わりに、利用者に選んでもらう文を足す) */
    public bool $nameErrors = false;

    public function __construct(
        private readonly PartyCandidates $candidates,
        private readonly AgentToolSubject $subject,
    ) {}

    /**
     * 相手の値 1 つ(複数の相手の列は要素ごと)を id の形に直す。
     *
     * @param  string  $field  誤りの文と相手の列で置き場を指す語
     * @param  PartyType  $type
     * @param  list<string>  $current  同じ欄の今の値の相手(種類:id)
     */
    public function value(string $field, string $type, mixed $value, array $current): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            return array_map(fn (mixed $element): mixed => $this->element($field, $type, $element, $current), $value);
        }

        return $this->element($field, $type, $value, $current);
    }

    /**
     * @param  PartyType  $type
     * @param  list<string>  $current
     */
    private function element(string $field, string $type, mixed $element, array $current): mixed
    {
        $name = is_array($element) && array_keys($element) === ['name'] && is_string($element['name'])
            ? $element['name']
            : null;

        return $name === null
            ? $this->byId($field, $type, $element, $current)
            : $this->byName($field, $type, $name, $element, $current);
    }

    /**
     * 名前の物を引いて id の形に直す(決まらなければ渡したまま)。
     *
     * @param  PartyType  $type
     * @param  list<string>  $current
     */
    private function byName(string $field, string $type, string $name, mixed $element, array $current): mixed
    {
        [$found, $error] = $this->candidates->resolve($field, $type, $name, $this->subject, AiToolPartyForm::Input);

        if ($found === null) {
            $this->errors[] = (string) $error;
            $this->nameErrors = true;

            return $element;
        }

        $this->found($found, $field, true, $current);

        return $type === 'user_or_department' ? ['type' => $found['type'], 'id' => $found['id']] : $found['id'];
    }

    /**
     * id の形の相手を残す(id の形でなければ誤り)。
     *
     * @param  PartyType  $type
     * @param  list<string>  $current
     */
    private function byId(string $field, string $type, mixed $element, array $current): mixed
    {
        $found = $type === 'user_or_department' ? self::kindAndId($element) : self::id($type, $element);

        if ($found === null) {
            $form = $type === 'user_or_department' ? '{"type": "user" か "department", "id": <id>}' : '<id>(整数)';
            $this->errors[] = "{$field} の相手の値の形が違う。相手は id の形 {$form} か、名前の物 {\"name\": \"…\"} で書く。";

            return $element;
        }

        $this->found($found, $field, false, $current);

        return $element;
    }

    /**
     * @param  Found  $found
     * @param  list<string>  $current
     */
    private function found(array $found, string $field, bool $byName, array $current): void
    {
        $this->slots[] = [
            'field' => $field,
            'type' => $found['type'],
            'id' => $found['id'],
            'byName' => $byName,
            'current' => $current,
        ];
    }

    /**
     * 型「ユーザー」「組織」の id の形(正の整数)。
     *
     * @param  'user'|'department'  $type
     * @return Found|null
     */
    private static function id(string $type, mixed $element): ?array
    {
        return is_int($element) && $element > 0 ? ['type' => $type, 'id' => $element] : null;
    }

    /**
     * 型「ユーザーと組織」の id の形 {"type": …, "id": <id>}(ほかの鍵を持たない)。
     *
     * @return Found|null
     */
    private static function kindAndId(mixed $element): ?array
    {
        if (! is_array($element) || count($element) !== 2) {
            return null;
        }

        $kind = $element['type'] ?? null;
        $id = $element['id'] ?? null;

        return ($kind === 'user' || $kind === 'department') && is_int($id) && $id > 0
            ? ['type' => $kind, 'id' => $id]
            : null;
    }
}
