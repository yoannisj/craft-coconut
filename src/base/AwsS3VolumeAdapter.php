<?php

/**
 * Coconut plugin for Craft
 *
 * @author Yoannis Jamar
 * @copyright Copyright (c) 2020 Yoannis Jamar
 * @link https://github.com/yoannisj/
 * @package craft-coconut
 */

namespace yoannisj\coconut\base;

use Craft;
use craft\base\VolumeInterface;
use craft\helpers\UrlHelper;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\base\VolumeAdapterInterface;

/**
 * Adapter for AWS S3 Volumes, used to transfer coconut output files
 */
class AwsS3VolumeAdapter implements VolumeAdapterInterface
{
    /**
     * @inheritdoc
     */
    public static function outputUploadUrl(
        VolumeInterface $volume,
        string $outputPath
    ): string
    {
        $secretKey = Craft::parseEnv($volume->secret);
        $accessKey = Craft::parseEnv($volume->keyId);
        $bucket = Craft::parseEnv($volume->bucket);

        // include volume's root folder in output path
        $subfolder = Craft::parseEnv($volume->subfolder);

        if (!empty($subfolder)) {
            $outputPath = rtrim($subfolder, '/').'/'.$outputPath;
        }

        $baseUrl = 's3://'.$accessKey.':'.$secretKey.'@'.$bucket;
        $uploadUrl = $baseUrl.'/'.trim($outputPath, '/');

        if ($volume->makeUploadsPublic == false) {
            $uploadUrl .= '?x-amz-acl=private';
        }

        // add host param to a) force Coconut to return urls with the same
        // http(s) scheme and b) support more AWS S3 -compliant storage
        // providers (e.g. digitalocean, etc.)
        // @todo: If the volume uses "http://<bucket-name>.s3.amazonaws.com/" as base url,
        //  passing the ?host argument results in empty jobInfo->outputUrls (the coconut dashboard
        //  shows there was en error when transcoding outputs, but doesn't tell us which one)
        // $volumeHost = UrlHelper::hostInfo($volume->getRootUrl());
        // $hostArg = strpos($uploadUrl, '?') === false ? '?host=' : '&host=';
        // $uploadUrl .= $hostArg . $volumeHost;

        return $uploadUrl;
    }

    /**
     * @inheritdoc
     */
    public static function outputPublicUrl(
        VolumeInterface $volume,
        string $outputPath
    ): string
    {
        $url = $volume->getRootUrl().$outputPath;
        return rtrim($url, '/');
    }
}
