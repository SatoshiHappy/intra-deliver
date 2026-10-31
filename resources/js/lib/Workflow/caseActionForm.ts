import type {
    CaseActionNode,
    CaseActionTarget,
    CaseOperation,
} from '@/lib/Workflow/caseActions';
import { OPERATION_TEXTS } from '@/lib/Workflow/caseActions';
import type {
    WorkflowDesignation,
    WorkflowPerson,
    WorkflowPrincipal,
} from '@/types';

/**
 * 処理のダイアログの入力の形と、送る内容(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節)。
 */

/** 選んだ人(初期値 = 今の指定は所属を持たない。候補から選んだ人は主所属の名前を持つ) */
export type PickedUser = WorkflowPerson & {
    mainDepartmentName?: string | null;
};

/** 連絡の人数の上限(業務処理の ContactValidator と同じ値。超える分は送った時の 422 が正本) */
export const CONTACT_USER_MAX = 10;

/** 連絡のひと言の文字数の上限 */
export const CONTACT_MESSAGE_MAX = 1000;

/**
 * ダイアログの入力。principal = 選んだ処理担当者の id の文字列、assignments = 指定されるステップの id → 選んだ人、
 * to_user = 担当変更先(0 人か 1 人)、contact_users・contact_message = 連絡する人とひと言。
 */
export type ActionForm = {
    comment: string;
    node_id: string;
    principal: string;
    send_back_node_id: string;
    assignments: Record<string, PickedUser[]>;
    to_user: PickedUser[];
    contact_users: PickedUser[];
    contact_message: string;
};

/**
 * 人を選ぶ欄でもう 1 人追加できるか(上限 1 の欄は選び直すと置き換えるので、いつも選べる)。
 */
export function canAddUser(count: number, max: number): boolean {
    return max === 1 || count < max;
}

/**
 * 処理担当者の既定(先頭。自分が当たれば自分が先頭、当たらなければ先頭の代理元)。選択肢が無ければ空。
 */
export function defaultPrincipalOf(choices: WorkflowPrincipal[]): string {
    return choices.length === 0 ? '' : String(choices[0].id);
}

/**
 * ステップの欄の初期値(処理担当者 = 選択肢の先頭、差戻し先 = 候補の先頭、指定 = 今の指定)。
 */
export function nodeDefaults(
    node: CaseActionNode | undefined,
): Pick<ActionForm, 'principal' | 'send_back_node_id' | 'assignments'> {
    return {
        principal: defaultPrincipalOf(node?.principals ?? []),
        send_back_node_id:
            node === undefined || node.sendBackTargets.length === 0
                ? ''
                : String(node.sendBackTargets[0].id),
        assignments: Object.fromEntries(
            (node?.assigneeFields ?? []).map((assigneeField) => [
                String(assigneeField.nodeId),
                assigneeField.initial,
            ]),
        ),
    };
}

/**
 * 開いた時の入力の初期値(先頭のステップを選んでおく)。
 */
export function initialFormOf(target: CaseActionTarget): ActionForm {
    const first = target.nodes[0];

    return {
        comment: '',
        node_id: first === undefined ? '' : String(first.id),
        ...nodeDefaults(first),
        to_user: [],
        contact_users: [],
        contact_message: '',
    };
}

/** 選んでいるステップ(id の文字列で引く。無ければ undefined) */
export function nodeOf(
    nodes: CaseActionNode[],
    nodeId: string,
): CaseActionNode | undefined {
    return nodes.find((candidate) => String(candidate.id) === nodeId);
}

/**
 * 選んでいる処理担当者の選択肢(選んだ id が選択肢に無ければ先頭 = 既定。選択肢が無ければ undefined)。
 */
export function chosenPrincipalOf(
    choices: WorkflowPrincipal[],
    selected: string,
): WorkflowPrincipal | undefined {
    return (
        choices.find((choice) => String(choice.id) === selected) ?? choices[0]
    );
}

/**
 * 送る処理者 principal_user_id(自分なら null)。選択肢が無ければ props の値(引戻し = h の処理者、取止め = 申請者)をそのまま送る。
 */
export function principalValueOf(
    choices: WorkflowPrincipal[],
    selected: string,
    fixedId: number | null,
): number | null {
    const chosen = chosenPrincipalOf(choices, selected);

    if (chosen === undefined) {
        return fixedId;
    }

    return chosen.isSelf ? null : chosen.id;
}

