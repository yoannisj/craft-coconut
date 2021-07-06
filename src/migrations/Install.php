<?php

/**
 * Coconut plugin for Craft
 *
 * @author Yoannis Jamar
 * @copyright Copyright (c) 2020 Yoannis Jamar
 * @link https://github.com/yoannisj/
 * @package craft-coconut
 *
 */

namespace yoannisj\coconut\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use craft\helpers\MigrationHelper;

use yoannisj\coconut\Coconut;

/**
 * Migration class ran during plugin's (un-)installation
 */

class Install extends Migration
{
    /**
     * @inheritdoc
     */

    public function safeUp()
    {
        // check current tables
        $hasInputsTable = $this->db->tableExists(Coconut::TABLE_INPUTS);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if (!$hasInputsTable)
        {
            $this->createTable(Coconut::TABLE_INPUTS, [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->null(),
                'url' => $this->text()->unique()->null(),
                'urlHash' => $this->string(64)->unique()->null(),
                'status' => $this->string()->null(),
                'metadata' => $this->longText()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_inputs_assetId_fk',
                Coconut::TABLE_INPUTS, ['assetId'], Table::ASSETS, ['id'], 'CASCADE', null);

            $this->createIndex('craft_coconut_inputs_urlHash_idx',
                Coconut::TABLE_INPUTS, 'urlHash', false);
        }

        // create outputs table
        if (!$hasOutputsTable)
        {
            $this->createTable(Coconut::TABLE_OUTPUTS, [
                'id' => $this->primaryKey(),
                'inputId' => $this->integer()->notNull(),
                'coconutJobId' => $this->integer()->null(),
                'format' => $this->string()->notNull(),
                'url' => $this->string()->notNull(),
                'metadata' => $this->longText()->null(),
                'volumeId' => $this->integer()->null(),
                'status' => $this->string()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_outputs_inputId_fk',
                Coconut::TABLE_OUTPUTS, ['inputId'], Coconut::TABLE_INPUTS, ['id'], 'CASCADE', null);

            $this->addForeignKey('craft_coconut_outputs_volumeId_fk',
                Coconut::TABLE_OUTPUTS, ['volumeId'], Table::VOLUMES, ['id'], 'CASCADE', null);

            $this->createIndex('craft_outputs_format_idx',
                Coconut::TABLE_OUTPUTS, 'format', false);
            $this->createIndex('craft_outputs_coconutJobId_idx',
                Coconut::TABLE_OUTPUTS, 'coconutJobId', false);
        }

        // refresh database schema if any of the tables were created
        if (!$hasInputsTable || !$hasOutputsTable) {
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    /**
     * @inheritdoc
     */

    public function safeDown()
    {
        $this->dropTableIfExists(Coconut::TABLE_OUTPUTS);
        $this->dropTableIfExists(Coconut::TABLE_INPUTS);

        Craft::$app->db->schema->refresh();

        return true;
    }

}