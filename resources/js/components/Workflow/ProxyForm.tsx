import { usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { CaseActionCallout } from '@/components/Workflow/CaseActionNotices';
import { ProxyFormFields } from '@/components/Workflow/ProxyFormFields';
import { UserPickerField } from '@/components/Workflow/UserPickerField';
import { useProxyForm } from '@/hooks/Workflow/use-proxy-form';
import type { ProxyFormState } from '@/hooks/Workflow/use-proxy-form';
import { proxyAfterNoticeText } from '@/lib/Workflow/proxyDisplay';
import { candidateBaseDate } from '@/lib/Workflow/proxyForm';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowProxyFlow } from '@/types';

/** 欄の id */
const PRINCIPAL_ID = 'proxy-principal';
const PROXY_ID = 'proxy-target';

/** 必須の札 */
const REQUIRED = <Badge variant="secondary">必須</Badge>;

/**
 * 代理元(代理の管理だけ)と代理先の欄。人の候補の基準日は入力中の開始日(空・日付でなければ今日)。
 */
function PeopleFields({
    isAdmin,
    form,
    errors,
}: { isAdmin: boolean } & Pick<ProxyFormState, 'form' | 'errors'>) {
    const baseDate = candidateBaseDate(form.data.start_date);

    return (
        <>
            {isAdmin && (
                <UserPickerField
                    id={PRINCIPAL_ID}
                    label="代理元"
                    labelAddon={REQUIRED}
                    help="管理者は、代理元を選択して代理を追加できます。"
                    error={errors.principal_user_id}
                    caseId={null}
                    baseDate={baseDate}
                    picked={form.data.principal}
                    max={1}
                    onChange={(picked) => form.setData('principal', picked)}
                />
            )}
            <UserPickerField
                id={PROXY_ID}
                label="代理先"
                labelAddon={REQUIRED}
                help="代理先に指定できるのはユーザーだけです。代理先の代理先は、代わりに処理できません。"
                error={errors.proxy_user_id}
                caseId={null}
                baseDate={baseDate}
                picked={form.data.proxy}
                max={1}
                onChange={(picked) => form.setData('proxy', picked)}
            />
        </>
    );
}

/**
 * 注意の囲み「追加後の動き」(代理先と、代理の管理では代理元を選んだ後に出す。代理の設定の代理元は自分)。
 */
function AfterNotice({
    isAdmin,
    data,
}: {
    isAdmin: boolean;
    data: ProxyFormState['form']['data'];
}) {
    const { account } = usePage().props;
    const principal = isAdmin ? (data.principal[0] ?? null) : account.user;
    const proxy = data.proxy[0] ?? null;

    if (proxy === null || principal === null) {
        return null;
    }

    return (
        <CaseActionCallout
            title="追加後の動き"
            text={proxyAfterNoticeText(
                data.kind,
                personName(proxy),
                personName(principal),
            )}
        />
    );
}

/**
 * 代理を追加するダイアログの中身(開くたびに作り直す = 呼ぶ側の Dialog が閉じると外れる)。代理の管理(isAdmin)は先頭に「代理元」の欄を足す。
 * 欄と文言 = docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節。
 */
export function ProxyForm({
    isAdmin,
    flows,
    today,
    onSucceeded,
}: {
    isAdmin: boolean;
    flows: WorkflowProxyFlow[];
    today: string;
    onSucceeded: () => void;
}) {
    const { form, errors, submit } = useProxyForm(isAdmin, today, onSucceeded);

    return (
        <form
            noValidate
            onSubmit={submit}
            className="flex min-h-0 flex-col gap-4"
        >
            <DialogHeader>
                <DialogTitle>代理を追加する</DialogTitle>
                <DialogDescription>
                    {isAdmin
                        ? '代理元を選択して、代理を追加します。'
                        : '自分の承認や申請を、ほかの利用者が代わりに処理できるようにします。'}
                </DialogDescription>
            </DialogHeader>
            <div className="-mx-6 flex min-h-0 flex-col gap-4 overflow-y-auto px-6 py-1">
                <PeopleFields isAdmin={isAdmin} form={form} errors={errors} />
                <ProxyFormFields
                    data={form.data}
                    errors={errors}
                    flows={flows}
                    setData={form.setData}
                />
                <AfterNotice isAdmin={isAdmin} data={form.data} />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="outline">
                        キャンセル
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={form.processing}>
                    追加
                </Button>
            </DialogFooter>
        </form>
    );
}
