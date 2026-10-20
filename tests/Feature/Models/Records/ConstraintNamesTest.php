<?php

namespace Tests\Feature\Models\Records;

use Illuminate\Support\Facades\DB;
use Tests\Support\RecordsCommonSchema;
use Tests\Support\RecordsSchema;
use Tests\Support\TransactionalTestCase;

/**
 * 業務データの入れ物の 6 表とレコードの共通機能の 6 表の制約と索引の名前(docs の 設計/業務データの入れ物/データモデル/制約の分担と検査.md 5 節・7 節: 名前は 設計/共通マスタ/共通の規則.md 9 節の形で、
 * 63 バイトを超える名前を PostgreSQL が切り詰めても重ならない)。設計どおりの名前を切り詰めた一覧と、マイグレーションの後の DB の名前を照合する
 * (共通機能の表の名前 = 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2 節)。
 */
final class ConstraintNamesTest extends TransactionalTestCase
{
    private const IDENTIFIER_MAX_BYTES = 63;

    /** 設計どおりの名前(切り詰める前)。主キー・一意・外部キー・検査制約と、索引(一意制約の索引は同じ名前) */
    private const NAMES = [
        'record_modules_pkey',
        'record_modules_code_unique',
        'record_modules_id_parent_record_module_id_unique',
        'record_modules_id_is_detail_unique',
        'record_modules_parent_record_module_id_foreign',
        'record_modules_parent_record_module_id_index',
        'record_modules_code_check',
        'record_modules_name_check',
        'record_modules_parent_record_module_id_id_check',
        'record_modules_parent_record_module_id_icon_check',
        'record_modules_parent_record_module_id_is_attachment_enabled_check',
        'record_modules_sort_key_check',
        'record_modules_row_access_scope_check',
        'record_modules_parent_record_module_id_row_access_scope_check',
        'record_fields_pkey',
        'record_fields_record_module_id_code_unique',
        'record_fields_record_module_id_id_unique',
        'record_fields_id_referenced_record_module_id_unique',
        'record_fields_child_record_module_id_unique',
        'record_fields_record_module_id_unique',
        'record_fields_record_module_id_foreign',
        'record_fields_referenced_record_module_id_foreign',
        'record_fields_child_record_module_id_record_module_id_foreign',
        'record_fields_referenced_record_module_id_index',
        'record_fields_child_record_module_id_record_module_id_index',
        'record_fields_code_check',
        'record_fields_name_check',
        'record_fields_type_check',
        'record_fields_sort_key_check',
        'record_fields_type_referenced_record_module_id_check',
        'record_fields_type_child_record_module_id_check',
        'record_fields_type_is_multiline_max_length_check',
        'record_fields_type_is_required_check',
        'record_fields_type_is_listed_is_searchable_check',
        'record_fields_type_is_multiline_is_title_check',
        'record_fields_default_value_type_check',
        'record_fields_id_is_multiple_unique',
        'record_fields_type_is_multiple_check',
        'record_fields_type_grants_row_access_check',
        'record_field_options_pkey',
        'record_field_options_record_field_id_code_unique',
        'record_field_options_record_field_id_foreign',
        'record_field_options_code_check',
        'record_field_options_name_check',
        'record_field_options_sort_key_check',
        'record_rules_pkey',
        'record_rules_record_module_id_foreign',
        'record_rules_record_module_id_target_record_field_id_foreign',
        'record_rules_record_module_id_compared_record_field_id_foreign',
        'record_rules_record_module_id_target_record_field_id_index',
        'record_rules_record_module_id_compared_record_field_id_index',
        'record_rules_kind_check',
        'record_rules_operator_check',
        'record_rules_kind_condition_operator_compared_record_field_id_check',
        'record_rules_condition_check',
        'record_rules_target_record_field_id_compared_record_field_id_check',
        'record_rules_message_check',
        'records_pkey',
        'records_record_module_id_id_unique',
        'records_record_module_id_foreign',
        'records_parent_record_module_id_parent_record_id_foreign',
        'records_record_module_id_parent_record_module_id_foreign',
        'records_record_module_id_is_detail_foreign',
        'records_created_by_user_id_foreign',
        'records_updated_by_user_id_foreign',
        'records_parent_record_module_id_parent_record_id_index',
        'records_record_module_id_parent_record_module_id_index',
        'records_record_module_id_is_detail_index',
        'records_created_by_user_id_index',
        'records_updated_by_user_id_index',
        'records_parent_record_module_id_parent_record_id_check',
        'records_field_values_check',
        'records_parent_record_id_sort_key_check',
        'records_sort_key_check',
        'record_references_pkey',
        'record_references_record_id_record_field_id_sort_key_unique',
        'record_references_record_id_record_field_id_user_id_unique',
        'record_references_record_id_record_field_id_department_id_unique',
        'record_references_record_field_id_is_multiple_foreign',
        'record_references_record_field_id_is_multiple_index',
        'record_references_is_multiple_sort_key_check',
        'record_references_sort_key_check',
        'record_references_record_module_id_record_id_foreign',
        'record_references_record_module_id_record_field_id_foreign',
        'record_references_referenced_record_module_id_referenced_record_id_foreign',
        'record_references_record_field_id_referenced_record_module_id_foreign',
        'record_references_user_id_foreign',
        'record_references_department_id_foreign',
        'record_references_record_module_id_record_id_index',
        'record_references_record_module_id_record_field_id_index',
        'record_references_referenced_record_id_referenced_record_module_id_index',
        'record_references_record_field_id_referenced_record_module_id_index',
        'record_references_user_id_index',
        'record_references_department_id_index',
        'record_references_referenced_record_id_user_id_department_id_referenced_record_module_id_check',
        'record_revisions_pkey',
        'record_revisions_record_id_revision_unique',
        'record_revisions_user_id_foreign',
        'record_revisions_principal_user_id_foreign',
        'record_revisions_record_module_id_record_id_foreign',
        'record_revisions_user_id_index',
        'record_revisions_principal_user_id_index',
        'record_revisions_record_module_id_record_id_index',
        'record_revisions_revision_check',
        'record_revisions_action_check',
        'record_revisions_action_revision_check',
        'record_revisions_snapshot_check',
        'record_revisions_labels_check',
        'record_revisions_origin_operation_check',
        'record_revisions_origin_node_name_check',
        'record_revisions_origin_operation_origin_node_name_principal_user_id_check',
        'record_revisions_principal_user_id_user_id_check',
        'record_attachments_pkey',
        'record_attachments_upload_id_unique',
        'record_attachments_upload_id_foreign',
        'record_attachments_user_id_foreign',
        'record_attachments_record_module_id_record_id_foreign',
        'record_attachments_user_id_index',
        'record_attachments_record_module_id_record_id_index',
        'record_attachments_name_check',
        'record_comments_pkey',
        'record_comments_user_id_foreign',
        'record_comments_deleted_by_user_id_foreign',
        'record_comments_record_module_id_record_id_foreign',
        'record_comments_user_id_index',
        'record_comments_deleted_by_user_id_index',
        'record_comments_record_module_id_record_id_id_index',
        'record_comments_body_check',
        'record_comments_deleted_at_body_deleted_by_user_id_check',
        'record_comment_mentions_pkey',
        'record_comment_mentions_record_comment_id_user_id_unique',
        'record_comment_mentions_record_comment_id_foreign',
        'record_comment_mentions_user_id_foreign',
        'record_comment_mentions_user_id_index',
        'record_field_headings_pkey',
        'record_field_headings_record_module_id_foreign',
        'record_field_headings_record_module_id_index',
        'record_field_headings_name_check',
        'record_field_headings_sort_key_check',
        'record_related_columns_pkey',
        'record_related_columns_record_field_id_column_record_field_id_unique',
        'record_related_columns_record_field_id_foreign',
        'record_related_columns_record_module_id_column_record_field_id_foreign',
        'record_related_columns_column_record_field_id_record_module_id_index',
        'record_related_columns_sort_key_check',
    ];

    public function test_the_truncated_designed_names_do_not_collide(): void
    {
        $truncated = $this->truncatedNames();

        $this->assertSame(count(self::NAMES), count(array_unique($truncated)));
    }

    public function test_the_database_has_exactly_the_designed_names(): void
    {
        $tables = '{'.implode(',', array_keys(RecordsSchema::COLUMNS + RecordsCommonSchema::COLUMNS)).'}';
        $constraints = DB::table('pg_constraint')->whereRaw('conrelid::regclass::text = any(?)', [$tables])->where('contype', '<>', 'n')->pluck('conname')->all();
        $indexes = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))->whereRaw('tablename = any(?)', [$tables])->pluck('indexname')->all();

        $actual = array_values(array_unique([...$constraints, ...$indexes]));
        $expected = array_values(array_unique($this->truncatedNames()));
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    /**
     * 設計どおりの名前を、PostgreSQL の識別子の上限で切り詰めたもの。
     *
     * @return list<string>
     */
    private function truncatedNames(): array
    {
        return array_map(fn (string $name): string => substr($name, 0, self::IDENTIFIER_MAX_BYTES), self::NAMES);
    }
}
