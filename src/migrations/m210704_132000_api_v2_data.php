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
        $hasInputsTable = $this->db->tableExists(Coconut::TABLE_INPUTS);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if (!$hasInputsTable)
        {
            $this->createTable(Coconut::TABLE_INPUTS, [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->unique()->null(),
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

        if ($hasOutputsTable)
        {
            // add new columns to outputs table
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'inputId', $this->integer()->notNull());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'status', $this->string()->null());

            $this->addForeignKey('craft_coconut_outputs_inputId_fk',
                Coconut::TABLE_OUTPUTS, ['inputId'], Coconut::TABLE_INPUTS, ['id'], 'CASCADE', null);

            // move data from outputs table to new sources table
            $this->migrateDataUp();

            // drop legacy columns from outputs table
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'sourceAssetId');
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'source');
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'inProgress');

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
        $hasInputsTable = $this->db->tableExists(Coconut::TABLE_INPUTS);
        $hasOutputsTable = $this->db->tableExists(Coconut::TABLE_OUTPUTS);

        if ($hasOutputsTable)
        {
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'sourceAssetId', $this->integer()->null());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'source', $this->text()->null());
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'inProgress', $this->boolean()->notNull());

            $this->createIndex('craft_coconut_outputs_sourceAssetId_idx',
                Coconut::TABLE_OUTPUTS, 'sourceAssetId', Table::ASSETS, 'id', 'CASCADE', null);

            $this->migrateDataDown();

            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'status');
        }

        MigrationHelper::dropTable(Coconut::TABLE_INPUTS);

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

        // populate sources table with data from outputs
        $inputs = [];
        foreach ($outputs as $output)
        {
            $this->update(Coconut::TABLE_OUTPUTS, [
                'status' => ($output['inProgress'] ? null : true),
            ], [
                'id' => $output['id'],
            ]);

            $inputAssetId = $output['soureAssetId'] ?? null;
            $inputUrl = $output['source'] ?? null;

            // use a  key to avoid insterting a source multiple times
            // if it has multiple outputs
            $key = $inputAssetId ?? $input;

            if (!array_key_exists($key, $inputs))
            {
                $inputHash = empty($input) ? null : md5($input);
                $inputs[$key] = [
                    'assetId' => $inputAssetId,
                    'url' => $inputUrl,
                    'urlHash' => $inputHash,
                ];
            }
        }

        // insert all sources found in outputs into new sources table
        $this->batchInsert(Coconut::TABLE_INPUTS, array_values($inputs));

        // update foreign key `inputId` in outputs table accordingly
        $inputs = (new Query())
            ->select('*')
            ->from(Coconut::TABLE_INPUTS)
            ->all();

        foreach ($inputs as $input)
        {
            $assetId = $input['assetId'] ?? null;
            if (!empty($assetId))
            {
                $assetOutput = ArrayHelper::firstWhere($outputs, 'assetId', $assetId);
                if ($assetOutput)
                {
                    $this->update(Coconut::TABLE_OUTPUTS, [
                        'inputId' => $input['id'],
                    ], [
                        'id' => $assetOutput['id'],
                    ]);

                    continue;
                }
            }

            $inputUrl = $input['url'] ?? null;
            if (!empty($inputUrl))
            {
                $urlOutput = ArrayHelper::firstWhere($outputs, 'source', $inputUrl);
                if ($urlOutput)
                {
                    $this->update(Coconut::TABLE_OUTPUTS, [
                        'inputId' => $input['id'],
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

    protected function migrateDataDown()
    {
        $outputs = (new Query)
            ->select('*')
            ->from(Coconut::TABLE_OUTPUTS)
            ->all();

        foreach ($outputs as $output)
        {
            $this->update(Coconut::TABLE_OUTPUTS, [
                'inProgress' => ($output['status'] != 'completed'),
            ], [
                'id' > $output['id']
            ]);
        }

        if ($this->db->tableExists(Coconut::TABLE_INPUTS))
        {

            $inputs = (new Query)
                ->select('*')
                ->from(Coconut::TABLE_INPUTS)
                ->all();

            foreach ($inputs as $input)
            {
                $inputOutputs = ArrayHelper::where($outputs, 'inputId', $input['id']);
                foreach ($inputOutputs as $output)
                {
                    $assetId = $input['assetId'] ?? null;
                    $inputUrl = $input['url'] ?? null;

                    $this->update(Coconut::TABLE_OUTPUTS, [
                        'sourceAssetId' => $assetId,
                        'source' => $inputUrl,
                    ], [
                        'id' => $output['id']
                    ]);
                }
            }
        }
    }

}
