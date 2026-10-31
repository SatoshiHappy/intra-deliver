import type { RecordFormData } from '@/lib/recordForm';
import {
    attachmentSurfaceOf,
    isAttachmentKey,
    isHiddenAttachmentError,
} from '@/lib/Workflow/applyAttachment';
import { applicationTargetOf } from '@/lib/Workflow/applicationTargetSnapshot';
import { targetsText } from '@/lib/Workflow/workflowLabels';
import type { WorkflowApplyAttachment, WorkflowApplyProps } from '@/types';

type Props = WorkflowApplyProps;

/**
 * 申請の画面の文・見出し・出し分け(docs の 設計/ワークフロー/画面/申請と案件.md の 3 節、設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md の 5 節)。
 * 見出しの下の 1 行と一時保存のボタンの有無は、mode と props で分かれるこの関数群({@link headingLineOf}・{@link showsSaveDraft})が決める。
 */

/** 申請の操作の語(再申請 = 再申請、ほか = 申請) */
export function operationOf(mode: Props['mode']): string {
    return mode === 'reapply' ? '再申請' : '申請';
}

/**
 * 欄の下に出すキーか(これ以外 = case・draft・record・封筒の誤りは、フォームの上に出す)。申請者の誤り(applicant_user_id・principal_user_id)は
 * 申請者の欄を出すときだけ欄の下に出す。
 */
export function isFieldKey(key: string, showsApplicant: boolean): boolean {
    return (
        key === 'name' ||
        key === 'comment' ||
        key === 'base_date' ||
        key.startsWith('values.') ||
        key.startsWith('details.') ||
        key.startsWith('contact.') ||
        (showsApplicant && key === 'applicant_user_id')
    );
}

/**
 * フォームの先頭に出す誤りのキー(欄の下に出ないもの)。添付の誤りは面の有無と使えるかで出し先が変わる
 * (設計/ワークフロー/申請の周辺/画面.md の 2 節)。
 */
export function unshownKeysOf(
    errors: Record<string, string | undefined>,
    showsApplicant: boolean,
    attachment: Pick<WorkflowApplyAttachment, 'enabled' | 'drafts'>,
): string[] {
    const showsAttachment = attachmentSurfaceOf(attachment).kind !== 'none';

    return Object.keys(errors)
        .filter(
            (key) =>
                !isFieldKey(key, showsApplicant) &&
                !(showsAttachment && isAttachmentKey(key)) &&
                !isHiddenAttachmentError(key, attachment.enabled),
        )
        .sort();
}

/**
 * 申請すると誰の未処理に出るかの 1 文(ルートの深さ/画面/案件の側の画面.md 5 節)。出せる人がいなければ空文字(0 人・版が無いときは別の文で示す)。
 */
export function nextText(next: Props['next'], operation: string): string {
    if (next.dependsOnContent) {
        return `${operation}すると、内容に応じたステップの未処理に表示されます。`;
    }

    const first = next.nodes[0];

    if (first === undefined || first.targets.length === 0) {
        return '';
    }

    const more = next.nodes.length > 1 ? 'ほか' : '';

    return `${operation}すると、${first.name}の${targetsText(first.targets)}${more}の未処理に表示されます。`;
}

/**
 * 申請できない理由(ルートの深さ/画面/案件の側の画面.md 5 節。無ければ null)。業務処理が正本で、送っても 422 になる。
 */
export function blockerOf(props: Props): string | null {
    if (props.mode !== 'reapply' && props.next.version === null) {
        return props.next.noVersionMessage;
    }

    for (const node of props.next.nodes) {
        if (node.targets.length === 0) {
            return `${node.name}の処理担当者がいないため、申請できません`;
        }

        if (node.blockedReason !== null) {
            return node.blockedReason;
        }
    }

    return null;
}

/**
 * 見出し(docs の 設計/ワークフロー/画面/申請と案件.md の 3 節)。
 */
function headingOf(props: Props): string {
    return props.mode === 'reapply'
        ? `${props.initial?.name ?? ''}の再申請`
        : props.flow.name;
}

/**
 * 見出しの下の 1 行の頭の文(文の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 8 節)。業務レコードの詳細から開いた画面は、
 * 今の申請対象の欄で決める(空になれば新規の申請の文)。
 */
function headingLeadOf(
    props: Props,
    record: RecordFormData,
    operation: string,
): string {
    if (props.mode === 'record') {
        const title = props.recordTitle ?? `#${props.recordId}`;

        return `${title}(既存のデータ)から申請します。`;
    }

    const applicationTarget =
        props.targetEntry === null
            ? null
            : applicationTargetOf(record, props.targetField);

    return applicationTarget === null
        ? `内容を入力して${operation}します。`
        : `${applicationTarget.label}を申請対象にして申請します。`;
}

/**
 * 見出しの下の 1 行(頭の文に、申請すると誰の未処理に出るかの文を、申請できない理由が無いときだけ添える)。
 */
export function headingLineOf(
    lead: string,
    nextLine: string,
    blocker: string | null,
): string {
    return `${lead}${blocker === null ? nextLine : ''}`;
}

/**
 * 一時保存のボタンを出すか(新規と一時保存の画面だけ。再申請は一時保存を持たず、既存のデータからの申請は一時保存がレコードを持てないので持たない)。
 */
export function showsSaveDraft(mode: Props['mode']): boolean {
    return mode === 'new' || mode === 'draft';
}

/**
 * 送信の誤りのうちトーストでも出すもの(正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節)。
 * 決めているのは既存のデータからの申請の画面だけなので、ほかの画面の誤りの見え方は変えない。
 */
export function toastedErrorsOf(
    mode: Props['mode'],
    errors: Record<string, string>,
): Record<string, string> {
    const record = errors.record;

    return mode !== 'record' || record === undefined ? {} : { record };
}

/**
 * 案件名の欄の既定の案内(業務処理が空の案件名に付ける既定と同じ形 = docs の 設計/ワークフロー/画面/申請と案件.md の 3 節の面 1)。
 */
function defaultNameOf(props: Props, record: RecordFormData): string {
    const draft =
        props.titleField === null
            ? null
            : (record.values[props.titleField] ?? null);
    const title =
        typeof draft === 'string'
            ? draft.trim()
            : draft !== null && typeof draft === 'object' && 'label' in draft
              ? draft.label
              : '';

    return title === '' ? props.flow.name : `${props.flow.name} ${title}`;
}

/**
 * 画面の文と値(フォームの値から決まる): 見出し・申請の語・見出しの下の 1 行・申請できない理由・案件名の既定の案内と、確かめのダイアログが示す案件名。
 */
export function applyViewOf(
    props: Props,
    record: RecordFormData,
    name: string,
) {
    const operation = operationOf(props.mode);
    const nextLine = nextText(props.next, operation);
    const blocker = blockerOf(props);
    const defaultName = defaultNameOf(props, record);

    return {
        heading: headingOf(props),
        operation,
        nextLine,
        blocker,
        defaultName,
        headingLine: headingLineOf(
            headingLeadOf(props, record, operation),
            nextLine,
            blocker,
        ),
        caseName: name.trim() === '' ? defaultName : name.trim(),
    };
}
