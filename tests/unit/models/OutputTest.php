<?php

namespace yoannisj\coconuttests\unit\models;

use Codeception\Specify;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use Craft;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\models\Output;
use yoannisj\coconut\models\Input;
use yoannisj\coconut\models\Job;
use yoannisj\coconut\helpers\JobHelper;

/**
 *
 */

class OutputTest extends UnitTest
{
    use Specify;

    // =Properties
    // ========================================================================

    /**
     * @var UnitTester
     */

    protected $tester;

    // =Public Methods
    // ========================================================================

    // =Setup/Cleanup
    // ------------------------------------------------------------------------

    /**
     * Method ran before each test to setup test context
     */

    public function _before()
    {
    }

    /**
     * Method ran after each test to clean up side effects
     */

    public function _after()
    {
    }

    // =Tests
    // ------------------------------------------------------------------------

    /**
     * =format
     * @test
     */

    public function format()
    {
        $this->specify("Is parsed as a format string or array of format specs",
            function( $format )
        {
            $parsedFormat = JobHelper::parseFormat($format);

            $output = new Output();
            $output->format = $format;

            $this->assertEquals( $parsedFormat, $output->format );
        }, [
            'examples' => [
                [
                    [ 'container' => 'mp4',
                    'video_codec' => 'hevc', 'resolution' => '1080p',
                    'audio_bitrate' => '320k',
                    'quality' => '3', 'level' =>  '41' ],
                ],
                [
                    [ 'container' => 'webm',
                    'video_codec' => 'vp9', 'resolution' => '480p',
                    'audio_bitrate' => '128k', 'sample_rate' => '22050hz',
                    'quality' => '2' ],
                ],
                [
                    [ 'container' => 'avi',
                    'resolution' => '480p',
                    'audio_disabled' => true ],
                ],
                [
                    [ 'container' => 'mp4', 'video_disabled' => true,
                    'audio_codec' => 'mp3', 'audio_bitrate' => '320k',
                    'vprofile' => 'high10' ],
                ],
                [
                    [ 'container' => 'jpeg', 'resolution' => '720p' ],
                ]
            ]
        ]);

        $this->specify("Can be set to a JSON string",
            function( $format )
        {
            $output = new Output();
            $output->format = json_encode($format);

            $this->assertEquals( $format, $output->format );

        }, [
            'examples' => [
                [
                    [ 'container' => 'mkv',
                    'video_codec' => 'hevc', 'resolution' => '1280x720', 'video_bitrate' => '4000k',
                    'quality' => '4' ],
                ],
                [
                    [ 'container' => 'ogg',
                    'audio_bitrate' => '256k', 'sample_rate' => '4800hz' ],
                ],
                [
                    [ 'container' => 'png', 'resolution' => '0x500' ],
                ]
            ]
        ]);

        $this->specify("Defaults to parsed key, if key is a valid format",
            function( $key, $parsed )
        {
            $output = new Output();
            $output->key = $key;

            $this->assertEquals( $parsed, $output->format );
        }, [
            'examples' => [
                [
                    'mp4',
                    [ 'container' => 'mp4']
                ],
                [
                    'webm:1080p',
                    [ 'container' => 'webm', 'resolution' => '1920x1080', 'video_bitrate' => '4000k' ]
                ],
                [
                    'mov::256k',
                    [ 'container' => 'mov', 'audio_bitrate' => '256k' ],
                ],
                [
                    'mkv:::quality=3,vprofile=main',
                    [ 'container' => 'mkv', 'quality' => '3', 'vprofile' => 'main' ],
                ],
                [
                    'jpeg:480p',
                    [ 'container' => 'jpeg', 'resolution' => '0x480' ]
                ],
            ],
        ]);
    }

    /**
     * =formatString
     * @test
     */

    public function formatString()
    {
        $this->specify("Equals the encoded `format` string",
            function( $format )
        {
            $output = new Output();
            $output->format = $format;
            $encoded = JobHelper::encodeFormat($format);

            $this->assertEquals( $encoded, $output->formatString );
        }, [
            'examples' => [
                [
                    [ 'container' => 'ogg', 'audio_codec' => 'vorbis', 'audio_channels' => 'mono' ],
                ],
                [
                    [ 'container' => 'webm', 'video_codec' => 'vp9', 'resolution' => '0x0',
                        'audio_disabled' => true,
                        'vprofile' => 'high422', 'level' => '13' ],
                ]
            ],
        ]);

        $this->specify("Is the empty string if output format is not set",
            function()
        {
            $output = new Output();
            $this->assertSame( '', $output->formatString );
        });
    }

    /**
     * =key
     * @test
     */

    public function key()
    {
        $this->specify("Defaults to output's `formatString` value",
            function()
        {
            $output = new Output();
            $output->format = [ 'container' => 'mp4',
                'video_bitrate' => '6000k', 'resolution' => '720p',
                'audio_codec' => 'flac', 'sample_rate' => '48000hz',
                'quality' => '3', 'level' => '50',
            ];

            $this->assertEquals( $output->formatString, $output->key );
        });

        $this->specify("Can be set independently from the output's `format`",
            function()
        {
            $output = new Output();
            $output->format = [ 'container' => 'mp4' ];
            $output->key = 'video';

            $this->assertEquals( 'video', $output->key );
        });

        $this->specify("Is suffixed by output's `formatIndex` value",
            function()
        {
            $output = new Output();
            $output->formatIndex = 1;

            $this->assertEquals( '1', $output->key );

            $output->key = 'jpg:720p';

            $this->assertEquals( 'jpg:720p:1', $output->key );

            $output->key = 'thumbnail';

            $this->assertEquals( 'thumbnail:1', $output->key );
        });
    }

