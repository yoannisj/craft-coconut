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
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Json;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 *
 */

class TranscodeVideo extends ElementAction
{
    /**
     * @inheritdoc
     */

    public function getTriggerLabel(): string
    {
        return Craft::t('coconut', 'Transcode with Coconut');
    }

    /**
     * @inheritdoc
     */

    public function getTriggerHtml()
    {
        $type = Json::encode(static::class);
        $fileExtensions = implode('|', ConfigHelper::SOURCE_FILE_EXTENSIONS);

        $js = <<<EOD
(function()
{
    var trigger = new Craft.ElementActionTrigger({
        type: {$type},
        batch: true,
        validateSelection: function(\$selectedItems)
        {
            var videoRe = /\.({$fileExtensions})$/;
            for (var i = 0; i < \$selectedItems.length; i++)
            {
                var url = \$selectedItems.eq(i).find('.element').data('url');
                url = url.split('?')[0];
                if (videoRe.test(url)) return true;
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
            try {
                Coconut::$plugin->transcodeSource($video, null, true);
                $anySuccess = true;
            } catch (\Throwable $e) {
                $anySuccess = false;
            }
        }

        return $anySuccess;
    }

}
