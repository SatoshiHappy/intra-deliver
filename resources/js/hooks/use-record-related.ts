import { router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { RefObject } from 'react';
import {
    RELATED_TAB,
    relatedDialogStatus,
    selectedRelatedKey,
} from '@/lib/recordRelated';
import { urlWithQuery } from '@/lib/recordShow';
import type {
    RecordRelatedForm,
    RecordRelatedRecords,
    RecordRelatedRows,
} from '@/types';

/** 表の props(部分の再読み込みで求める名前) */
const ROWS_PROP = 'relatedRecordRows';

/** 「新規」のダイアログのフォームの props */
const FORM_PROP = 'relatedRecordForm';

type Requests = {
    cancel: RefObject<(() => void) | null>;
    seq: RefObject<number>;
};

/**
 * 表だけを部分の再読み込みで引く(page = ページ送り。URL に映さない = 詳細の画面/README.md の 3 節)。送る前に前の要求を取り消す。
 */
function reloadRows(
    data: Record<string, string>,
    page: boolean,
    { cancel, seq }: Requests,
    setLoading: (loading: boolean) => void,
) {
    cancel.current?.();
    const mine = ++seq.current;
    router.reload({
        data,
        only: [ROWS_PROP],
        replace: true,
        preserveUrl: page,
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
 * 部分の再読み込みの応答の relatedRecordForm(無ければ null)。
 */
function formOf(props: Record<string, unknown>): RecordRelatedForm | null {
    const form = props[FORM_PROP];

    return typeof form === 'object' && form !== null
        ? (form as RecordRelatedForm)
        : null;
}

/**
 * 入口の「新規」のフォームを部分の再読み込みで引く(URL もその入口の関連レコードのタブにする)。受け取ったフォーム(null もある)を received、
 * 要求の終わり(成功・403・5xx・通信の失敗・取り消しのどれでも)を finished で知らせる。onHttpException は既定のエラーの面も出す(false を返さない)。
 */
function reloadForm(
    key: string,
    on: {
        received: (form: RecordRelatedForm | null) => void;
        finished: () => void;
    },
) {
    router.reload({
        data: { tab: RELATED_TAB, related: key },
        only: [FORM_PROP, ROWS_PROP],
        replace: true,
        onSuccess: (page) => on.received(formOf(page.props)),
        onHttpException: () => {
            on.finished();
        },
        onNetworkError: () => {
            on.finished();
        },
        onFinish: () => on.finished(),
    });
}

/**
 * 「新規」のダイアログ(開いた入口のキーと、部分の再読み込みで引いたフォーム。開いている間は部品の状態に持つ = 関連レコード.md の 7 節)。
 * 開くと、その入口の関連レコードのタブにする(登録の後の back() が同じ入口の表へ戻る = 4.2 節)。フォームを受け取らないまま要求が終わったら
 * (403・5xx・通信の失敗・取り消し・出せない入口で null)読み込めなかった状態にする。閉じた・開き直した後に届いた前の要求の結果は捨てる(連番)。
 * 閉じてもフォームは残し(閉じる途中の中身を変えない)、開くときに消す。
 */
function useRelatedDialog() {
    const [entryKey, setEntryKey] = useState<string | null>(null);
    const [form, setForm] = useState<RecordRelatedForm | null>(null);
    const [finished, setFinished] = useState(false);
    const seq = useRef(0);

    const open = (key: string) => {
        const mine = ++seq.current;
        const current = () => seq.current === mine;
        setEntryKey(key);
        setForm(null);
        setFinished(false);
        reloadForm(key, {
            received: (next) => {
                if (current()) {
                    setForm(next);
                }
            },
            finished: () => {
                if (current()) {
                    setFinished(true);
                }
            },
        });
    };
    // フォームは消さない(閉じのアニメの間に「読み込めなかった」に見えないよう。次に開くときに open が消す)
    const close = () => {
        seq.current++;
        setEntryKey(null);
    };
    const status = relatedDialogStatus(form, finished);

    return { open: entryKey !== null, form, status, openFor: open, close };
}

/**
 * 関連レコードのタブ・入口・表・ページ送り・「新規」のダイアログ(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の
 * 3 節・4 節)。選んだ入口の正本は URL のクエリ related(見えない入口は先頭)。入口を選ぶ・タブを開くは、表が props に無ければクエリを付けた
 * 部分の再読み込み(履歴は積まない)、あれば URL だけを置き換える。
 */
export function useRecordRelated(
    related: RecordRelatedRecords | null,
    rows: RecordRelatedRows | null,
) {
    const { url } = usePage();
    const entries = related?.entries ?? [];
    const selectedKey = selectedRelatedKey(
        url,
        entries.map((entry) => entry.key),
    );
    const [loading, setLoading] = useState(false);
    const cancel = useRef<(() => void) | null>(null);
    const seq = useRef(0);
    const dialog = useRelatedDialog();

    const select = (key: string | null) => {
        const next = key ?? selectedKey;

        if (rows !== null && rows.entry === next) {
            router.replace({
                url: urlWithQuery(url, { tab: RELATED_TAB, related: next }),
                preserveState: true,
                preserveScroll: true,
            });
        } else {
            const data: Record<string, string> = { tab: RELATED_TAB };
            reloadRows(
                next === null ? data : { ...data, related: next },
                false,
                { cancel, seq },
                setLoading,
            );
        }
    };
    const page = (number: number) => {
        if (selectedKey !== null) {
            const data = { tab: RELATED_TAB, related: selectedKey };
            reloadRows(
                { ...data, relatedPage: String(number) },
                true,
                { cancel, seq },
                setLoading,
            );
        }
    };
    // 選んだ入口と違う入口の表(切り替えの途中)は描かない
    const shown = rows !== null && rows.entry === selectedKey ? rows : null;

    return { entries, selectedKey, rows: shown, loading, select, page, dialog };
}
