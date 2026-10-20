import { router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { RefObject } from 'react';
import { openPanelKey, urlWithQuery } from '@/lib/recordShow';
import type { RecordPanelContent, RecordPanelItem } from '@/types';

/** 欄の中身の props(部分の再読み込みで求める名前) */
const CONTENT_PROP = 'panelContent';

type Requests = {
    /** 前の要求の取り消し */
    cancel: RefObject<(() => void) | null>;
    /** 要求の連番(取り消した前の要求の完了が、今の要求の読み込み中の印を落とさないよう、今の要求だけが印を落とす) */
    seq: RefObject<number>;
};

/** 欄の中身だけを部分の再読み込みで引く(more = 「さらに表示」。行を後ろに足し、URL に映さない) */
function reloadContent(
    data: Record<string, string>,
    more: boolean,
    { cancel, seq }: Requests,
    setLoading: (loading: boolean) => void,
) {
    cancel.current?.();
    const mine = ++seq.current;
    router.reload({
        data,
        only: [CONTENT_PROP],
        reset: more ? [] : [CONTENT_PROP],
        replace: true,
        preserveUrl: more,
        onCancelToken: (token) => {
            cancel.current = token.cancel;
        },
        onStart: () => setLoading(true),
        onFinish: () => {
            if (seq.current === mine) {
                setLoading(false);
            }
        },
    });
}

/**
 * サイドパネルの欄の開閉と「さらに表示」(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 2.2 節・3 節)。
 * 開いた欄の正本は URL のクエリ panel(帯に無いキーは閉じた形)。開く・切り替えるはクエリ panel を付けた部分の再読み込み
 * (前の欄の行に足さないよう panelContent を reset。履歴は積まない)、閉じるは中身が要らないので URL だけを置き換える。
 * 「さらに表示」は before を付けた部分の再読み込み(URL に映さない。行は id で後ろに足される)。送る前に前の要求を取り消す。
 */
export function useRecordSidePanel(
    items: RecordPanelItem[],
    content: RecordPanelContent | null,
) {
    const { url } = usePage();
    const openKey = openPanelKey(
        url,
        items.map((item) => item.key),
    );
    const [loading, setLoading] = useState(false);
    const cancel = useRef<(() => void) | null>(null);
    const seq = useRef(0);

    const reload = (data: Record<string, string>, more: boolean) =>
        reloadContent(data, more, { cancel, seq }, setLoading);

    const close = () => {
        cancel.current?.();
        router.replace({
            url: urlWithQuery(url, { panel: null }),
            props: (props) => ({ ...props, [CONTENT_PROP]: null }),
            preserveState: true,
            preserveScroll: true,
        });
    };

    const toggle = (key: string) =>
        key === openKey ? close() : reload({ panel: key }, false);

    const more = (beforeId: number) => {
        if (openKey !== null) {
            reload({ panel: openKey, before: String(beforeId) }, true);
        }
    };

    // 開いた欄と違う欄の中身(切り替えの途中)は描かない
    const shown =
        content !== null && contentKey(content) === openKey ? content : null;

    return { openKey, content: shown, loading, toggle, close, more };
}

function contentKey(content: RecordPanelContent): string {
    return content.kind === 'section' ? content.code : content.kind;
}
