<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
use yii\db\Migration;

class m261030_120000_thread_moves extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%move_content_thread_move}}')) { return; }
        $this->createTable('{{%move_content_thread_move}}', [
            'id' => $this->primaryKey(), 'source_model' => $this->string(255)->notNull(),
            'source_id' => $this->integer()->notNull(), 'target_model' => $this->string(255)->notNull(),
            'target_id' => $this->integer()->notNull(), 'source_content_id' => $this->integer()->notNull(),
            'snapshot' => $this->text()->notNull(), 'created_by' => $this->integer()->notNull(),
            'created_at' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex('idx_thread_move_source', '{{%move_content_thread_move}}', ['source_model', 'source_id']);
    }
    public function safeDown()
    {
        echo "Move history contains permalink redirects and cannot be removed automatically.\n";
        return false;
    }
}
