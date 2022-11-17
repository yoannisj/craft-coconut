<?php

namespace yoannisj\coconut\migrations;

use yii\db\Query;

use Craft;
use craft\db\Migration;
use craft\errors\VolumeException;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\migrations\Install;

/**
 * m200914_132537_add_output_metadata_column migration.
 */
class m221026_144600_api_v2_upgrade extends Migration
{
    /**
     * @inheritdoc
     */

    public function safeUp()
    {
        // Collect legacy outputs data needed to delete their files
        $outputsData = [];
        try {
            $outputsData = (new Query())
                ->select(['id', 'volumeId', 'url'])
                ->from(Coconut::TABLE_OUTPUTS)
                ->all();
        } catch (\yii\db\Exception $e) {
            // probably the new schema is already in use
        }

        // Drop tables and data that where used with API v1
        echo "    Deleting legacy outputs data".PHP_EOL;
        $this->dropTableIfExists(Coconut::TABLE_OUTPUTS);

        // Re-apply install migration
        $installMigration = new Install();
        Craft::$app->getContentMigrator()->migrateUp($installMigration);

        if ($outputsData) {
            $this->deleteLegacyOutputFiles($outputsData);
        }

        return true;
    }

    /**
     * @inheritdoc
     */

    public function safeDown()
    {
        echo "m221026_144600_api_v2_upgrade cannot be reverted.\n";
        return false;
    }

    // =Protected Methods
    // =========================================================================

    /**
     * @return void
     */
    protected function deleteLegacyOutputFiles( array $outputsData )
    {
        if (!$outputsData) return;

        $craftVolumes = Craft::$app->getVolumes();

        foreach ($outputsData as $outputData)
        {
            $url = $outputData['url'] ?? null;
            if (!$url) continue;

            $volumeId = $outputData['volumeId'] ?? null;
            if ($volumeId === null) continue;

            $volume = $craftVolumes->getVolumeById($volumeId);
            if (!$volume) continue;

            if (($pathStart = strpos('_coconut/', $url)) !== false) {
                $path = substr($url, $pathStart);
            } else {
                $volumeUrl = $volume->getRootUrl();
                $path = trim(str_replace($volumeUrl, '', $url), '/');
            }

            echo "    Deleting output file $path... ";

            try {
                $volume->deleteFile($path);
                echo "done.".PHP_EOL;
            } catch (VolumeException $e) {
                echo "error: ".PHP_EOL;
                echo "      ".$e->getMessage().PHP_EOL;
            }
        }
    }
}
