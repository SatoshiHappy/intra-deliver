import { Head, Link, router } from '@inertiajs/react';
import { RecordFieldsSection } from '@/components/Records/RecordFieldsSection';
import { RecordRelatedColumnsSection } from '@/components/Records/RecordRelatedColumnsSection';
import { RecordRulesSection } from '@/components/Records/RecordRulesSection';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, index, show } from '@/routes/records/admin/modules';
import { index as recordList } from '@/routes/records/data';
import type {
    DefinitionTextMaxLengths,
    RecordAdminHeading,
    RecordAdminItem,
    RecordAdminModule,
    RecordModuleSummary,
    RecordRelatedColumnSetting,
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
    /** 関連レコードの列の行(他モジュール参照の項目ごと。明細のモジュールは空 = レコードの共通機能/業務モジュールの管理.md の 4・6 節) */
    relatedColumns: RecordRelatedColumnSetting[];
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
    relatedColumns,
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

                <RecordRulesSection
                    module={module}
                    messageMaxLength={textMaxLengths.name}
                />
                <RecordRelatedColumnsSection
                    moduleCode={module.code}
                    settings={relatedColumns}
                />
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
