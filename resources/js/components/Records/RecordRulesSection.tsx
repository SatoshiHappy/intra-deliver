import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordRuleDialog } from '@/components/Records/RecordRuleDialog';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { RULE_KIND_LABELS, ruleSummary } from '@/lib/Records/recordDefinitions';
import { destroy as destroyRule } from '@/routes/records/admin/rules';
import type { RecordAdminModule, RecordRuleDefinition } from '@/types';

type Props = {
    module: RecordAdminModule;
    /** 規則の文言の文字数の上限 */
    messageMaxLength: number;
};

/**
 * 規則の表の 1 行(種別・中身・文言と、編集・削除)。
 */
function RuleRow({
    module,
    rule,
    messageMaxLength,
}: Props & { rule: RecordRuleDefinition }) {
    const summary = ruleSummary(module, rule);

    return (
        <TableRow>
            <TableCell>{RULE_KIND_LABELS[rule.kind]}</TableCell>
            <TableCell className="whitespace-normal">{summary}</TableCell>
            <TableCell className="whitespace-normal">
                {rule.message ?? (
                    <span className="text-muted-foreground">既定の文言</span>
                )}
            </TableCell>
            <TableCell>
                <div className="flex items-center justify-end gap-1">
                    <RecordRuleDialog
                        module={module}
                        rule={rule}
                        messageMaxLength={messageMaxLength}
                        trigger={
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label={`規則「${summary}」を編集`}
                            >
                                編集
                            </Button>
                        }
                    />
                    <ConfirmDeleteDialog
                        preserveScroll
                        triggerLabel={`規則「${summary}」を削除`}
                        title="規則の削除"
                        description={`規則「${summary}」を削除しますか。既存のレコードは変更されません。`}
                        action={destroyRule({
                            module: module.code,
                            rule: rule.id,
                        })}
                    />
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * 管理の業務モジュールの定義の面「規則」(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3): 規則の表と「規則を追加」。
 */
export function RecordRulesSection({ module, messageMaxLength }: Props) {
    return (
        <section
            aria-labelledby="module-rules"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex items-center justify-between gap-4">
                <h2 id="module-rules" className="text-[15px] font-semibold">
                    規則
                </h2>
                <RecordRuleDialog
                    module={module}
                    rule={null}
                    trigger={<Button size="sm">規則を追加</Button>}
                    messageMaxLength={messageMaxLength}
                />
            </div>
            {module.rules.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    規則はありません
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>種別</TableHead>
                            <TableHead>中身</TableHead>
                            <TableHead>文言</TableHead>
                            <TableHead>
                                <span className="sr-only">操作</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {module.rules.map((rule) => (
                            <RuleRow
                                key={rule.id}
                                module={module}
                                rule={rule}
                                messageMaxLength={messageMaxLength}
                            />
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}
