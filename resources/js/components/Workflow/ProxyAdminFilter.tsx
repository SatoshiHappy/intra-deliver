import { FormBlock } from '@/components/FormBlock';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { UserPickerField } from '@/components/Workflow/UserPickerField';
import { PROXY_KIND_LABELS } from '@/lib/Workflow/proxyDisplay';
import { visitProxyList } from '@/lib/Workflow/proxyListVisit';
import type { WorkflowAdminProxyQuery, WorkflowProxyKind } from '@/types';

/** 種類の選択肢(すべて = 絞らない) */
const KIND_CHOICES: { value: 'all' | WorkflowProxyKind; label: string }[] = [
    { value: 'all', label: 'すべて' },
    { value: 'approve', label: PROXY_KIND_LABELS.approve },
    { value: 'apply', label: PROXY_KIND_LABELS.apply },
];

/**
 * 種類の切替(すべて・承認・申請。すべて = 絞らない)。
 */
function KindFilter({ query }: { query: WorkflowAdminProxyQuery }) {
    const id = 'proxy-filter-kind';

    return (
        <FormBlock id={id} label="種類">
            <RadioGroup
                value={query.kind ?? 'all'}
                onValueChange={(next) => {
                    const choice = KIND_CHOICES.find(
                        (one) => one.value === next,
                    );

                    if (choice !== undefined) {
                        visitProxyList({
                            ...query,
                            kind: choice.value === 'all' ? null : choice.value,
                        });
                    }
                }}
                aria-labelledby={`${id}-label`}
                className="flex gap-6"
            >
                {KIND_CHOICES.map((choice) => (
                    <div key={choice.value} className="flex items-center gap-2">
                        <RadioGroupItem
                            id={`${id}-${choice.value}`}
                            value={choice.value}
                        />
                        <Label htmlFor={`${id}-${choice.value}`}>
                            {choice.label}
                        </Label>
                    </div>
                ))}
            </RadioGroup>
        </FormBlock>
    );
}

/**
 * 代理の管理の絞り込み(代理元・代理先の検索、種類、有効な代理だけ。管理と通知の画面.md の 7.4 節)。選ぶとすぐ一覧を取り直す。
 */
export function ProxyAdminFilter({
    query,
}: {
    query: WorkflowAdminProxyQuery;
}) {
    return (
        <div
            role="search"
            aria-label="代理の絞り込み"
            className="flex flex-wrap items-start gap-x-8 gap-y-4"
        >
            <UserPickerField
                id="proxy-filter-principal"
                label="代理元"
                caseId={null}
                picked={query.principal === null ? [] : [query.principal]}
                max={1}
                onChange={(picked) =>
                    visitProxyList({ ...query, principal: picked[0] ?? null })
                }
            />
            <UserPickerField
                id="proxy-filter-proxy"
                label="代理先"
                caseId={null}
                picked={query.proxy === null ? [] : [query.proxy]}
                max={1}
                onChange={(picked) =>
                    visitProxyList({ ...query, proxy: picked[0] ?? null })
                }
            />
            <KindFilter query={query} />
            <div className="flex items-center gap-2 self-end pb-2">
                <Checkbox
                    id="proxy-filter-active"
                    checked={query.active}
                    onCheckedChange={(checked) =>
                        visitProxyList({ ...query, active: checked === true })
                    }
                />
                <Label htmlFor="proxy-filter-active">有効な代理だけ</Label>
            </div>
        </div>
    );
}
