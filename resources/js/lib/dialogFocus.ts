import type { RefObject } from 'react';

/**
 * ダイアログを閉じた後のフォーカスの戻し(DialogContent の onCloseAutoFocus に渡す)。既定の戻し先を止め、returnFocusTo の要素が DOM に
 * 残っていればそこへ戻し、消えていれば何もしない(開いたボタンが描き直しで消えたとき)。
 */
export function focusBackOnClose(returnFocusTo: RefObject<HTMLElement | null>) {
    return (event: Event) => {
        event.preventDefault();

        if (returnFocusTo.current?.isConnected === true) {
            returnFocusTo.current.focus();
        }
    };
}
