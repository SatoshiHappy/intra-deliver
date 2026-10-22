/**
 * サーバーの誤りを入力の名前の経路(`child_module.code`・`options.0.code`)で消す。
 * Inertia の `clearErrors` の型はフォームの値のキーに限られるので、キャストをここに閉じ込める。
 */
export function clearServerErrors(
    form: { clearErrors: (...fields: never[]) => void },
    keys: string[],
): void {
    // 引数なしの clearErrors() は全部の誤りを消すので、キーが無いときは呼ばない
    if (keys.length > 0) {
        form.clearErrors(...(keys as never[]));
    }
}

/** 並びが変わると添字のキーの誤りが別の行に出るので、その表の誤り(prefix で始まるキー)を消す(業務の画面.md 2.2「エラー」) */
export function clearRowErrors(
    form: { errors: object; clearErrors: (...fields: never[]) => void },
    prefix: string,
): void {
    clearServerErrors(
        form,
        Object.keys(form.errors).filter((key) => key.startsWith(prefix)),
    );
}

/**
 * 欄の下に出さない誤りのキー(キーの順。フォームの先頭に出す = FormBlock の UnshownErrors)。shownKeys = その画面が欄の下に出すキー。
 */
export function unshownErrorKeys(
    errors: Record<string, string | undefined>,
    shownKeys: string[],
): string[] {
    return Object.keys(errors)
        .filter((key) => !shownKeys.includes(key))
        .sort();
}
