import { Head } from '@inertiajs/react';
import { useRef } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock } from '@/components/FormBlock';
import { FormSurface } from '@/components/FormLayout';
import { RecordFields } from '@/components/RecordFields';
import { ApplyActions } from '@/components/Workflow/ApplyActions';
import { ApplyInfoSurface } from '@/components/Workflow/ApplyInfoSurface';
import { useApplyForm } from '@/hooks/Workflow/use-apply-form';
import { useFocusFirstInvalid } from '@/hooks/use-focus-first-invalid';
import { clearRowErrors } from '@/lib/formErrors';
import type { RecordFormData } from '@/lib/recordForm';
import { targetsText } from '@/lib/Workflow/workflowLabels';
import {
    create as applicationCreate,
    store as applicationStore,
} from '@/routes/workflow/applications';
import { index as casesIndex, show as caseShow } from '@/routes/workflow/cases';
import {
    apply as draftApply,
    edit as draftEdit,
    store as draftStore,
    update as draftUpdate,
} from '@/routes/workflow/drafts';
import { index as flowsIndex } from '@/routes/workflow/flows';
import {
    create as reapplicationCreate,
    store as reapplicationStore,
} from '@/routes/workflow/reapplications';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

type Props = WorkflowApplyProps;

/** 欄の下に出すキー(これ以外 = case・draft・record・封筒の誤りは、フォームの上に出す) */
function isFieldKey(key: string): boolean {
    return (
        key === 'name' ||
        key === 'comment' ||
        key === 'base_date' ||
        key.startsWith('values.') ||
        key.startsWith('details.')
    );
}

const COMMENT_ID = 'workflow-case-comment';

/**
 * 申請すると誰の未処理に出るかの 1 文(ルートの深さ/画面.md 5 節)。出せる人がいなければ空文字(0 人・版が無いときは別の文で示す)。
 */
function nextText(next: Props['next'], operation: string): string {
    if (next.dependsOnContent) {
        return `${operation}すると、内容に応じたステップの未処理に表示されます。`;
    }

    const first = next.nodes[0];

    if (first === undefined || first.targets.length === 0) {
        return '';
    }

    return `${operation}すると、${first.name}の${targetsText(first.targets)}${next.nodes.length > 1 ? 'ほか' : ''}の未処理に表示されます。`;
}

/**
 * 申請できない理由(ルートの深さ/画面.md 5 節。無ければ null)。業務処理が正本で、送っても 422 になる。
 */
function blockerOf(props: Props): string | null {
    if (props.mode !== 'reapply' && props.next.version === null) {
        return props.next.noVersionMessage;
    }

    for (const node of props.next.nodes) {
        if (node.targets.length === 0) {
            return `${node.name}の処理対象者がいないため、申請できません`;
        }

        if (node.blockedReason !== null) {
            return node.blockedReason;
        }
    }

    return null;
}

/**
 * 一時保存・再申請の案件の id(この 2 つの mode は案件を持つ。新規で呼ばない)。
 */
function caseIdOf(props: Props): number {
    if (props.case === null) {
        throw new Error('新規の申請の画面には案件が無い');
    }

    return props.case.id;
}

/**
 * 見出し(設計/ワークフロー/画面/申請と案件.md 3 節)。
 */
function headingOf(props: Props): string {
    return props.mode === 'reapply'
        ? `${props.initial?.name ?? ''}の再申請`
        : props.flow.name;
}

/**
 * 案件名の欄の既定の案内(業務処理が空の案件名に付ける既定と同じ形 = 設計/ワークフロー/画面/申請と案件.md 3 節の面 1)。
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

/** 申請の送り先(新規 = 申請、一時保存 = 一時保存からの申請、再申請 = 再申請) */
function applyActionOf(props: Props): RouteDefinition<'post'> {
    if (props.mode === 'new') {
        return applicationStore(props.flow.code);
    }

    return props.mode === 'draft'
        ? draftApply(caseIdOf(props))
        : reapplicationStore(caseIdOf(props));
}

/** 一時保存の送り先(新規 = 作成、一時保存 = 更新) */
function draftActionOf(props: Props): RouteDefinition<'post' | 'put'> {
    return props.mode === 'new'
        ? draftStore(props.flow.code)
        : draftUpdate(caseIdOf(props));
}

/** キャンセルの戻り先(新規 = フローの選択、一時保存 = 一覧の一時保存、再申請 = 案件の詳細) */
function backHrefOf(props: Props) {
    return props.mode === 'new'
        ? flowsIndex()
        : props.mode === 'draft'
          ? casesIndex({ query: { tab: 'draft' } })
          : caseShow(caseIdOf(props));
}

