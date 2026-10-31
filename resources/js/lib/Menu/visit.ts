/** Inertia の訪問のうち、画面を移るかの判定に使う欄(router.on('start') の event.detail.visit のうち) */
type Visit = {
    only: readonly string[];
    except: readonly string[];
    reset: readonly string[];
    preserveState: unknown;
    url: URL;
};

/** ハッシュを除いた URL の文字列(Inertia が同じ画面かを見る比べ方と同じ) */
function withoutHash(url: URL): string {
    return `${url.origin}${url.pathname}${url.search}`;
}

/**
 * Inertia の訪問が、画面を移る訪問か。同じ画面に留まる訪問では、シートやランチャーのパネルを閉じない。留まる訪問は次の 2 つ:
 * ページの一部だけを読み直す訪問(only・except・reset のどれかが空でない。Inertia が部分の読み直しと見なす欄)と、
 * 今の画面と同じ URL で preserveState が真の訪問(router.reload() や、状態を残して同じ画面へ送る訪問)。
 * preserveState が関数や 'errors' のときは留まるか決まらないので、移る訪問として閉じる。今の画面のリンクを押す訪問は
 * preserveState が偽(既定)なので移る訪問で、閉じる。
 * 閉じる規則は docs の 設計/メニュー/表示.md 3.3.3(シート)と 設計/画面の意匠/骨格とヘッダー.md 3 節の 7(ランチャー)。
 */
export function isNavigatingVisit(visit: Visit, currentUrl: URL): boolean {
    const isPartial =
        visit.only.length > 0 ||
        visit.except.length > 0 ||
        visit.reset.length > 0;
    const staysOnPage =
        visit.preserveState === true &&
        withoutHash(visit.url) === withoutHash(currentUrl);

    return !isPartial && !staysOnPage;
}
