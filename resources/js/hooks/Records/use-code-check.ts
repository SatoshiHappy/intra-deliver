import { useState } from 'react';

type Options = {
    /** コードの今の値 */
    value: string;
    /** サーバーの誤り(無ければ undefined) */
    serverError: string | undefined;
    /** サーバーの誤りを消す */
    clearServerError: () => void;
    /** 書式の誤り(無ければ null。lib/Records/recordDefinitionCode) */
    check: (value: string) => string | null;
};

/**
 * コードの入力時の検査(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.2・3.4)。
 */
export function useCodeCheck({
    value,
    serverError,
    clearServerError,
    check,
}: Options) {
    const [isChecked, setChecked] = useState(false);
    const shownError =
        serverError ?? (isChecked ? (check(value) ?? undefined) : undefined);

    return {
        shownError,
        onBlur: () => setChecked(true),
        onChange: () => {
            if (serverError !== undefined) {
                clearServerError();
            }
        },
    };
}