/**
 * 代理元を選んだときに欄の下へ出す 1 行(自分を選んだ・選択肢が無いときは null)。操作の語は題でなく開くボタンの語に「します」を付ける。
 */
export function principalNoteOf(
    choices: WorkflowPrincipal[],
    selected: string,
    operationWord: string,
    selfName: string,
): string | null {
    const chosen = choices.find((choice) => String(choice.id) === selected);

    return chosen === undefined || chosen.isSelf
        ? null
        : `${chosen.name}さんの代理として${operationWord}します。履歴の処理実行者には、${selfName}(${chosen.name}さんの代理)と記録します。`;
}

/**
 * 連絡の送る形(人もひと言も無ければ null = 連絡なし。ひと言だけのときは人を空で送り、業務処理の 422 に任せる)。
 */
export function contactPayloadOf(
    users: PickedUser[],
    message: string,
): { user_ids: number[]; message: string } | null {
    return users.length === 0 && message.trim() === ''
        ? null
        : { user_ids: users.map((user) => user.id), message };
}

/**
 * 送る内容。assignments は指定の欄ごとに、選んだ人をユーザーの id の並びにする。担当変更先・連絡は、ある操作の入力だけ入れる。
 */
export function payloadOf(
    data: ActionForm,
    target: CaseActionTarget,
    assigneeFields: WorkflowDesignation[],
) {
    const node = nodeOf(target.nodes, data.node_id);
    const contact = contactPayloadOf(data.contact_users, data.contact_message);

    return {
        comment: data.comment,
        revision: target.revision,
        principal_user_id: principalValueOf(
            node?.principals ?? [],
            data.principal,
            node?.fixedPrincipalId ?? target.fixedPrincipalId,
        ),
        ...(data.node_id === '' ? {} : { node_id: Number(data.node_id) }),
        ...(target.operation === 'sendBack'
            ? { send_back_node_id: Number(data.send_back_node_id) }
            : {}),
        ...(target.operation === 'transfer' && data.to_user.length > 0
            ? { to_user_id: data.to_user[0].id }
            : {}),
        ...(assigneeFields.length > 0
            ? {
                  assignments: Object.fromEntries(
                      assigneeFields.map(({ nodeId }) => [
                          String(nodeId),
                          (data.assignments[String(nodeId)] ?? []).map(
                              (person) => person.id,
                          ),
                      ]),
                  ),
              }
            : {}),
        ...(contact === null ? {} : { contact }),
    };
}

/**
 * 処理担当者の欄を出すか(選択肢が 2 つ以上のときだけ)。
 */
export function showsPrincipalField(node: CaseActionNode | undefined): boolean {
    return (node?.principals.length ?? 0) >= 2;
}

/**
 * ダイアログの上部に出す案件の誤り。欄の下に出せない誤りも寄せる: 処理担当者の欄が無いとき(画面から起きるのは選択肢の古さだけ)の
 * principal_user_id と、添付の欄が無いとき(可否が偽の入口へ送った 422)の attachments。
 */
export function noticeErrorOf(
    errors: Record<string, string | undefined>,
    node: CaseActionNode | undefined,
    attachable: boolean,
): string | undefined {
    return (
        errors.case ??
        (showsPrincipalField(node) ? undefined : errors.principal_user_id) ??
        (attachable ? undefined : errors.attachments)
    );
}

/** 縦配置の子の否認のダイアログの文(docs の 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 6 節) */
const COUNCIL_REJECT_NOTE =
    '否認の票を記録します。可決できなくなった場合は、申請を否認して完了します。';

/**
 * ダイアログの説明の 1 行(操作ごとの文。縦配置の子の否認だけ票の文)。
 */
export function dialogNoteOf(
    operation: CaseOperation,
    node: CaseActionNode | undefined,
): string | null {
    return operation === 'reject' && node?.isCouncilVote === true
        ? COUNCIL_REJECT_NOTE
        : OPERATION_TEXTS[operation].note;
}

/**
 * 代理の 1 行の「{操作の語}します」の語(開くボタンの語)。
 */
export function operationWordOf(target: CaseActionTarget): string {
    return OPERATION_TEXTS[target.operation].trigger;
}
