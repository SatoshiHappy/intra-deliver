import type {
    WorkflowCaseAssignNode,
    WorkflowCaseMoveNode,
    WorkflowCaseReexpandNode,
} from '@/types';

/**
 * 申請操作の面とダイアログの文言(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節)。
 */

/** ダイアログで行う申請操作(面のボタンの順) */
export type OperationKind = 'retarget' | 'reexpand' | 'move' | 'assign';

/** 面のボタンの語 */
export const OPERATION_LABELS = {
    retarget: '処理担当者の変更',
    reexpand: '再展開',
    move: 'ステップの移動',
    assign: '指定の変更',
    release: '保留解除',
    destroy: '申請の削除',
} as const;

/** ダイアログの題 */
export const OPERATION_TITLES: Record<OperationKind, string> = {
    retarget: '処理担当者を変更する',
    reexpand: '再展開する',
    move: 'ステップを移動する',
    assign: '指定を変更する',
};

/** 面の見出しの横の「?」の説明 */
export const OPERATION_SURFACE_NOTE =
    '申請操作の権限がある人だけに表示されます。';

/** 申請の削除の確かめの文(案件操作.md の 15.7 節) */
export const DELETE_CONFIRM_TEXT =
    'この申請を削除しますか。申請書(申請内容・添付ファイル・コメント・更新履歴)も一緒に削除されます。この操作は取り消せません。';

/** 注意の囲み(見出しが null なら文だけ) */
type OperationCallout = { title: string | null; text: string };

/**
 * ダイアログの 2 行目(処理担当者の変更・再展開は申請名とステップ名の間を全角の空白 1 つで、ほかは申請名だけ)。
 */
export function operationSubtitleOf(
    kind: OperationKind,
    caseName: string,
    nodeName: string,
): string {
    return kind === 'retarget' || kind === 'reexpand'
        ? `${caseName}　${nodeName}`
        : caseName;
}

/**
 * 変更後の処理担当者の「?」(展開の行は 1 人だけ)。
 */
export function retargetHelpOf(single: boolean): string {
    return `${single ? '1 人' : '1 人以上'}を指定します。自分自身を除く、申請基準日に有効な利用者を指定できます。`;
}

/** 処理担当者の変更の注意の囲みの文 */
const RETARGET_CALLOUT_TEXT =
    '変更後の処理担当者の未処理に表示され、通知が届きます。差戻しなどで再び到達すると、フロー定義の指定で展開し直します。';

/**
 * 処理担当者の変更の注意の囲み(保留中なら保留が解除されることを見出しにする)。
 */
export function retargetCalloutOf(onHold: boolean): OperationCallout {
    return {
        title: onHold ? '保留は解除されます' : '処理担当者が替わります',
        text: RETARGET_CALLOUT_TEXT,
    };
}

/**
 * 再展開の注意の囲み(見出しは保留中のときだけ)。
 */
export function reexpandCalloutOf(onHold: boolean): OperationCallout {
    return {
        title: onHold ? '保留は解除されます' : null,
        text: '展開した結果が 0 人の場合は、再展開できません。',
    };
}

/**
 * 展開後の処理担当者の「?」(要約が無ければ undefined =「?」を出さない)。
 */
export function reexpandHelpOf(
    node: WorkflowCaseReexpandNode | undefined,
): string | undefined {
    return node === undefined || node.summary === null
        ? undefined
        : `${node.summary}を、申請基準日で展開した結果です。`;
}

/** 移動の種類(送る値。画面の並びの順) */
export type MoveDirection = 'back' | 'forward' | 'finish';

/** 移動の種類の語 */
export const MOVE_DIRECTIONS: { value: MoveDirection; label: string }[] = [
    { value: 'back', label: '戻る' },
    { value: 'forward', label: '進む' },
    { value: 'finish', label: '終了' },
];

/** 「移動の種類」の「?」 */
export const MOVE_TYPE_HELP =
    '戻るは、差戻しと同じ結果になります。進むは、現在のステップを承認したときと同じように進みます。終了は、承認終了と同じように申請を完了します。';

/** 進めないステップ(次が指定するステップ)で「進む」の「?」 */
export const FORWARD_BLOCKED_HELP =
    '次のステップの指定が必要なため、このステップは進められません。';

/** 戻せないステップ(戻り先の候補が無い = 再申請待ちの申請のステップ)で「戻る」の「?」(「進む」の文の形に倣った) */
export const BACK_BLOCKED_HELP =
    '戻せるステップがないため、このステップは戻せません。';

/**
 * 押せない移動の種類の「?」の文(押せる種類・終了・ステップが未選択は null)。
 */
export function blockedDirectionHelpOf(
    direction: MoveDirection,
    node: WorkflowCaseMoveNode | undefined,
): string | null {
    if (node === undefined) {
        return null;
    }

    if (direction === 'back') {
        return node.backNodes.length === 0 ? BACK_BLOCKED_HELP : null;
    }

    if (direction === 'forward') {
        return node.canForward ? null : FORWARD_BLOCKED_HELP;
    }

    return null;
}

/** 移動の種類ごとの注意の囲み */
const MOVE_CALLOUTS: Record<MoveDirection, OperationCallout> = {
    back: {
        title: '戻り先以降のステップは、未到達に戻ります',
        text: '戻り先の処理担当者の未処理に表示され、通知が届きます。',
    },
    forward: {
        title: '現在のステップを処理済みにして、次へ進みます',
        text: '次のステップの処理担当者の未処理に表示され、通知が届きます。',
    },
    finish: {
        title: '申請を完了します',
        text: '承認終了と同じように申請を完了します。この操作は取り消せません。',
    },
};

/**
 * ステップの移動の注意の囲み(移動の種類で替わる)。
 */
export function moveCalloutOf(direction: MoveDirection): OperationCallout {
    return MOVE_CALLOUTS[direction];
}

/**
 * 指定の変更の「ステップ」の選択肢の文(到達していないステップだけが候補なので、いつも同じ末尾)。
 */
export function assignOptionOf(node: WorkflowCaseAssignNode): string {
    return `${node.kindLabel}「${node.name}」　まだ到達していません`;
}

/**
 * 「変更後の指定」に札「必須」を付けるか(最小が 1 人以上のステップだけ = 空で送れない欄。最小 0 人は 0 人にすると削除される)。
 */
export function isAssignRequired(
    node: WorkflowCaseAssignNode | undefined,
): boolean {
    return node !== undefined && node.min >= 1;
}

/**
 * 「変更後の指定」の「?」(人数の規則 = 案件操作.md の 15.6 節)。
 */
export function assignHelpOf(
    node: WorkflowCaseAssignNode | undefined,
): string | undefined {
    return node === undefined
        ? undefined
        : `到達していない動的承認や配置のステップの指定を書き直します。${node.min} 人から ${node.max} 人まで指定します。最小が 0 人のステップは、0 人にすると削除されます。`;
}
