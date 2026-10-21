import { hasBlankOrControl, safeLinkUrl } from '@/lib/richText';
import type {
    RecordReferenceValue,
    RecordRevisionRow,
    StatusTone,
} from '@/types';

/**
 * レコードの詳細の枠(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md)の分岐。
 * クエリの値は画面にあるキーの一覧で照らしてから使う(描画にそのまま使わない = 同じ文書の 9 節)。
 */

/** 左のタブの既定(詳細タブ = 3 節。保存形の details が明細を指すので fields) */
export const FIELDS_TAB = 'fields';

/** 明細の札のタブのキー */
export function detailsTabKey(code: string): string {
    return `details-${code}`;
}

/** 画面の URL(パスとクエリ)を読む(相対の URL も読めるよう、仮の起点を置く) */
function parsed(url: string): URL {
    return new URL(url, 'http://localhost');
}

/**
 * URL のクエリの値(無ければ null)。
 */
function queryValue(url: string, name: string): string | null {
    return parsed(url).searchParams.get(name);
}

/**
 * URL のクエリを変えた URL(パス・クエリ・ハッシュ。null の値はキーを消す)。
 */
export function urlWithQuery(
    url: string,
    changes: Record<string, string | null>,
): string {
    const next = parsed(url);

    for (const [name, value] of Object.entries(changes)) {
        if (value === null) {
            next.searchParams.delete(name);
        } else {
            next.searchParams.set(name, value);
        }
    }

    return `${next.pathname}${next.search}${next.hash}`;
}

/**
 * 開いている欄のキー(クエリ panel が帯の項目のキーのどれかのときだけ。ほかは閉じた形 = 3 節)。
 */
export function openPanelKey(url: string, keys: string[]): string | null {
    const key = queryValue(url, 'panel');

    return key !== null && keys.includes(key) ? key : null;
}

/**
 * 選んでいるタブのキー(クエリ tab がタブのキーのどれかのときだけ。ほかは詳細タブ = 3 節)。
 */
export function selectedTabKey(url: string, keys: string[]): string {
    const key = queryValue(url, 'tab');

    return key !== null && keys.includes(key) ? key : FIELDS_TAB;
}

/**
 * 業務ツールの節の URL をリンクにしてよいか(アプリ内の絶対パスか、リッチテキストと同じ判定 {@see safeLinkUrl} を通る http・https の URL。
 * 描画の層に頼らない防御の重ね)。外れた URL(javascript:・data:・//host・相対のパスなど)は呼び手が文字だけで出す。
 */
export function isLinkableUrl(url: string): boolean {
    if (/^\/(?![/\\])/.test(url)) {
        return !hasBlankOrControl(url);
    }

    return safeLinkUrl(url) !== null;
}

/**
 * タブの帯に並べる数(4 節のあふれ)。全部が入れば全部、入らなければ「その他」のボタンの幅を残して先頭から入るだけ(最低 1)。
 */
export function fittingTabCount(
    widths: number[],
    available: number,
    moreWidth: number,
): number {
    const total = widths.reduce((sum, width) => sum + width, 0);

    if (total <= available) {
        return widths.length;
    }

    let used = moreWidth;
    let count = 0;

    for (const width of widths) {
        if (used + width > available) {
            break;
        }

        used += width;
        count++;
    }

    return Math.max(count, 1);
}

const KIB = 1024;

/**
 * ファイルの大きさの表示(1,024 ごとの単位。KB は整数、MB・GB は小数 1 桁)。
 */
export function fileSizeText(bytes: number): string {
    if (bytes < KIB) {
        return `${bytes} B`;
    }

    if (bytes < KIB ** 2) {
        return `${Math.round(bytes / KIB)} KB`;
    }

    return bytes < KIB ** 3
        ? `${(bytes / KIB ** 2).toFixed(1)} MB`
        : `${(bytes / KIB ** 3).toFixed(1)} GB`;
}

/** プレビューの出し方: 画像・音声・動画は Dialog の中、そのほか(PDF)は新しいタブ */
export type PreviewKind = 'image' | 'audio' | 'video' | 'tab';

/**
 * 添付の型からプレビューの出し方を決める(型の頭で見る = 添付ファイル/README.md の 4 節)。
 */
export function previewKind(mime: string): PreviewKind {
    const head = mime.split('/')[0];

    return head === 'image' || head === 'audio' || head === 'video'
        ? head
        : 'tab';
}

/** 送っている間の進み具合の表示(value = null は割合が分からない) */
export type UploadProgress = { value: number | null; text: string };

/**
 * 送信の進み具合(Inertia の progress の percentage。0〜100)の描き分け。分からない・範囲の外は丸める。
 */
export function uploadProgress(percentage: number | undefined): UploadProgress {
    if (percentage === undefined || Number.isNaN(percentage)) {
        return { value: null, text: '送信しています' };
    }

    const value = Math.min(100, Math.max(0, Math.round(percentage)));

    return {
        value,
        text:
            value >= 100
                ? '送信が終わりました。処理しています'
                : `送信中 ${value}%`,
    };
}

/**
 * 人の表示(null = ゲスト。名称が無ければコード)。
 */
export function personText(person: RecordReferenceValue | null): string {
    return person === null
        ? 'ゲスト'
        : (person.label ?? person.code ?? `#${person.id}`);
}

/**
 * 更新履歴の頭の操作した人(代理のときは「<実行者>(<代理元>さんの代理)」= 更新履歴.md の 5.1 節)。
 */
export function revisionActorText(
    row: Pick<RecordRevisionRow, 'actor' | 'principal'>,
): string {
    const actor = personText(row.actor);

    return row.principal === null
        ? actor
        : `${actor}(${personText(row.principal)}さんの代理)`;
}

/**
 * 更新履歴の頭の操作(契機があればその語とステップ名、無ければ登録・編集)。
 */
export function revisionOperation(
    row: Pick<RecordRevisionRow, 'origin' | 'action'>,
): { label: string; nodeName: string | null } {
    if (row.origin !== null) {
        return { label: row.origin.operation, nodeName: row.origin.nodeName };
    }

    return { label: row.action === 'create' ? '登録' : '編集', nodeName: null };
}

/**
 * 変化の札(追加・削除・変更)と色。
 */
export function changeBadge(change: 'added' | 'removed' | 'changed'): {
    label: string;
    tone: StatusTone;
} {
    return {
        added: { label: '追加', tone: 'pending' as const },
        removed: { label: '削除', tone: 'rejected' as const },
        changed: { label: '変更', tone: 'returned' as const },
    }[change];
}

/**
 * レコードの削除の確かめの文(明細の項目が無ければ「明細・」を出さない = 添付ファイル/レコードの添付.md の 6 節)。
 */
export function deleteRecordDescription(
    heading: string,
    hasDetails: boolean,
): string {
    const parts = hasDetails ? '明細・添付ファイル' : '添付ファイル';

    return `${heading}を削除しますか。${parts}・コメント・更新履歴も一緒に削除されます。この操作は取り消せません。`;
}
