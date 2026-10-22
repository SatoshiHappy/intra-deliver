import { useEffect } from 'react';
import type { RefObject } from 'react';

/** エラーのある欄とフォームの先頭の文(送った後に、文書の順で最初のものへフォーカスを移す。業務データの入れ物の登録・編集・項目の追加と編集とワークフローの申請の画面で同じ) */
const INVALID_SELECTOR = '[aria-invalid="true"], [role="alert"]';

/**
 * 送信の失敗ごとに増える合図(request)が変わったら、フォームの中の最初の誤りへフォーカスを移す(0 = まだ失敗していない)。
 * 誤りの欄が畳んだ見出しの中にあるときは、呼び手が同じ更新で見出しを開く(RecordHeadingGroups の reveal)ので、欄は開いた後に描かれている。
 */
export function useFocusFirstInvalid(
    formRef: RefObject<HTMLFormElement | null>,
    request: number,
): void {
    useEffect(() => {
        if (request > 0) {
            formRef.current
                ?.querySelector<HTMLElement>(INVALID_SELECTOR)
                ?.focus();
        }
    }, [formRef, request]);
}
