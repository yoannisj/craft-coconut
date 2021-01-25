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
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\Json as JsonHelper;

use yoannisj\coconut\Coconut;

/**
 * 
 */

class ClearVideoOutputs extends ElementAction
{
    /**
     * @inheritdoc
     */

    public function getTriggerLabel(): string
    {
        return Craft::t('coconut', 'Clear Coconut ouputs');
    }

    /**
     * @inheritdoc
     */

    public function getTriggerHtml()
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
    }

    /**
     * @inheritdoc
     */

    public function performAction( ElementQueryInterface $query ): bool
    {
        // fetch video elements only
        $videos = $query
            ->kind('video')
            ->all();

        $anySuccess = false;

        foreach ($videos as $video)
        {
            if (Coconut::$plugin->getOutputs()->clearSourceOutputs($video) !== false) {
                $anySuccess = true;
            }
        }

        return $anySuccess;
    }

    /**
     * 
     */

    protected function getOutputAssetIds()
    {
        $results = (new Query())
            ->select('sourceAssetId')
            ->from(Coconut::TABLE_OUTPUTS)
            ->distinct()
            ->all();

        $assetIds = ArrayHelper::getColumn($results, 'sourceAssetId');

        return array_filter($assetIds);
    }

}
