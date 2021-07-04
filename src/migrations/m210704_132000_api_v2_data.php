<?php

namespace yoannisj\coconut\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use craft\db\Query;
use craft\helpers\ArrayHelper;
use craft\helpers\Db as DbHelper;
use craft\helpers\MigrationHelper;

use yoannisj\coconut\Coconut;

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
        $hasSourcesTable = $this->db->tableExists(Coconut::TABLE_SOURCES);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if (!$hasSourcesTable)
        {
            $this->createTable(Coconut::TABLE_SOURCES, [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->unique()->null(),
                'url' => $this->text()->unique()->null(),
                'urlHash' => $this->string(64)->unique()->null(),
                'metadata' => $this->longText()->null(),
                'uid' => $this->uid(),
            ]);

            $this->addForeignKey('craft_coconut_sources_assetId_fk',
                Coconut::TABLE_SOURCES, ['assetId'], Table::ASSETS, ['id'], 'CASCADE', null);

            $this->createIndex('craft_coconut_sources_urlHash_idx',
                Coconut::TABLE_SOURCES, 'urlHash', false);
        }

        if ($hasOutputsTable)
        {
            // add new columns to outputs table
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'sourceId', $this->integer()->notNull());

            $this->addForeignKey('craft_coconut_outputs_sourceId_fk',
                Coconut::TABLE_OUTPUTS, ['sourceId'], Coconut::TABLE_SOURCES, ['id'], 'CASCADE', null);

            // move data from outputs table to new sources table
            $this->migrateSourcesDataUp();

            // drop legacy columns from outputs table
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'sourceAssetId');
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'source');

            MigrationHelper::dropForeignKeyIfExists(Coconut::TABLE_OUTPUTS, 'sourceAssetId', false, $this);
            MigrationHelper::dropIndexIfExists(Coconut::TABLE_OUTPUTS, 'source', false, $this);

            return true;
        }

        return false;
    }

    /**
     * @inheritdoc
     */

    public function safeDown()
    {
        $hasSourcesTable = $this->db->tableExists(Coconut::TABLE_SOURCES);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if ($hasOutputsTable)
        {
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'sourceAssetId', $this->integer()->null());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'source', $this->text()->null());

            $this->createIndex('craft_coconut_outputs_sourceAssetId_idx',
                Coconut::TABLE_OUTPUTS, 'sourceAssetId', Table::ASSETS, 'id', 'CASCADE', null);

            if ($hasSourcesTable) {
                $this->migrateSourcesDataDown();
            }
        }

        MigrationHelper::dropTable(Coconut::TABLE_SOURCES);

        Craft::$app->db->schema->refresh();

        return true;
    }


    // =Protected Methods
    // =========================================================================

    /**
     * 
     */

    protected function migrateSourcesDataUp()
    {
        // move source data from outputs table to new source table
        $outputs = (new Query)
            ->select('*')
            ->from(Coconut::TABLE_OUTPUTS)
            ->all();

        // populate sources table with data from outputs
        $sources = [];
        foreach ($outputs as $output)
        {
            $sourceAssetId = $output['soureAssetId'] ?? null;
            $source = $output['source'] ?? null;

            // use a  key to avoid insterting a source multiple times
            // if it has multiple outputs
            $key = $sourceAssetId ?? $source;

            if (!array_key_exists($key, $sources))
            {
                $sourceHash = empty($source) ? null : md5($source);
                $sources[$key] = [
                    'assetId' => $sourceAssetId,
                    'url' => $source,
                    'urlHash' => $sourceHash,
                ];
            }
        }

        // insert all sources found in outputs into new sources table
        $this->batchInsert(Coconut::TABLE_SOURCES, array_values($sources));

        // update foreign key `sourceId` in outputs table accordingly
        $sources = (new Query())
            ->select('*')
            ->from(Coconut::TABLE_SOURCES)
            ->all();

        foreach ($sources as $source)
        {
            $assetId = $source['assetId'] ?? null;
            $sourceUrl = $source['url'] ?? null;

            if (!empty($assetId))
            {
                $assetOutput = ArrayHelper::firstWhere($outputs, 'assetId', $assetId);
                if ($assetOutput)
                {
                    $this->update(Coconut::TABLE_OUTPUTS, [
                        'sourceId' => $source['id'],
                    ], [
                        'id' => $assetOutput['id'],
                    ]);

                    continue;
                }
            }

            $sourceUrl = $source['url'] ?? null;
            if (!empty($sourceUrl))
            {
                $urlOutput = ArrayHelper::firstWhere($outputs, 'source', $sourceUrl);
                if ($urlOutput)
                {
                    $this->update(Coconut::TABLE_OUTPUTS, [
                        'sourceId' => $source['id'],
                    ], [
                        'id' => $urlOutput['id'],
                    ]);

                    continue;
                }
            }
        }
    }

    /**
     * 
     */

    protected function migrateSourcesDataDown()
    {
        $sources = (new Query)
            ->select('*')
            ->from(Coconut::TABLE_SOURCES)
            ->all();

        $outputs = (new Query)
            ->select('*')
            ->from(Coconut::TABLE_OUTPUTS)
            ->all();

        foreach ($sources as $source)
        {
            $sourceOutputs = ArrayHelper::where($outputs, 'sourceId', $source['id']);
            foreach ($sourceOutputs as $output)
            {
                $assetId = $source['assetId'] ?? null;
                $sourceUrl = $source['url'] ?? null;

                $this->update(Coconut::TABLE_OUTPUTS, [
                    'sourceAssetId' => $assetId,
                    'source' => $sourceUrl,
                ], [
                    'id' => $output['id']
                ]);
            }
        }
    }

}
