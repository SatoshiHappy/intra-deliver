import { Search } from 'lucide-react';
import { FormBlock } from '@/components/FormBlock';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CASE_OPERATION_SCOPES } from '@/lib/Workflow/caseOperationList';
import { visitCaseOperations } from '@/lib/Workflow/caseOperationVisit';
import type { WorkflowCaseOperationQuery } from '@/types';

/** 検索の語の文字数の上限(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.1 節) */
const KEYWORD_MAX_LENGTH = 100;

/** 「すべてのフロー」の選択肢の値(Select は空の値を持てない) */
const ALL_FLOWS = 'all';

/** 検索の虫眼鏡の見た目 */
const SEARCH_ICON_CLASS =
    'pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground';

type Props = {
    /** 読み取りが解釈した絞り込み(キーワードの欄の語は typedQ) */
    query: WorkflowCaseOperationQuery;
    flows: { code: string; name: string }[];
    typedQ: string;
    onKeywordChange: (next: string) => void;
    onKeywordSubmit: () => void;
};

/**
 * 絞り込みの変更を、欄の語を添えて一覧に反映する(ページは 1 に戻る)。
 */
function change(props: Props, next: Partial<WorkflowCaseOperationQuery>) {
    visitCaseOperations({ ...props.query, q: props.typedQ, ...next });
}

/**
 * フローの選択(既定「すべてのフロー」)。
 */
function FlowFilter(props: Props) {
    const id = 'case-operation-flow';

    return (
        <FormBlock id={id} label="フロー">
            <Select
                value={props.query.flow ?? ALL_FLOWS}
                onValueChange={(next) =>
                    change(props, { flow: next === ALL_FLOWS ? null : next })
                }
            >
                <SelectTrigger id={id} className="w-56">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL_FLOWS}>すべてのフロー</SelectItem>
                    {props.flows.map((flow) => (
                        <SelectItem key={flow.code} value={flow.code}>
                            {flow.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </FormBlock>
    );
}

/**
 * 状態の切替(未完了・完了)。
 */
function ScopeFilter(props: Props) {
    const id = 'case-operation-scope';

    return (
        <FormBlock id={id} label="状態">
            <RadioGroup
                value={props.query.scope}
                onValueChange={(next) =>
                    change(props, {
                        scope: next === 'completed' ? 'completed' : 'active',
                    })
                }
                aria-labelledby={`${id}-label`}
                className="flex gap-6"
            >
                {CASE_OPERATION_SCOPES.map(({ scope, label }) => (
                    <div key={scope} className="flex items-center gap-2">
                        <RadioGroupItem id={`${id}-${scope}`} value={scope} />
                        <Label htmlFor={`${id}-${scope}`}>{label}</Label>
                    </div>
                ))}
            </RadioGroup>
        </FormBlock>
    );
}

/**
 * キーワードの欄(入力の停止か Enter で引き直す。待ちは呼び手の useCaseSearch)。
 */
function KeywordFilter(props: Props) {
    const id = 'case-operation-keyword';

    return (
        <FormBlock id={id} label="キーワード">
            <form
                className="relative w-64"
                onSubmit={(event) => {
                    event.preventDefault();
                    props.onKeywordSubmit();
                }}
            >
                <Search aria-hidden="true" className={SEARCH_ICON_CLASS} />
                <Input
                    id={id}
                    type="search"
                    placeholder="申請番号・申請名"
                    maxLength={KEYWORD_MAX_LENGTH}
                    value={props.typedQ}
                    onChange={(event) =>
                        props.onKeywordChange(event.target.value)
                    }
                    className="pl-9"
                />
            </form>
        </FormBlock>
    );
}

/**
 * 申請操作の一覧の絞り込みの行(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.1 節): フロー(既定「すべてのフロー」)・
 * 状態(未完了・完了)・キーワード・「期限超過だけ」(完了のときは押せない)。変えるとすぐ一覧を取り直す(キーワードは入力の停止か Enter)。
 */
export function CaseOperationFilter(props: Props) {
    const overdueId = 'case-operation-overdue';

    return (
        <div
            role="search"
            aria-label="申請の絞り込み"
            className="flex flex-wrap items-start gap-x-8 gap-y-4"
        >
            <FlowFilter {...props} />
            <ScopeFilter {...props} />
            <KeywordFilter {...props} />
            <div className="flex items-center gap-2 self-end pb-2">
                <Checkbox
                    id={overdueId}
                    checked={props.query.overdue}
                    disabled={props.query.scope === 'completed'}
                    onCheckedChange={(checked) =>
                        change(props, { overdue: checked === true })
                    }
                />
                <Label htmlFor={overdueId}>期限超過だけ</Label>
            </div>
        </div>
    );
}