/**
 * 申請・一時保存・再申請(docs の 設計/ワークフロー/画面/申請と案件.md 3 節、ルートの深さ/画面.md 5 節)。業務データの欄は {@link RecordFields}(登録・編集のフォームと共通)。
 */
export default function Apply(props: Props) {
    const { mode, flow, case: workflowCase, module, headings, next } = props;
    const { form, errors, focusRequest, send, changeBaseDate } =
        useApplyForm(props);
    const formRef = useRef<HTMLFormElement>(null);
    const heading = headingOf(props);
    const operation = mode === 'reapply' ? '再申請' : '申請';
    const nextLine = nextText(next, operation);
    const blocker = blockerOf(props);
    const defaultName = defaultNameOf(props, form.data.record);
    const unshownErrors = Object.keys(errors)
        .filter((key) => !isFieldKey(key))
        .sort();
    const caseName =
        form.data.name.trim() === '' ? defaultName : form.data.name.trim();

    useFocusFirstInvalid(formRef, focusRequest);

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold wrap-anywhere">
                        {heading}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {`内容を入力して${operation}します。`}
                        {blocker === null && nextLine}
                    </p>
                </div>
                <form
                    ref={formRef}
                    noValidate
                    onSubmit={(event) => event.preventDefault()}
                    aria-label={`${heading}の入力`}
                    className="flex flex-col gap-6"
                >
                    {unshownErrors.map((key) => (
                        <p
                            key={key}
                            role="alert"
                            tabIndex={-1}
                            className="text-sm text-destructive"
                        >
                            {errors[key]}
                        </p>
                    ))}
                    <ApplyInfoSurface
                        mode={mode}
                        flowName={flow.name}
                        caseNumber={workflowCase?.number}
                        baseDate={props.baseDate}
                        baseDateInput={form.data.base_date}
                        baseDateRange={props.baseDateRange}
                        departmentName={next.departmentName}
                        nameMaxLength={props.nameMaxLength}
                        name={form.data.name}
                        defaultName={defaultName}
                        blocker={blocker}
                        errors={errors}
                        onChangeBaseDate={changeBaseDate}
                        onChangeName={(value) => form.setData('name', value)}
                    />
                    <FormSurface aria-labelledby="apply-record-heading">
                        <h2
                            id="apply-record-heading"
                            className="text-[15px] font-semibold"
                        >
                            {module.name}
                        </h2>
                        <RecordFields
                            module={module}
                            headings={headings}
                            revealRequest={focusRequest}
                            recordId={props.recordId}
                            data={form.data.record}
                            onChange={(update) =>
                                form.setData((data) => ({
                                    ...data,
                                    record: update(data.record),
                                }))
                            }
                            onRowsReordered={(prefix) =>
                                clearRowErrors(form, prefix)
                            }
                            errors={errors}
                        />
                    </FormSurface>
                    <FormSurface aria-labelledby="apply-comment-heading">
                        <h2
                            id="apply-comment-heading"
                            className="text-[15px] font-semibold"
                        >
                            コメント
                        </h2>
                        <FormBlock
                            id={COMMENT_ID}
                            label="コメント"
                            help="任意の入力です。"
                            error={errors.comment}
                        >
                            <CountedTextInput
                                id={COMMENT_ID}
                                isMultiline
                                value={form.data.comment}
                                max={props.commentMaxLength}
                                invalid={errors.comment !== undefined}
                                hasHelp
                                onChange={(event) =>
                                    form.setData('comment', event.target.value)
                                }
                            />
                        </FormBlock>
                    </FormSurface>
                    <ApplyActions
                        mode={mode}
                        workflowCase={workflowCase}
                        isProcessing={form.processing}
                        backHref={backHrefOf(props)}
                        operation={operation}
                        confirmDescription={`${caseName}を${operation}します。${nextLine}`}
                        isApplyDisabled={blocker !== null}
                        onSaveDraft={() => send(draftActionOf(props))}
                        onApply={() => send(applyActionOf(props))}
                    />
                </form>
            </div>
        </>
    );
}

Apply.layout = (props: Props) => ({
    breadcrumbs:
        props.mode === 'reapply'
            ? [
                  { title: '申請一覧', href: casesIndex() },
                  {
                      title: props.initial?.name ?? '',
                      href: caseShow(caseIdOf(props)),
                  },
                  {
                      title: '再申請',
                      href: reapplicationCreate(caseIdOf(props)),
                  },
              ]
            : [
                  { title: '申請の種類を選ぶ', href: flowsIndex() },
                  {
                      title: props.flow.name,
                      href:
                          props.mode === 'draft'
                              ? draftEdit(caseIdOf(props))
                              : applicationCreate(props.flow.code),
                  },
              ],
});
