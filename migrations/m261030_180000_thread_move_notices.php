<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
use yii\db\Migration;
use yii\db\Query;

class m261030_180000_thread_move_notices extends Migration
{
    public function safeUp()
    {
        $table = '{{%move_content_thread_move}}';
        $this->addColumn($table, 'reason', $this->text()->null());
        $this->addColumn($table, 'item_model', $this->string(255)->null());
        $this->addColumn($table, 'item_id', $this->integer()->null());
        $this->addColumn($table, 'reply_count', $this->integer()->notNull()->defaultValue(0));
        $rows = (new Query())->from($table)->orderBy(['id' => SORT_ASC])->all($this->db);
        $next = [];
        foreach ($rows as $row) {
            if ($row['source_model'] !== $row['target_model']) {
                $next[$row['source_model'] . ':' . $row['source_id']] = [$row['target_model'], (int)$row['target_id']];
            }
        }
        foreach ($rows as $row) {
            [$model, $id] = [$row['target_model'], (int)$row['target_id']];
            $seen = [];
            while (isset($next[$model . ':' . $id]) && !isset($seen[$model . ':' . $id])) {
                $seen[$model . ':' . $id] = true;
                [$model, $id] = $next[$model . ':' . $id];
            }
            $this->update($table, ['item_model' => $model, 'item_id' => $id], ['id' => $row['id']]);
        }
        $this->createIndex('idx_thread_move_item', $table, ['item_model', 'item_id', 'id']);
    }
    public function safeDown()
    {
        echo "Visible moderation history must not be removed automatically.\n";
        return false;
    }
}
