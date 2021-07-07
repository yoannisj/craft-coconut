<?php

namespace yoannisj\coconut\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use craft\db\Query;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;
use craft\helpers\Db as DbHelper;
use craft\helpers\MigrationHelper;
use craft\helpers\Assets as AssetsHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 * m200914_132537_add_output_metadata_column migration.
 */

class m210704_132000_api_v2_data extends Migration
{
    /**
     * @inheritdoc
     */

    public function safeUp()
    {
        $hasJobsTable = $this->db->tableExists(Coconut::TABLE_JOBS);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if (!$hasJobsTable)
        {
            $this->createTable(Coconut::TABLE_JOBS, [
                'id' => $this->primaryKey(),
                'coconutId' => $this->string(32)->notNull(),
                'status' => $this->string()->null(),
                'progress' => $this->string()->null(),
                'createdAt' => $this->dateTime()->notNull(),
                'completedAt' => $this->dateTime()->null(),
                'inputAssetId' => $this->integer()->notNull(),
                'inputUrl' => $this->text()->null(),
                'inputUrlHash' => $this->string(64)->null(),
                'inputStatus' => $this->string()->null(),
                'inputMetadata' => $this->longText()->null(),
                'inputExpires' => $this->dateTime()->null(),
                'storageHandle' => $this->string()->null(),
                'storageSettings' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_jobs_inputAssetId_fk',
                Coconut::TABLE_JOBS, ['inputAssetId'], Table::ASSETS, ['id'], 'CASCADE', null);

            $this->createIndex('craft_coconut_jobs_coconutId_idx',
                Coconut::TABLE_JOBS, ['coconutId'], false);

            $this->createIndex('craft_coconut_jobs_inputUrlHash_idx',
                Coconut::TABLE_JOBS, 'inputUrlHash', false);

            $this->createIndex('craft_coconut_jobs_storageHandle_idx',
                Coconut::TABLE_JOBS, 'storageHandle', false);
        }

        if ($hasOutputsTable)
        {
            // add new columns to outputs table
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'jobId', $this->integer()->notNull());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'key', $this->string()->null());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'type', $this->string()->null());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'status', $this->string()->null());

            $this->addForeignKey('craft_coconut_outputs_jobId_fk',
                Coconut::TABLE_OUTPUTS, ['jobId'], Coconut::TABLE_JOBS, ['id'], null, null);

            // move data from outputs table to new sources table
            $this->migrateDataUp();

            // drop legacy columns from outputs table
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'sourceAssetId');
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'source');
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'volumeId');
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'inProgress');

            MigrationHelper::dropForeignKeyIfExists(Coconut::TABLE_OUTPUTS, 'sourceAssetId', false, $this);
            MigrationHelper::dropIndexIfExists(Coconut::TABLE_OUTPUTS, 'source', false, $this);
            MigrationHelper::dropForeignKeyIfExists(Coconut::TABLE_OUTPUTS, 'volumeId', false, $this);

            return true;
        }

        return false;
    }

    /**
     * @inheritdoc
     */

    public function safeDown()
    {
        $hasJobsTable = $this->db->tableExists(Coconut::TABLE_JOBS);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if ($hasOutputsTable)
        {
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'sourceAssetId', $this->integer()->null());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'source', $this->text()->null());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'volumeId', $this->integer()->null());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'inProgress', $this->boolean()->notNull());

            $this->addForeignKey('craft_coconut_outputs_volumeId_fk',
                Coconut::TABLE_OUTPUTS, 'volumeId', Table::VOLUMES, 'id', 'CASCADE', null);

            $this->createIndex('craft_coconut_outputs_sourceAssetId_idx',
                Coconut::TABLE_OUTPUTS, 'sourceAssetId', Table::ASSETS, 'id', 'CASCADE', null);

            $this->migrateDataDown();

            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'key');
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'type');
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'status');
        }

        MigrationHelper::dropTable(Coconut::TABLE_JOBS);

        Craft::$app->db->schema->refresh();

        return true;
    }

    // =Protected Methods
    // =========================================================================

    /**
     * 
     */

    protected function migrateDataUp()
    {
        // move source data from outputs table to new source table
        $outputs = (new Query)
            ->select('*')
            ->from(Coconut::TABLE_OUTPUTS)
            ->all();

        $craftAssets = Craft::$app->getAssets();
        $craftVolumes = Craft::$app->getVolumes();

        // populate sources table with data from outputs
        $jobs = [];
        foreach ($outputs as $output)
        {
            $coconutId = $output['coconutJobId'] ?? null;;
            $inProgress = $output['inProgress'] ?? null;
            $inputAssetId = $output['soureAssetId'] ?? null;
            $inputUrl = $output['source'] ?? null;
            $volumeId = $output['volumeId'] ?? null;
            $url = $output['url'] ?? null;

            // populate new columns in outputs table
            $key = preg_replace('/:+/', '_', $format);
            $urlPath = $url ? parse_url($url, PHP_URL_PATH) : null;
            $type = $urlPath ? AssetsHelper::getFileKindByExtension($urlPath) : null;
            if ($urlPath && empty($type)) $type = 'httpstream'; // craft does not recognize 'httpstream' type
            $status = $inProgress ? 'output.failed' : 'output.completed';

            $this->update(Coconut::TABLE_OUTPUTS, [
                'key' => $key,
                'type' => $type,
                'status' => $status,
            ], [
                'id' => $output['id'],
            ]);

            // populate new jobs table
            if ($coconutId && !array_key_exists($coconutId, $jobs))
            {
                $inputAsset = null;
                $storageVolume = null;

                if ($inputAssetId && empty($inputUrl))
                {
                    $inputAsset = $craftAssets->getAssetById($inputAssetId);
                    $inputUrl = $inputAsset ? $inputAsset->id : null;
                }

                if ($volumeId) $storageVolume = $craftVolumes->getVolumeById($volumeId);
                if (!$storagVolume && $inputAsset) $storageVolume = $inputAsset->getVolume();

                $inputHash = empty($inputUrl) ? null : md5($inputUrl);
                $storageHandle = $storageVolume ? $storageVolume->handle : null;
                $storage = ConfigHelper::parseStorage($storageHandle);
                $storageSettings = $storage ? $storage->toArray() : null;
                $status =  $inProgress ? 'job.failed' : 'job.completed';

                // use a coconutId key to avoid insteting same job multiple times
                $jobs[$coconutId] = [
                    'coconutId' => $coconutId,
                    'inputAsssetId' => $inputAssetId,
                    'inputUrl' => $inputUrl,
                    'inputUrlHash' => $inputHash,
                    'inputStatus' => 'input.transferred', // let's assume
                    'storageHandle' => $storageHandle,
                    'storageSettings' => JsonHelper::encode($storageSettings),
                    'storageVolumeId' => $volumeId,
                    'status' => $status,
                ];
            }
        }

        // insert all jobs found in outputs into new jobs table
        $this->batchInsert(Coconut::TABLE_JOBS, array_values($jobs));

        // update foreign key `jobId` in outputs table accordingly
        $jobs = (new Query())
            ->select('*')
            ->from(Coconut::TABLE_JOBS)
            ->all();

        foreach ($jobs as $job)
        {
            $coconutId = $job['coconutId'] ?? null;
            if (!empty($coconutId))
            {
                $jobOutputs = ArrayHelper::where($outputs, 'coconutJobId', $coconutId);
                foreach ($outputs as $output)
                {
                    $this->update(Coconut::TABLE_OUTPUTS, [
                        'jobId' => $job['id'],
                    ], [
                        'id' => $output['id'],
                    ]);

                    continue;
                }
            }
        }
    }

    /**
     * 
     */

    protected function migrateDataDown()
    {
        $outputs = (new Query)
            ->select('*')
            ->from(Coconut::TABLE_OUTPUTS)
            ->all();

        foreach ($outputs as $output)
        {
            $inProgress = !in_array($output['status'], [
                'video.skipped', 'video.failed', 'video.encoded',
                'image.skipped', 'image.failed', 'image.created',
                'httpstream.skipped', 'httpstream.failed', 'httpstream.packaged',
            ]);

            $this->update(Coconut::TABLE_OUTPUTS, [
                'inProgress' => $inProgress,
            ], [
                'id' > $output['id']
            ]);
        }

        if ($this->db->tableExists(Coconut::TABLE_JOBS))
        {
            $jobs = (new Query)
                ->select('*')
                ->from(Coconut::TABLE_JOBS)
                ->all();

            foreach ($jobs as $job)
            {
                $jobOutputs = ArrayHelper::where($outputs, 'jobId', $job['id']);
                foreach ($jobOutputs as $output)
                {
                    $inputAssetId = $job['inputAssetId'] ?? null;
                    $inputUrl = $job['inputUrl'] ?? null;

                    $this->update(Coconut::TABLE_OUTPUTS, [
                        'sourceAssetId' => $inputAssetId,
                        'source' => $inputUrl,
                    ], [
                        'id' => $output['id']
                    ]);
                }
            }
        }
    }

}
