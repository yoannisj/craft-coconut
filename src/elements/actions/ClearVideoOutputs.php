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

namespace yoannisj\coconut\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\db\Query;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;

/**
 * Element action to clear Outputs of a video Assets
 */
class ClearVideoOutputs extends ElementAction
{
    // =Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function isDestructive(): bool
    {
        return true;
    }

    // =Properties
    // =========================================================================

    // =Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('coconut', 'Clear Coconut Ouputs');
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        $type = JsonHelper::encode(static::class);
        $assetIds = implode(',', $this->getOutputAssetIds());

        $js = <<<EOD
(function()
{
    var trigger = new Craft.ElementActionTrigger({
        type: {$type},
        batch: true,
        validateSelection: function(\$selectedItems)
        {
            var assetIds = "{$assetIds}".split(',');
            for (var i = 0; i < \$selectedItems.length; i++)
            {
                var id = \$selectedItems.eq(i).find('.element').attr('data-id');
                if (assetIds.indexOf(id) !== -1) return true;
            }

            return false;
        }
    });
})();
EOD;

        Craft::$app->getView()->registerJs($js);

        return null;
    }

    /**
     * @inheritdoc
     */
    public function performAction( ElementQueryInterface $query ): bool
    {
        // fetch video elements only
        /** @var AssetQuery $query */
        $videos = $query->kind('video')->all();

        if (empty($videos)) return true;

        $coconutOutputs = Coconut::$plugin->getOutputs();
        $anySuccess = false;

        foreach ($videos as $video)
        {
            if ($coconutOutputs->clearOutputsForInput($video) !== false) {
                $anySuccess = true;
            }
        }

        return $anySuccess;
    }

    // =Protected Methods
    // =========================================================================

    /**
     * Return Output IDS for selected Asset elements.
     *
     * @return array
     */
    protected function getOutputAssetIds(): array
    {
        $results = (new Query())
            ->select('inputAssetId')
            ->from(Coconut::TABLE_JOBS)
            ->distinct()
            ->all();

        $assetIds = ArrayHelper::getColumn($results, 'inputAssetId');

        return array_filter($assetIds);
    }

}
