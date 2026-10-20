/**
 * 定義のコードの入力時の検査(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.2・3.4)。業務処理の確かめ(PHP の App\Support\DefinitionText の
 * CODE_PATTERN と codeError の文言、App\Actions\Records\ValidatesDefinitionText の OPTION_CODE_PATTERN と optionCodeError の文言)の**書式だけの写し**で、欄を離れたときの目安に使う。
 * 予約語と一意はここでは見ない(業務処理だけ)。送信は止めず、正はサーバー。
 * 正規表現の本体と文言は PHP と同じ文字列で、片方だけ変わると tests/Architecture/RecordDefinitionCodeMirrorTest が落ちる。
 */

/** 業務モジュール・項目のコードの書式の誤りの文言 */
const CODE_MESSAGE =
    'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください';

/** 選択肢のコードの書式の誤りの文言 */
const OPTION_CODE_MESSAGE =
    'コードは英小文字・数字・_ の 50 文字までにしてください';

/**
 * 前後の空白を除いた値が書式に合わないときの文言(合うか、除いた後が空なら null。必須の判定はサーバー)。
 */
function formatError(
    code: string,
    pattern: RegExp,
    message: string,
): string | null {
    const trimmed = code.trim();

    return trimmed === '' || pattern.test(trimmed) ? null : message;
}

/**
 * 業務モジュール・項目・明細のモジュールのコードの書式の誤り(無ければ null)。
 */
export function codeError(code: string): string | null {
    return formatError(code, /^[a-z][a-z0-9_]{0,49}$/, CODE_MESSAGE);
}

/**
 * 選択肢のコードの書式の誤り(無ければ null。数字で始まってよい)。
 */
export function optionCodeError(code: string): string | null {
    return formatError(code, /^[a-z0-9_]{1,50}$/, OPTION_CODE_MESSAGE);
}
