import { Head, Link, router } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordFieldsSection } from '@/components/Records/RecordFieldsSection';
import { RecordRuleDialog } from '@/components/Records/RecordRuleDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { RULE_KIND_LABELS, ruleSummary } from '@/lib/Records/recordDefinitions';
import { edit, index, show } from '@/routes/records/admin/modules';
import { destroy as destroyRule } from '@/routes/records/admin/rules';
import { index as recordList } from '@/routes/records/data';
import type {
    DefinitionTextMaxLengths,
    RecordAdminHeading,
    RecordAdminItem,
    RecordAdminModule,
    RecordModuleSummary,
} from '@/types';

type Props = {
    module: RecordAdminModule;
    /** 項目のコード → 値を持つレコード(明細は明細の行)の数 */
    fieldUsage: Record<string, number>;
    /** 見出し(名前。並びは items の中の位置で決まる。明細のモジュールは空) */
    headings: RecordAdminHeading[];
    /** 見出しと項目の 1 本の並び(項目の表の行の順。明細のモジュールは項目だけ) */
    items: RecordAdminItem[];
    /** 見出しの切替の業務モジュール(一覧 ModuleIndex と同じ範囲・並び = 明細のモジュールを含む) */
    modules: RecordModuleSummary[];
    /** 規則の文言の文字数の上限(name を使う) */
    textMaxLengths: DefinitionTextMaxLengths;
};

/**
 * 業務モジュールの切替(見出しの行の右)。選ぶとその業務モジュールの定義の画面へ移る。
 */
function ModuleSwitch({
    current,
    modules,
}: {
    current: string;
    modules: RecordModuleSummary[];
}) {
    return (
        <Select
            value={current}
            onValueChange={(code) => router.get(show(code))}
        >
            <SelectTrigger
                aria-label="業務モジュールの切替"
                className="min-w-48"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {modules.map((item) => (
                    <SelectItem key={item.code} value={item.code}>
                        {item.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * 管理の業務モジュールの定義(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3)。
 */
export default function ModuleShow({
    module,
    headings,
    items,
    fieldUsage,
    modules,
    textMaxLengths,
}: Props) {
    return (
        <>
            <Head title={module.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-4">
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-semibold">
                                {module.name}
                            </h1>
                            {module.isVisible === false && (
                                <Badge variant="outline">非表示</Badge>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href={edit(module.code)}>編集</Link>
                            </Button>
                            <ModuleSwitch
                                current={module.code}
                                modules={modules}
                            />
                        </div>
                    </div>
                    <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
                        <dt className="text-muted-foreground">コード</dt>
                        <dd>{module.code}</dd>
                        {module.description !== null && (
                            <>
                                <dt className="text-muted-foreground">説明</dt>
                                <dd className="whitespace-pre-line">
                                    {module.description}
                                </dd>
                            </>
                        )}
                        {module.parent !== null && (
                            <>
                                <dt className="text-muted-foreground">
                                    親の業務モジュール
                                </dt>
                                <dd>
                                    <Link
                                        href={show(module.parent.code)}
                                        className="text-primary underline-offset-4 hover:underline"
                                    >
                                        {module.parent.name}
                                    </Link>
                                </dd>
                            </>
                        )}
                    </dl>
                    {module.parent === null && (
                        <div>
                            <Link
                                href={recordList(module.code)}
                                className="text-sm text-primary underline-offset-4 hover:underline"
                            >
                                レコード一覧
                            </Link>
                        </div>
                    )}
                </div>

                <RecordFieldsSection
                    module={module}
                    headings={headings}
                    items={items}
                    fieldUsage={fieldUsage}
                    nameMaxLength={textMaxLengths.name}
                />

                <section
                    aria-labelledby="module-rules"
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <div className="flex items-center justify-between gap-4">
                        <h2
                            id="module-rules"
                            className="text-[15px] font-semibold"
                        >
                            規則
                        </h2>
                        <RecordRuleDialog
                            module={module}
                            rule={null}
                            trigger={<Button size="sm">規則を追加</Button>}
                            messageMaxLength={textMaxLengths.name}
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
                                {module.rules.map((rule) => {
                                    const summary = ruleSummary(module, rule);

                                    return (
                                        <TableRow key={rule.id}>
                                            <TableCell>
                                                {RULE_KIND_LABELS[rule.kind]}
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                {summary}
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                {rule.message ?? (
                                                    <span className="text-muted-foreground">
                                                        既定の文言
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center justify-end gap-1">
                                                    <RecordRuleDialog
                                                        module={module}
                                                        rule={rule}
                                                        messageMaxLength={
                                                            textMaxLengths.name
                                                        }
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
                                })}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </div>
        </>
    );
}

ModuleShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: '業務モジュール', href: index() },
        ...(props.module.parent === null
            ? []
            : [
                  {
                      title: props.module.parent.name,
                      href: show(props.module.parent.code),
                  },
              ]),
        { title: props.module.name, href: show(props.module.code) },
    ],
});