    /**
     * =path
     * @test
     */

    public function path()
    {
        $this->specify("Automatically adds missing '_' prefix to turn output file paths private",
            function()
        {
            $output = new Output();
            $output->path = 'coconut/outputs';

            $this->assertSame( "_coconut/outputs", $output->path );
        });

        $this->specify("Does not add the '_' prefix if it is already present",
            function()
        {
            $output = new Output();
            $output->path = '_coconut/outputs';

            $this->assertSame( "_coconut/outputs", $output->path );
        });

        $this->specify("Renders the `{ext}` placeholder to the file extension corresponding to the output's `format`",
            function()
        {
            $output = new Output();
            $output->path = '_coconut/outputs/video.{ext}';
            $output->format = [ 'container' => 'divx' ];

            $extension = JobHelper::containerExtension('divx');

            $this->assertSame( '_coconut/outputs/video.'.$extension, $output->path );
        });

        $this->specify("Renders the {path} placeholder to the job input file's path",
            function()
        {
            $inputUrl = 'https://assets.example.com/videos/input-video.mp4';
            $input = new Input([ 'url' => $inputUrl ]);

            $output = new Output();
            $output->path = '_coconut/outputs/{path}.webm';
            $output->job = new Job([ 'input' => $input ]);

            $path = 'videos/input-video';

            $this->assertSame( '_coconut/outputs/'.$path.'.webm', $output->path );
        });

        $this->specify("Renders the {filename} placeholder to the job input file's basename",
            function()
        {
            $inputUrl = 'https://assets.example.com/videos/input-video.mp4';
            $input = new Input([ 'url' => $inputUrl ]);

            $output = new Output();
            $output->path = '_coconut/outputs/{filename}.webm';
            $output->job = new Job([ 'input' => $input ]);

            $filename = 'input-video';

            $this->assertSame( '_coconut/outputs/'.$filename.'.webm', $output->path );
        });

        $this->specify("Renders the {hash} placeholder to a hash of the job input file's URL",
            function()
        {
            $inputUrl = 'https://assets.example.com/videos/input-video.mp4';
            $input = new Input([ 'url' => $inputUrl ]);

            $output = new Output();
            $output->path = '_coconut/outputs/{hash}.webm';
            $output->job = new Job([ 'input' => $input ]);

            $hash = $input->getUrlHash();

            $this->assertSame( '_coconut/outputs/'.$hash.'.webm', $output->path );
        });

        $this->specify("Adds output's `formatIndex` suffix when set to a template string",
            function()
        {
            $output = new Output();
            $output->path = '_coconut/outputs/{key}.jpg';
            $output->key = 'jpeg:1080p';
            $output->formatIndex = 1;

            $this->assertSame( '_coconut/outputs/jpeg-1080p-1.jpg', $output->path);
        });

        $this->specify("Does not add the `formatIndex` suffix when set to a plain path",
            function()
        {
            $output = new Output();
            $output->path = '_coconut/outputs/thumbnail.jpg';
            $output->formatIndex = 1;

            $this->assertSame( '_coconut/outputs/thumbnail.jpg', $output->path);
        });

        $this->specify("Adds missing sequential suffix if output produces multiple files",
            function()
        {
            $output = new Output();
            $output->path = '_coconut/output/thumbnail.jpg';
            $output->format = 'jpg:x800';
            $output->number = 3;

            $this->assertSame( '_coconut/output/thumbnail-%.2d.jpg', $output->path);
        });

        // @todo: test other valid sequential placeholder's
        $this->specify("Does not add a sequential suffix if one is already present",
            function()
        {
            $output = new Output();
            $output->path = '_coconut/output/thumbnail-%.2d.png';
            $output->format = 'png:x800';
            $output->number = 3;

            $this->assertSame( '_coconut/output/thumbnail-%.2d.png', $output->path);
        });
    }

    /**
     * =container
     * @test
     */

    public function container()
    {
        $this->specify("Is defined by output's `format`",
            function ( $format, $container )
        {
            $output = new Output();
            $output->format = $format;

            $this->assertEquals( $container, $output->container );
        }, [
            'examples' => [
                [
                    [ 'container' => 'webm', 'audio_disabled' => true ],
                    'webm',
                ],
                [
                    'mp4::96k:vprofile=baseline',
                    'mp4',
                ],
                [
                    'mp3:320k_pcm_u8',
                    'mp3',
                ]
            ],
        ]);

        $this->specify("Is defined by output's `path` if its `format` is not defined",
            function()
        {
            $output = new Output();
            $output->format = null;
            $output->path = 'path/to/output-file.png';

            $this->assertSame( 'png', $output->container );
        });

        $this->specify("Is `null` if none of output's `format` and `path` are defined",
            function()
        {
            $output = new Output();
            $output->format = null;
            $output->path = null;

            $this->assertSame( null, $output->container );
        });
    }

    /**
     * =type
     * @test
     */

    public function type()
    {
        $this->specify("Is defined by the output's `container`",
            function( $container, $type )
        {
            $output = new Output();
            $output->format = [ 'container' => $container ];

            $this->assertSame( $type, $output->type );
        }, [
            'examples' => [
                [ 'mpegts', 'video' ],
                [ 'flash', 'video' ],
                [ 'ogg', 'audio' ],
                [ 'gif', 'image' ],
            ]
        ]);

        $this->specify("Is `null` if container is not a known output container",
            function()
        {
            $output = new Output();
            $output->format = [ 'container' => 'psd' ];

            $this->assertSame( null, $output->type );
        });
    }
}
