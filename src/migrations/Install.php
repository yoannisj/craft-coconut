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

    try {

        // check current tables
        $hasJobsTable = $this->db->tableExists(Coconut::TABLE_JOBS);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if (!$hasJobsTable)
        {
            $this->createTable(Coconut::TABLE_JOBS, [
                'id' => $this->primaryKey(),
                'coconutId' => $this->string(32)->null(),
                'status' => $this->string()->null(),
                'progress' => $this->string(4)->null(),
                'inputAssetId' => $this->integer()->null(),
                'inputUrl' => $this->text()->null(),
                'inputUrlHash' => $this->string(64)->null(),
                'inputStatus' => $this->string()->null(),
                'inputMetadata' => $this->json()->null(),
                'inputExpires' => $this->dateTime()->null(),
                'inputError' => $this->string()->null(),
                'outputPathFormat' => $this->string()->null(),
                'storageHandle' => $this->string()->null(),
                'storageVolumeId' => $this->integer()->null(),
                'storageParams' => $this->json()->null(),
                'notification' => $this->json()->null(),
                'createdAt' => $this->dateTime()->null(),
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
                'status' => $this->string()->null(),
                'progress' => $this->string(4)->null(),
                'format' => $this->string(255)->notNull(),
                'path' => $this->string()->null(),
                'if' => $this->string()->null(),
                'deinterlace' => $this->boolean()->null(),
                'square' => $this->boolean()->null(),
                'blur' => $this->boolean()->null(),
                'fit' => $this->string()->null(),
                'transpose' => $this->integer()->null(),
                'hflip' => $this->boolean()->null(),
                'vflip' => $this->boolean()->null(),
                'offset' => $this->integer()->null(),
                'duration' => $this->integer()->null(),
                'number' => $this->integer()->null(),
                'interval' => $this->integer()->null(),
                'offsets' => $this->json()->null(),
                'sprite' => $this->boolean()->null(),
                'vtt' => $this->boolean()->null(),
                'scene' => $this->text()->null(),
                'watermark' => $this->json()->null(),
                'url' => $this->string()->null(),
                'urls' => $this->json()->null(),
                'type' => $this->string()->notNull(),
                'metadata' => $this->json()->null(),
                'error' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_outputs_jobId_fk',
                Coconut::TABLE_OUTPUTS, ['jobId'], Coconut::TABLE_JOBS, ['id'], null, null);

            $this->createIndex('craft_outputs_format_idx',
                Coconut::TABLE_OUTPUTS, 'format', false);
        }

        // refresh database schema if any of the tables were created
        if (!$hasJobsTable || !$hasOutputsTable) {
            Craft::$app->db->schema->refresh();
        }

    }

    catch (\Throwable $e) {
        var_dump($e->message); die();
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
