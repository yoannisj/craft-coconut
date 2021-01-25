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
        // get table names
        $volumesTable = Table::VOLUMES;
        $assetsTable = Table::ASSETS;
        $queueTable = Table::QUEUE;
        $outputsTable = Coconut::TABLE_OUTPUTS;

        // check current tables
        $hasOutputsTable = $this->db->tableExists($outputsTable);

        // create outputs table
        if (!$hasOutputsTable)
        {
            $this->createTable($outputsTable, [
                'id' => $this->primaryKey(),
                'volumeId' => $this->integer()->notNull(),
                'sourceAssetId' => $this->integer()->null(),
                'source' => $this->text()->null(),
                'format' => $this->string()->notNull(),
                'url' => $this->string()->notNull(),
                'inProgress' => $this->boolean()->notNull(),
                'coconutJobId' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $outputsTable, 'sourceAssetId', false);
            $this->createIndex(null, $outputsTable, 'source', false);
            $this->createIndex(null, $outputsTable, 'format', false);
            $this->createIndex(null, $outputsTable, 'coconutJobId', false);

            $this->addForeignKey(null, $outputsTable, ['volumeId'], $volumesTable, ['id'], 'CASCADE', null);
            $this->addForeignKey(null, $outputsTable, ['sourceAssetId'], $assetsTable, ['id'], 'CASCADE', null);
        }

        // refresh database schema if any of the tables were created
        if (!$hasOutputsTable) {
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

        Craft::$app->db->schema->refresh();

        return true;
    }

}