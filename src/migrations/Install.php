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
        $hasSourcesTable = $this->db->tableExists(Coconut::TABLE_SOURCES);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if (!$hasSourcesTable)
        {
            $this->createTable(Coconut::TABLE_SOURCES, [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->null(),
                'url' => $this->text()->null(),
                'urlHash' => $this->string(64)->null(),
                'metadata' => $this->longText()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_sources_assetId_fk',
                Coconut::TABLE_SOURCES, ['assetId'], Table::ASSETS, ['id'], 'CASCADE', null);

            $this->createIndex('craft_coconut_sources_urlHash_idx',
                Coconut::TABLE_SOURCES, 'urlHash', false);
        }

        // create outputs table
        if (!$hasOutputsTable)
        {
            $this->createTable(Coconut::TABLE_OUTPUTS, [
                'id' => $this->primaryKey(),
                'sourceId' => $this->integer()->notNull(),
                'volumeId' => $this->integer()->null(),
                'format' => $this->string()->notNull(),
                'url' => $this->string()->notNull(),
                'coconutJobId' => $this->integer()->null(),
                'metadata' => $this->longText()->null(),
                'inProgress' => $this->boolean()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_outputs_sourceId_fk',
                Coconut::TABLE_OUTPUTS, ['sourceId'], Coconut::TABLE_SOURCES, ['id'], 'CASCADE', null);

            $this->addForeignKey('craft_coconut_outputs_volumeId_fk',
                Coconut::TABLE_OUTPUTS, ['volumeId'], Table::VOLUMES, ['id'], 'CASCADE', null);

            $this->createIndex('craft_outputs_format_idx',
                Coconut::TABLE_OUTPUTS, 'format', false);
            $this->createIndex('craft_outputs_coconutJobId_idx',
                Coconut::TABLE_OUTPUTS, 'coconutJobId', false);
        }

        // refresh database schema if any of the tables were created
        if (!$hasSourcesTable || !$hasOutputsTable) {
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
        $this->dropTableIfExists(Coconut::TABLE_SOURCES);

        Craft::$app->db->schema->refresh();

        return true;
    }

}