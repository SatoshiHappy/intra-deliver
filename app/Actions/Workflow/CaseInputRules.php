<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Support\InputText;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * 案件の入力の規則(docs の 設計/ワークフロー/案件の操作/同時実行と検査.md 6 節、操作.md 3.1・3.4、業務データとの接続/申請と書き込み.md 2 節)。
 * 入口と同じ変換({@see InputText})を先に掛けてから確かめる(直接の呼び出しでも HTTP の入口と同じ結果にするため)。
 */
class CaseInputRules
{
    /** 案件名の最大の文字数(列の長さ。正は永続化の層の定数) */
    public const NAME_MAX = WorkflowCase::NAME_MAX;

    /** コメントの最大の文字数(列の長さ。正は永続化の層の定数) */
    public const COMMENT_MAX = WorkflowCaseHistory::COMMENT_MAX;

    /** 一時保存の入力を json_encode(JSON_UNESCAPED_UNICODE)した文字列の最大のバイト数(4 MiB = 業務データとの接続/申請と書き込み.md 2 節) */
    public const DRAFT_MAX_BYTES = 4_194_304;

    /**
     * 申請・再申請の案件名とコメント(誤りは両方まとめて返す)。
     *
     * @return array{string|null, string|null} 変換した案件名(null = 既定にする)とコメント
     *
     * @throws ValidationException 案件名(name)・コメント(comment)の誤り
     */
    public function nameAndComment(?string $name, ?string $comment): array
    {
        $name = $this->normalized($name);
        $comment = $this->normalized($comment);
        $errors = array_filter(['name' => $this->nameError($name), 'comment' => $this->commentError($comment)], fn (?string $message) => $message !== null);

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }

        return [$name, $comment];
    }

    /**
     * 処理の操作のコメント。
     *
     * @throws ValidationException コメント(comment)の誤り
     */
    public function comment(?string $comment): ?string
    {
        return $this->nameAndComment(null, $comment)[1];
    }

    /**
     * 一時保存の入力(draft_input に書く形)。案件名・コメント・値・明細の全体を入口と同じ変換(前後の空白を除き空なら null)に掛けてから、大きさと使えない文字を見る
     * (HTTP の入口と直接の呼び出しで同じ結果にする)。
     *
     * @return array{name: string|null, comment: string|null, values: stdClass, details: stdClass, base_date: string|null}
     *
     * @throws ValidationException 使えない文字(U+0000・UTF-8 として正しくない文字列)・大きさの上限を超える(どちらも draft)
     */
    public function draftInput(DraftInput $input): array
    {
        $draft = JsonObjectMaps::draftInput($this->normalized($input->name), $this->normalized($input->comment), InputText::normalize($input->values), InputText::normalize($input->details), $this->normalized($input->baseDate));
        $json = json_encode($draft, JSON_UNESCAPED_UNICODE);

        if ($json === false || $this->containsNul($draft)) {
            throw ValidationException::withMessages(['draft' => [CaseOperationErrors::DRAFT_INVALID_CHARACTER]]);
        }

        if (strlen($json) > self::DRAFT_MAX_BYTES) {
            throw ValidationException::withMessages(['draft' => [CaseOperationErrors::DRAFT_TOO_LARGE]]);
        }

        return $draft;
    }

    /**
     * 前後の空白を除き、空なら null({@see InputText::normalize()})。
     */
    private function normalized(?string $text): ?string
    {
        $normalized = InputText::normalize($text);

        return is_string($normalized) ? $normalized : null;
    }

    private function nameError(?string $name): ?string
    {
        return match (true) {
            $name === null => null,
            ! mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0") => CaseOperationErrors::NAME_INVALID_CHARACTER,
            preg_match('/[\r\n]/', $name) === 1 => CaseOperationErrors::NAME_NEWLINE,
            mb_strlen($name) > self::NAME_MAX => CaseOperationErrors::NAME_TOO_LONG,
            default => null,
        };
    }

    private function commentError(?string $comment): ?string
    {
        return match (true) {
            $comment === null => null,
            ! mb_check_encoding($comment, 'UTF-8') || str_contains($comment, "\0") => CaseOperationErrors::COMMENT_INVALID_CHARACTER,
            mb_strlen($comment) > self::COMMENT_MAX => CaseOperationErrors::COMMENT_TOO_LONG,
            default => null,
        };
    }

    /**
     * 値・キーのどこかに U+0000 があるか(jsonb は \u0000 を受けない)。
     */
    private function containsNul(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, "\0");
        }

        if (! is_array($value) && ! $value instanceof stdClass) {
            return false;
        }

        foreach ((array) $value as $key => $item) {
            if (str_contains((string) $key, "\0") || $this->containsNul($item)) {
                return true;
            }
        }

        return false;
    }
}
