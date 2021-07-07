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
        $hasJobsTable = $this->db->tableExists(Coconut::TABLE_JOBS);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if (!$hasJobsTable)
        {
            $this->createTable(Coconut::TABLE_JOBS, [
                'id' => $this->primaryKey(),
                'coconutId' => $this->string(32)->notNull(),
                'status' => $this->string()->null(),
                'progress' => $this->string()->null(),
                'inputAssetId' => $this->integer()->notNull(),
                'inputUrl' => $this->text()->null(),
                'inputUrlHash' => $this->string(64)->null(),
                'inputStatus' => $this->string()->null(),
                'inputMetadata' => $this->longText()->null(),
                'inputExpires' => $this->dateTime()->null(),
                'storageHandle' => $this->string()->notNull(),
                'storageVolumeId' => $this->text()->null(),
                'storageSettings' => $this->text()->null(),
                'createdAt' => $this->dateTime()->notNull(),
                'completedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_jobs_inputAssetId_fk',
                Coconut::TABLE_JOBS, ['inputAssetId'], Table::ASSETS, ['id'], 'CASCADE', null);

            $this->addForeignKey('craft_coconut_jobs_storageVolumeId_fk',
                Coconut::TABLE_JOBS, ['storageVolumeId'], Table::VOLUMES, ['id'], 'CASCADE', null);

            $this->createIndex('craft_coconut_jobs_coconutId_idx',
                Coconut::TABLE_JOBS, ['coconutId'], false);

            $this->createIndex('craft_coconut_jobs_inputUrlHash_idx',
                Coconut::TABLE_JOBS, 'inputUrlHash', false);

            $this->createIndex('craft_coconut_jobs_storageHandle_idx',
                Coconut::TABLE_JOBS, 'storageHandle', false);
        }

        // create outputs table
        if (!$hasOutputsTable)
        {
            $this->createTable(Coconut::TABLE_OUTPUTS, [
                'id' => $this->primaryKey(),
                'jobId' => $this->integer()->null(),
                'key' => $this->string()->notNull(),
                'type' => $this->string()->notNull(),
                'format' => $this->string()->notNull(),
                'url' => $this->string()->notNull(),
                'metadata' => $this->longText()->null(),
                'status' => $this->string()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_outputs_jobId_fk',
                Coconut::TABLE_OUTPUTS, ['jobId'], Coconut::TABLE_JOBS, ['id'], null, null);

            $this->addForeignKey('craft_coconut_outputs_volumeId_fk',
                Coconut::TABLE_OUTPUTS, ['volumeId'], Table::VOLUMES, ['id'], 'CASCADE', null);

            $this->createIndex('craft_outputs_format_idx',
                Coconut::TABLE_OUTPUTS, 'format', false);
            $this->createIndex('craft_outputs_coconutJobId_idx',
                Coconut::TABLE_OUTPUTS, 'coconutJobId', false);
        }

        // refresh database schema if any of the tables were created
        if (!$hasJobsTable || !$hasOutputsTable) {
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
        $this->dropTableIfExists(Coconut::TABLE_JOBS);

        Craft::$app->db->schema->refresh();

        return true;
    }

}