<?php

namespace yoannisj\coconuttests\unit;

use Codeception\Specify;
use Codeception\Test\Unit as UnitTest;
use UnitTester;

use Craft;

use yoannisj\coconut\Coconut;
use yoannisj\coconut\helpers\ConfigHelper;

/**
 *
 */

class ConfigHelperTest extends UnitTest
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
     * * =decodeFormat( $format )
     * @test
     */

    public function decodeFormat()
    {
        $this->specify("Returns an empty array if given format string is empty",
            function()
        {
            $decoded = ConfigHelper::decodeFormat('');
            $this->assertEquals( [], $decoded );
        });

        $this->specify("Recognizes base container in 1st segment of given format string",
            function( $container, $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertSame( $container, $decoded['container'] );
        }, [
            'examples' => [
                [ 'mp4', 'mp4' ],
                [ 'webm', 'webm:720p' ],
                [ 'webm', 'webm::mono' ],
                [ 'webm', 'webm:vp9::quality=2' ],
                [ 'avi', 'avi:mpeg2video' ],
                [ 'asf', 'asf:360p_800k' ],
                [ 'mpegts', 'mpegts:0x500' ],
                [ 'mov', 'mov:prores::vprofile=2' ],
                [ 'mkv', 'mkv:hevc_1080p' ],
                [ '3gp', '3gp:h263' ],
                [ 'ogv', 'ogv:30fps_720p' ],
                [ 'ogg', 'ogg:256k_mono' ],
                [ 'mp3', 'mp3:320k' ],
                [ 'jpg', 'jpg:1600x900' ],
                [ 'png', 'png:480p' ],
                [ 'gif', 'gif:0x120' ],
            ],
        ]);

        $this->specify("Preserves alias container in 1st segment of given format string",
            function( $container, $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertSame( $container, $decoded['container'] );
        }, [
            'examples' => [
                [ 'divx', 'divx' ],
                [ 'xvid', 'xvid:720px' ],
                [ 'wmv', 'wmv:600x400_800k:aac' ],
                [ 'flash', 'flash:15fps' ],
                [ 'theora', 'theora:1080p_25fps:stereo' ],
                [ 'jpeg', 'jpeg:480p' ],
            ]
        ]);

        $this->specify("Preserves unknown container in 1st segment of given format string",
            function( $container, $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertSame( $container, $decoded['container'] );
        }, [
            'examples' => [
                [ 'foo', 'foo' ],
                [ 'bar', 'bar:h264_720p:mono' ],
            ]
        ]);

        $this->specify("Includes implicit container specs in given format string",
            function( $container, $implicitSpecs )
        {
            $decoded = ConfigHelper::decodeFormat($container);

            foreach ($implicitSpecs as $spec => $value) {
                $this->assertEquals( $decoded[$spec], $value );
            }
        }, [
            'examples' => $this->provideImplicitContainerSpecs(),
        ]);

        $this->specify("Recognizes `video_codec` in given format string",
            function( $format, $video_codec )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $video_codec, $decoded['video_codec'] );
        }, [
            'examples' => [
                [ 'mp4:h263', 'h263' ],
                [ 'webm:vp9', 'vp9' ],
                [ 'avi:mpeg2video', 'mpeg2video' ],
                [ 'asf:h264', 'h264' ],
                [ 'mpegts:hevc', 'hevc' ],
                [ 'mov:prores', 'prores' ],
                [ 'flv:mpeg4', 'mpeg4' ],
                [ 'mkv:theora', 'theora' ],
                [ '3gp:h264', 'h264' ],
                [ 'ogv:h264', 'h264' ],
            ],
        ]);

        // @todo: separate test for video vs. image resolution definitions
        $this->specify("Recognizes `resolution` definitions in given format string",
            function( $format, $implicitSpecs )
        {
            $decoded = ConfigHelper::decodeFormat($format);

            foreach ($implicitSpecs as $spec => $value)
            {
                if ($spec == 'container') continue;
                $this->assertEquals( $decoded[$spec], $value );
            }
        }, [
            'examples' => $this->provideValidDefinitionFormatStringSpecs()
        ]);

        $this->specify("Ignores invalid `resolution` definitions in given format string",
            function( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( '0x0', $decoded['resolution'] );
        }, [
            'examples' => [
                [ 'mp4:500p' ],
                [ 'webm:1440p' ],
                [ 'png:640p' ],
                [ 'gif:180p' ],
            ],
        ]);

        $this->specify("Recognizes `resolution` in given format string",
            function( $format, $resolution )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $resolution, $decoded['resolution'] );
        }, [
            'examples' => [
                [ 'mp4:0x600', '0x600' ],
                [ 'webm:2400x1350', '2400x1350' ],
                [ 'divx:1600x0', '1600x0' ],
                [ 'jpeg:900x600', '900x600' ],
                [ 'png:0x480', '0x480' ],
            ],
        ]);

        $this->specify("Recognizes `video_bitrate` in given format string",
            function( $format, $video_bitrate )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $video_bitrate, $decoded['video_bitrate'] );
        }, [
            'examples' => [
                [ 'mp4:500k', '500k' ],
                [ 'webm:12000k', '12000k' ],
                [ 'mov:24000k', '24000k' ],
                [ 'ogv:2000k', '2000k' ],
            ],
        ]);

        $this->specify("Ignores invalid `video_bitrate` in given format string",
            function( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( '1000k', $decoded['video_bitrate'] );
        }, [
            'examples' => [
                [ 'mp4:200000k' ],
                [ 'webm:666666k' ],
            ],
        ]);

        $this->specify("Recognizes video `fps` in given format string",
            function( $format, $fps )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $fps, $decoded['fps'] );
        }, [
            'examples' => [
                [ 'mp4:0fps', '0fps' ],
                [ 'webm:15fps', '15fps' ],
                [ 'avi:23.98fps', '23.98fps' ],
                [ 'flv:25fps', '25fps' ],
                [ 'mov:29.97fps', '29.97fps' ],
                [ 'mkv:30fps', '30fps' ],
            ],
        ]);

        $this->specify("Ignores invalid video `fps` in given format string",
            function( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( '0fps', $decoded['fps'] );
        }, [
            'examples' => [
                [ 'mp4:12fps' ],
                [ 'webm:-30fps' ],
                [ 'mov:29.98fps' ],
                [ 'ogv:23.89fps' ],
                [ 'ogv:60fps' ],
            ],
        ]);

        $this->specify("Recognizes `audio_codec` in given format string",
            function( $format, $audio_codec )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $audio_codec, $decoded['audio_codec'] );
        }, [
            'examples' => [
                [ 'mp4::mp3', 'mp3' ],
                [ 'webm::aac', 'aac' ],
                [ 'avi::wmav2', 'wmav2' ],
                [ 'xvid::mp2', 'mp2' ],
                [ 'mpegts::mp3', 'mp3' ],
                [ 'mov::flac', 'flac' ],
                [ 'mkv::ac3', 'ac3' ],
                [ '3gp::pcm_u8', 'pcm_u8' ],
                [ 'ogv::pcm_s16le', 'pcm_s16le' ],
                [ 'ogg:pcm_alaw', 'pcm_alaw' ],
                [ 'mp3:amr_nb', 'amr_nb' ],
            ],
        ]);

        $this->specify("Recognizes `audio_bitrate` in given format string",
            function( $format, $audio_bitrate )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $audio_bitrate, $decoded['audio_bitrate'] );
        }, [
            'examples' => [
                [ 'mp4::32k', '32k' ],
                [ 'webm::64k', '64k' ],
                [ 'avi::96k', '96k' ],
                [ 'divx::128k', '128k' ],
                [ 'xvid::160k', '160k' ],
                [ 'asf::192k', '192k' ],
                [ 'wmv::224k', '224k' ],
                [ 'mpegts::256k', '256k' ],
                [ 'mov::288k', '288k' ],
                [ 'flv::320k', '320k' ],
                [ 'mkv::352k', '352k' ],
                [ '3gp::384k', '384k' ],
                [ 'ogv::416k', '416k' ],
                [ 'theora::448k', '448k' ],
                [ 'ogg:480k', '480k' ],
                [ 'mp3:512k', '512k' ],
            ],
        ]);

        $this->specify("Ignores invalid `audio_bitrate` in given format string",
            function( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( '128k', $decoded['audio_bitrate'] );
        }, [
            'examples' => [
                [ 'mp4:16k' ],
                [ 'webm:-256k' ],
                [ 'mov:544k' ],
                [ 'ogv:244k' ],
                [ 'ogv:3200k' ],
            ],
        ]);

        $this->specify("Recognizes `sample_rate` in given format string",
            function( $format, $sample_rate )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $sample_rate, $decoded['sample_rate'] );
        }, [
            'examples' => [
                [ 'mp4::8000hz', '8000hz' ],
                [ 'webm::11025hz', '11025hz' ],
                [ 'avi::16000hz', '16000hz' ],
                [ 'asf::22000hz', '22000hz' ],
                [ 'mpegts::22050hz', '22050hz' ],
                [ 'mov::24000hz', '24000hz' ],
                [ 'flv::32000hz', '32000hz' ],
                [ 'mkv::44000hz', '44000hz' ],
                [ '3gp::44100hz', '44100hz' ],
                [ 'ogv::48000hz', '48000hz' ],
                [ 'ogg:22050hz', '22050hz' ],
                [ 'mp3:48000hz', '48000hz' ],
            ],
        ]);

        $this->specify("Ignores invalid `sample_rate` in given format string",
            function( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( '44100hz', $decoded['sample_rate'] );
        }, [
            'examples' => [
                [ 'mp4::4400hz' ],
                [ 'webm::-44100hz' ],
                [ 'mov::64000hz' ],
                [ 'ogg:32100hz' ],
                [ 'mp3:3200hz' ],
            ],
        ]);

        $this->specify("Recognizes `audio_channel` in given format string",
            function( $format, $audio_channel )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $audio_channel, $decoded['audio_channel'] );
        }, [
            'examples' => [
                [ 'mp4::mono', 'mono' ],
                [ 'webm::stereo', 'stereo' ],
                [ 'ogg:mono', 'mono' ],
                [ 'mp3:stereo', 'stereo' ],
            ],
        ]);

        $this->specify("Recognizes `pix_fmt` option in given format string",
            function( $format, $pix_fmt )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $pix_fmt, $decoded['pix_fmt'] );
        }, [
            'examples' => [
                [ 'mp4:::pix_fmt=yuv420p', 'yuv420p' ],
                [ 'webm:::pix_fmt=yuv422p', 'yuv422p' ],
                [ 'mov:::pix_fmt=yuva444p10le', 'yuva444p10le' ],
                // [ 'png::pix_fmt=yuv422p', 'yuv422p' ],
                // [ 'gif::pix_fmt=yuva444p10le', 'yuva444p10le' ],
            ],
        ]);

        $this->specify("Ignores invalid `pix_fmt` option in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'pix_fmt', $decoded );
        }, [
            'examples' => [
                [ 'mp4:::pix_fmt=yuv244p' ],
                [ 'webm:::pix_fmt=yuv480p' ],
                [ 'avi:::pix_fmt=yuva444p10lx' ],
                [ 'mov:::pix_fmt=yuva444p11le' ],
                // [ 'png::pix_fmt=yuv242p' ],
                // [ 'jpg::pix_fmt=yuvb444p10le' ],
                // [ 'gif::pix_fmt=yuva442p10le' ],
            ],
        ]);

        $this->specify("Recognizes boolean `2pass` option in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( true, $decoded['2pass'] );
        }, [
            'examples' => [
                [ 'mp4:::2pass' ],
                [ 'webm:::2pass' ],
                [ 'webm:vp9::2pass' ],
                [ 'mov:hevc::2pass' ],
                [ 'mkv:::2pass' ],
                [ 'ogv:theora::2pass' ],
            ],
        ]);

        $this->specify("Ignores boolean `2pass` option value in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( true, $decoded['2pass'] );
        }, [
            'examples' => [
                [ 'mp4:::2pass=false' ],
                [ 'webm:vp9::2pass=0' ],
                [ 'mov:hevc::2pass=false' ],
                [ 'ogv:theora::2pass=x' ],
            ],
        ]);

        $this->specify("Recognizes `quality` option in given format string",
            function ( $format, $quality)
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $quality, $decoded['quality'] );
        }, [
            'examples' => [
                [ 'mp4:::quality=1', '1' ],
                [ 'webm:::quality=2', '2' ],
                [ 'webm:vp9::quality=3', '3' ],
                [ 'mov:::quality=4', '4' ],
                [ 'mov:hevc::quality=5', '5' ],
            ],
        ]);

        $this->specify("Ignores invalid `quality` option in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'quality', $decoded );
        }, [
            'examples' => [
                [ 'mp4:::quality=-1' ],
                [ 'webm:::quality=6' ],
                [ 'webm:vp9::quality=33' ],
                [ 'mov:::quality=0.5' ],
            ],
        ]);

        $this->specify("Ignores `quality` option if not supported by format codecs",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'quality', $decoded );
        }, [
            'examples' => [
                [ 'avi:::quality=1' ],
                [ 'divx:wmv2::quality=4' ],
                [ 'mkv:theora::quality=2' ],
                [ 'ogv:::quality=5' ],
                [ 'mp3::quality=3' ],
            ],
        ]);

        $this->specify("Annuls `video_bitrate` when a valid `quality` option value is found in given format string",
            function ( $format, $quality )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( null, $decoded['video_bitrate'] );
        }, [
            'examples' => [
                [ 'mp4:8000k::quality=1', '1' ],
                [ 'webm:::quality=2', '2' ],
                [ 'webm:vp9_12000k::quality=3', '3' ],
            ],
        ]);

        $this->specify("Recognizes `maxrate` option in given format string",
            function ( $format, $maxrate )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $maxrate, $decoded['maxrate'] );
        }, [
            'examples' => [
                [ 'mp4:::quality=5,maxrate=145000k', '145000k' ],
                [ 'mkv:hevc::quality=2,maxrate=12000k', '12000k' ],
                [ 'webm:vp9::quality=1,maxrate=1000k', '1000k' ],
                [ 'webm:::quality=3,maxrate=8000k', '8000k' ],
            ],
        ]);

        $this->specify("Ignores invalid `maxrate` option in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'maxrate', $decoded );
        }, [
            'examples' => [
                [ 'mp4::::quality=5,maxrate=-1000k' ],
                [ 'webm:::quality=1,maxrate=200000k' ],
                [ 'webm:::quality=3,maxrate=666666k' ],
            ],
        ]);

        $this->specify("Ignores `maxrate` option if no valid `quality` option value is found in format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'maxrate', $decoded );
        }, [
            'examples' => [
                [ 'avi:::quality=1,maxrate=1000k' ],
                [ 'divx:wmv2::quality=4,maxrate=16000k' ],
                [ 'mkv:theora::quality=2,maxrate=8000k' ],
                [ 'ogv:::quality=5,maxrate=145000k' ],
                [ 'mp3::quality=3,maxrate=500k' ],
            ],
        ]);

        $this->specify("Recognizes `vprofile` option in given format string",
            function ( $format, $vprofile)
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $vprofile, $decoded['vprofile'] );
        }, [
            'examples' => [
                [ 'mp4:::vprofile=main', 'main' ],
                [ 'mp4:hevc::vprofile=high10', 'high10' ],
                [ 'mpegts:::vprofile=high422', 'high422' ],
                [ 'mpegts:hevc::vprofile=baseline', 'baseline' ],
                [ 'mov:prores::vprofile=0', '0' ],
                [ 'mov:prores::vprofile=1', '1' ],
                [ 'mov:prores::vprofile=2', '2' ],
                [ 'mov:prores::vprofile=3', '3' ],
            ],
        ]);

        $this->specify("Ignores invalid `vprofile` option in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'vprofile', $decoded );
        }, [
            'examples' => [
                [ 'mp4:::vprofile=middle' ],
                [ 'mp4:hevc::vprofile=high11' ],
                [ 'mpegts:::vprofile=high448' ],
                [ 'mpegts:hevc:vprofile=secondary' ],
                [ 'mov:::vprofile=low10' ],
                [ 'mov:prores::vprofile=high444' ], // prores supports different values
                [ 'mkv:hevc::vprofile=foo' ],
            ],
        ]);

        $this->specify("Ignores `vprofile` option if not supported by format codecs",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'vprofile', $decoded );
        }, [
            'examples' => [
                [ 'webm:::vprofile=main' ],
                [ 'avi:wmv2::vprofile=high10' ],
                [ 'mkv:theora::vprofile=high422' ],
                [ 'mp3::vprofile=baseline' ],
            ],
        ]);

        $this->specify("Recognizes `level` format option in given format string",
            function ( $format, $level)
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $level, $decoded['level'] );
        }, [
            'examples' => [
                [ 'mp4:::level=10', '10' ],
                [ 'mp4:hevc::level=11', '11' ],
                [ 'mpegts:::level=12', '12' ],
                [ 'mpegts:hevc::level=13', '13' ],
                [ 'mov:::level=20', '20' ],
                [ 'mov:hevc::level=21', '21' ],
                [ 'mkv:::level=22', '22' ],
                [ 'mkv:hevc::level=30', '30' ],
                [ 'mp4:::level=31', '31' ],
                [ 'mp4:hevc::level=32', '32' ],
                [ 'mpegts:::level=40', '40' ],
                [ 'mpegts:hevc::level=41', '41' ],
                [ 'mov:::level=42', '42' ],
                [ 'mov:hevc::level=50', '50' ],
                [ 'mkv:::level=51', '51' ],
            ],
        ]);

        $this->specify("Ignores invalid `level` option in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'level', $decoded );
        }, [
            'examples' => [
                [ 'mp4:::level=33' ],
                [ 'mp4:hevc::level=-10' ],
                [ 'mpegts:::level=100' ],
                [ 'mpegts:hevc:level=45' ],
                [ 'mov:::level=52' ],
                [ 'mov:hevc::level=14' ],
            ],
        ]);

        $this->specify("Ignores `level` option if not supported by format codecs",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'level', $decoded );
        }, [
            'examples' => [
                [ 'webm:::level=13' ],
                [ 'avi:wmv2::level=30' ],
                [ 'mkv:theora::level=22' ],
                [ 'mp3::level=41' ],
            ],
        ]);

        $this->specify("Recognizes boolean `frag` option in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( true, $decoded['frag'] );
        }, [
            'examples' => [
                [ 'mp4:::frag' ],
                [ 'mp4:hevc::frag' ],
            ],
        ]);

        $this->specify("Ignores boolean `frag` option value in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( true, $decoded['frag'] );
        }, [
            'examples' => [
                [ 'mp4:::frag=false' ],
                [ 'mp4:hevc::frag=0' ],
            ],
        ]);

        $this->specify("Discards boolean `frag` option if not supported by format container",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertArrayNotHasKey( 'frag', $decoded );
        }, [
            'examples' => [
                [ 'webm:::frag' ],
                [ 'mov:::frag' ],
                [ 'mkv:h264::frag' ],
                [ 'mp3::frag' ],
            ],
        ]);

        $this->specify("Discards all video specs if video segment is 'x' in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);

            $this->assertSame( true, $decoded['video_disabled']);
            foreach (ConfigHelper::VIDEO_SPECS as $spec) {
                $this->assertArrayNotHasKey( $spec, $decoded );
            }
        }, [
            'examples' => [
                [ 'mp4:x' ],
                [ 'webm:x:256k_mono' ],
                [ 'mkv:x:flac_4800hz' ],
            ],
        ]);

        $this->specify("Discards video format options if video segment is 'x' in given format string",
            function( $format, $discarded_options )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            foreach ($discarded_options as $option) {
                $this->assertArrayNotHasKey( $option, $decoded );
            }
        }, [
            'examples' => [
                [ 'mp4:x::frag', ['frag'] ],
                [ 'webm:x:256k_mono:quality=2', ['quality'] ],
                [ 'mkv:x::quality=3,maxrate=8000k', ['quality', 'maxrate'] ],
            ],
        ]);

        $this->specify("Discards all audio specs if audio segment is 'x' in given format string",
            function ( $format )
        {
            $decoded = ConfigHelper::decodeFormat($format);

            $this->assertSame( true, $decoded['audio_disabled']);
            foreach (ConfigHelper::AUDIO_SPECS as $spec) {
                $this->assertArrayNotHasKey( $spec, $decoded );
            }
        }, [
            'examples' => [
                [ 'mp4::x' ],
                [ 'webm:vp9_1080p:x' ],
                [ 'mkv:0x600_2000k:x' ],
            ],
        ]);

        $this->specify("Decodes all specs defined in given format strings",
            function( $format, $specs )
        {
            $decoded = ConfigHelper::decodeFormat($format);
            $this->assertEquals( $specs, $decoded );
        }, [
            'examples' => [
                [
                    'format' => 'mp4:720p_hevc_25fps:mp3_96k_22050hz_mono:vprofile=high10,level=31,quality=2,maxrate=1000k,frag',
                    'specs' => [ 'container' => 'mp4',
                        'video_codec' => 'hevc', 'resolution' => '1280x720', 'video_bitrate' => null, 'fps' => '25fps',
                        'audio_codec' => 'mp3', 'audio_bitrate' => '96k', 'sample_rate' => '22050hz', 'audio_channel' => 'mono',
                        'vprofile' => 'high10', 'level' => '31', 'quality' => '2', 'maxrate' => '1000k', 'frag' => true,
                    ],
                ],
                [
                    'format' => 'webm:2160p_vp9:48000hz_flac_320k:2pass',
                    'specs' => [ 'container' => 'webm',
                        'video_codec' => 'vp9', 'resolution' => '3840x2160', 'video_bitrate' => '8000k', 'fps' => '0fps',
                        'audio_codec' => 'flac', 'audio_bitrate' => '320k', 'sample_rate' => '48000hz', 'audio_channel' => 'stereo',
                        '2pass' => true,
                    ],
                ],
                [
                    'format' => 'divx:23.98fps_mpeg2video_6000k_1080p:pcm_u8_256k:pix_fmt=yuv420p',
                    'specs' => [ 'container' => 'divx',
                        'video_codec' => 'mpeg2video', 'resolution' => '1920x1080', 'video_bitrate' => '6000k', 'fps' => '23.98fps',
                        'audio_codec' => 'pcm_u8', 'audio_bitrate' => '256k', 'sample_rate' => '44100hz', 'audio_channel' => 'stereo',
                        'pix_fmt' => 'yuv420p',
                    ],
                ],
                [
                    'format' => 'flv:400x600_15fps:x:2pass,pix_fmt=yuva444p10le',
                    'specs' => [ 'container' => 'flv',
                        'video_codec' => 'flv', 'resolution' => '400x600', 'video_bitrate' => '1000k', 'fps' => '15fps',
                        'audio_disabled' => true,
                        // 'audio_codec' => false, 'audio_bitrate' => false, 'sample_rate' => false, 'audio_channel' => false,
                        '2pass' => true, 'pix_fmt' => 'yuva444p10le',
                    ],
                ],
                [
                    'format' => 'avi:x:320k_flac',
                    'specs' => [ 'container' => 'avi',
                        'video_disabled' => true,
                        // 'video_codec' => false, 'resolution' => false, 'video_bitrate' => false, 'fps' => false,
                        'audio_codec' => 'flac', 'audio_bitrate' => '320k', 'sample_rate' => '44100hz', 'audio_channel' => 'stereo',
                    ],
                ],
                [
                    'format' => 'mp4:1080p:x:quality=3,maxrate=8000k',
                    'specs' => [ 'container' => 'mp4',
                        'video_codec' => 'h264', 'resolution' => '1920x1080', 'video_bitrate' => null, 'fps' => '0fps',
                        'audio_disabled' => true,
                        // 'audio_codec' => false, 'audio_bitrate' => false, 'sample_rate' => false, 'audio_channel' => false,
                        'quality' => '3', 'maxrate' => '8000k',
                    ],
                ],
                [
                    'format' => 'ogg:aac_22000hz',
                    'specs' => [ 'container' => 'ogg',
                        'audio_codec' => 'aac', 'audio_bitrate' => '128k', 'sample_rate' => '22000hz', 'audio_channel' => 'stereo',
                    ],
                ],
                [
                    'format' => 'ogg:256k_stereo',
                    'specs' => [ 'container' => 'ogg',
                        'audio_codec' => 'vorbis', 'audio_bitrate' => '256k', 'sample_rate' => '44100hz', 'audio_channel' => 'stereo',
                    ],
                ],
                [
                    'format' => 'mp3:96k_8000hz_mono',
                    'specs' => [ 'container' => 'mp3',
                        'audio_codec' => 'mp3', 'audio_bitrate' => '96k', 'sample_rate' => '8000hz', 'audio_channel' => 'mono',
                    ],
                ],
                [
                    'format' => 'jpg:576p',
                    'specs' => [ 'container' => 'jpg', 'resolution' => '0x576' ],
                ],
                [
                    'format' => 'png:800x0',
                    'specs' => [ 'container' => 'png', 'resolution' => '800x0' ],
                ],
            ],
        ]);
    }

    /**
     * =encodeFormat( $format )
     * @test
     */

    public function encodeFormat()
    {
        // @todo test behavior of `ConfigHelper::encodeFormat()` method

        $this->specify("Discards default spec values for container",
            function( $container, $defaults )
        {
        }, [
            'examples' => $this->provideImplicitContainerSpecs(),
        ]);
    }

    /**
     * =parseFormat( $format )
     * @test
     */

    public function parseFormat()
    {
        $this->specify("Discards default spec values for container",
            function( $container, $defaults )
        {
            $format = array_merge([ 'container' => $container ], $defaults);
            $parsed = ConfigHelper::parseFormat($format);

            $this->assertSame( [ 'container' => $container ], $parsed );
        }, [
            'examples' => $this->provideImplicitContainerSpecs(),
        ]);

        $this->specify("Resolves specs for definitions in `resolution` spec",
            function( $definition, $specs )
        {
            $container = 'mp4';
            $specs = array_merge([ 'container' => $container, ], $specs);

            $parsed = ConfigHelper::parseFormat([
                'container' => $container,
                'resolution' => $definition,
            ]);

            $this->assertSame( $specs, $parsed );

        }, [ 'examples' => $this->provideNormalizedDefinitionSpecs() ]);

        // $this->specify('Accepts a format string by decoding it into an array of format specs',
        //     function( $format, $specs )
        // {
        //     $parsed = ConfigHelper::parseFormat($format);

        //     $this->assertIsArray( $parsed );
        //     $this->assertSame( $parsed, $specs );

        // }, [ 'examples' => $this->provideValidFormatStringSpecs() ]);

        // @todo test normalizatio behavior of `ConfigHelper::parseFormat()` method
    }

    // =Data
    // ------------------------------------------------------------------------

    /**
     * @return array
     */

    public function provideBaseVideoContainerFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp4',
                'specs' => [ 'container' => 'mp4',
                    'video_codec' => 'h264', 'resolution' => '0x0', 'video_bitrate' => '1000k', 'fps' => '0fps',
                    'audio_codec' => 'aac', 'audio_bitrate' => '128k', 'audio_channel' => 'stereo',
                ],
            ],
            [
                'format' => 'webm',
                'specs' => [ 'container' => 'webm',
                    'video_codec' => 'h264', 'resolution' => '0x0', 'video_bitrate' => '1000k', 'fps' => '0fps',
                    'audio_codec' => 'aac', 'audio_bitrate' => '128k', 'audio_channel' => 'stereo',

                ],
            ],
            [
                'format' => 'avi',
                'specs' => [ 'container' => 'avi' ],
            ],
            [
                'format' => 'asf',
                'specs' => [ 'container' => 'asf' ],
            ],
            [
                'format' => 'mpegts',
                'specs' => [ 'container' => 'mpegts' ],
            ],
            [
                'format' => 'mov',
                'specs' => [ 'container' => 'mov' ],
            ],
            [
                'format' => 'mkv',
                'specs' => [ 'container' => 'mkv' ],
            ],
            [
                'format' => '3gp',
                'specs' => [ 'container' => '3gp' ],
            ],
            [
                'format' => 'ogv',
                'specs' => [ 'container' => 'ogv' ],
            ],
            [
                'format' => 'flv',
                'specs' => [ 'container' => 'flv' ],
            ],
            [
                'format' => 'swf',
                'specs' => [ 'container' => 'swf' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideAliasVideoContainerFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'divx',
                'specs' => [ 'container' => 'divx' ], // avi
            ],
            [
                'format' => 'xvid',
                'specs' => [ 'container' => 'xvid' ], // avi
            ],
            [
                'format' => 'wmv',
                'specs' => [ 'container' => 'wmv' ], // asf
            ],
            [
                'format' => 'flash',
                'specs' => [ 'container' => 'flash' ], // flv
            ],
            [
                'format' => 'theora',
                'specs' => [ 'container' => 'theora' ], // ogv
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideVideoContainerFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideBaseVideoContainerFormatStringSpecs(),
            $this->provideAliasVideoContainerFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideBaseAudioContainerFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp3',
                'specs' => [ 'container' => 'mp3' ],
            ],
            [
                'format' => 'ogg',
                'specs' => [ 'container' => 'ogg' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideAliasAudioContainerFormatStringSpecs(): array
    {
        return [];
    }

    /**
     * @return array
     */

    public function provideAudioContainerFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideBaseAudioContainerFormatStringSpecs(),
            $this->provideAliasAudioContainerFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideBaseImageContainerFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'jpg',
                'specs' => [ 'container' => 'jpg' ],
            ],
            [
                'format' => 'png',
                'specs' => [ 'container' => 'png' ],
            ],
            [
                'format' => 'gif',
                'specs' => [ 'container' => 'gif' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideAliasImageContainerFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'jpeg',
                'specs' => [ 'container' => 'jpeg' ], // jpg
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideImageContainerFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideBaseImageContainerFormatStringSpecs(),
            $this->provideAliasImageContainerFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideBaseContainerFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideBaseVideoContainerFormatStringSpecs(),
            $this->provideBaseAudioContainerFormatStringSpecs(),
            $this->provideBaseImageContainerFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideAliasContainerFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideAliasVideoContainerFormatStringSpecs(),
            $this->provideAliasAudioContainerFormatStringSpecs(),
            $this->provideAliasImageContainerFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideContainerFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideVideoContainerFormatStringSpecs(),
            $this->provideAudioContainerFormatStringSpecs(),
            $this->provideImageContainerFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideExplicitVideoCodecFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp4:hevc',
                'specs' => [ 'container' => 'mp4', 'video_codec' => 'hevc' ],
            ],
            [
                'format' => 'webm:vp9',
                'specs' => [ 'container' => 'webm', 'video_codec' => 'vp9' ],
            ],
            [
                'format' => 'divx:mjpeg',
                'specs' => [ 'container' => 'divx', 'video_codec' => 'mjpeg' ],
            ],
            [
                'format' => 'avi:mpeg2video',
                'specs' => [ 'container' => 'avi', 'video_codec' => 'mpeg2video' ],
            ],
            [
                'format' => 'mp4:wmv1',
                'specs' => [ 'container' => 'mp4', 'video_codec' => 'wmv1' ],
            ],
            [
                'format' => 'mov:prores',
                'specs' => [ 'container' => 'mp4', 'video_codec' => 'prores' ],
            ],
            [
                'format' => 'mkv:theora',
                'specs' => [ 'container' => 'mp4', 'video_codec' => 'theora' ],
            ],
            [
                'format' => 'ogv:h264',
                'specs' => [ 'container' => 'ogv', 'video_codec' => 'h264' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideImplicitVideoCodecFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp4:h264',
                'specs' => [ 'container' => 'mp4' ],
            ],
            [
                'format' => 'webm:vp8',
                'specs' => [ 'container' => 'webm' ],
            ],
            [
                'format' => 'avi:mpeg4',
                'specs' => [ 'container' => 'divx' ],
            ],
            [
                'format' => 'ogv:theora',
                'specs' => [ 'container' => 'ogv' ],
            ],
            [
                'format' => 'asf:wmv2',
                'specs' => [ 'container' => 'asf' ],
            ],
            [
                'format' => 'mpegts:h264',
                'specs' => [ 'container' => 'mpegts' ],
            ],
            [
                'format' => 'mov:h264',
                'specs' => [ 'container' => 'mov' ],
            ],
            [
                'format' => 'flash:flv',
                'specs' => [ 'container' => 'flash' ],
            ],
            [
                'format' => 'mkv:h264',
                'specs' => [ 'container' => 'mkv' ],
            ],
            [
                'format' => '3gp:h263',
                'specs' => [ 'container' => '3gp' ],
            ],
            [
                'format' => 'ogv:theora',
                'specs' => [ 'container' => 'ogv' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideValidVideoCodecFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideExplicitDefinitionFormatStringSpecs(),
            $this->provideImplicitDefinitionFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideInvalidVideoCodecFormatStringSpecs(): array
    {
        return [];
    }

    /**
     * @return array
     */

    public function provideExplicitVideoDefinitionFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp4:480p',
                'specs' => [ 'container' => 'mp4', 'resolution' => '0x480' ],
            ],
            [
                'format' => 'mp4:540p',
                'specs' => [ 'container' => 'mp4', 'resolution' => '0x540' ],
            ],
            [
                'format' => 'mp4:576p',
                'specs' => [ 'container' => 'mp4', 'resolution' => '0x576' ],
            ],
            [
                'format' => 'mp4:720p_800k',
                'specs' => [ 'container' => 'mp4', 'resolution' => '1280x720', 'video_bitrate' => '800k' ],
            ],
            [
                'format' => 'mp4:1080p_8000k',
                'specs' => [ 'container' => 'mp4', 'resolution' => '1920x1080', 'video_bitrate' => '8000k' ],
            ],
            [
                'format' => 'mp4:2160p_145000k',
                'specs' => [ 'container' => 'mp4', 'resolution' => '3840x2160', 'video_bitrate' => '145000k' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideImplicitVideoDefinitionFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp4:360p',
                'specs' => [ 'container' => 'mp4', 'resolution' => '0x360', 'video_bitrate' => '800k' ],
            ],
            [
                'format' => 'mp4:720p',
                'specs' => [ 'container' => 'mp4', 'resolution' => '1280x720', 'video_bitrate' => '2000k' ],
            ],
            [
                'format' => 'mp4:2160p',
                'specs' => [ 'container' => 'mp4', 'resolution' => '3840x2160', 'video_bitrate' => '8000k' ],
            ],
            // [
            //     'format' => 'mp4:2160p_25fps',
            //     'specs' => [ 'container' => 'mp4', 'resolution' => '3840x2160', 'video_bitrate' => '8000k', 'fps' => '25fps' ],
            // ],
        ];
    }

    /**
     * @return array
     */

    public function provideValidVideoDefinitionFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideExplicitVideoDefinitionFormatStringSpecs(),
            $this->provideImplicitVideoDefinitionFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideInvalidVideoDefinitionFormatStringSpecs(): array
    {
        return [];
    }

    /**
     * @return array
     */

    public function provideExplicitImageDefitionFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'jpg:240p',
                'specs' => [ 'resolution' => '0x240' ],
            ],
            [
                'format' => 'jpg:360p',
                'specs' => [ 'resolution' => '0x360' ],
            ],
            [
                'format' => 'jpg:540p',
                'specs' => [ 'container' => 'jpg', 'resolution' => '0x540' ],
            ],
            [
                'format' => 'jpg:720p',
                'specs' => [ 'resolution' => '1280x720' ],
            ],
            [
                'format' => 'jpg:1080p',
                'specs' => [ 'resolution' => '1920x1080' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideImplicitImageDefitionFormatStringSpecs(): array
    {
        return [];
    }

    /**
     * @return array
     */

    public function provideValidImageDefinitionFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideImplicitImageDefitionFormatStringSpecs(),
            $this->provideExplicitImageDefitionFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideInvalidImageDefinitionFormatStringSpecs(): array
    {
        return [];
    }

    /**
     * @return array
     */

    public function provideImplicitDefinitionFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideImplicitVideoDefitionFormatStringSpecs(),
            $this->provideImplicitImageDefitionFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideExplicitDefinitionFormatSringSpecs(): array
    {
        return array_merge(
            $this->provideExplicitVideoDefitionFormatStringSpecs(),
            $this->provideExplicitImageDefitionFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideValidDefinitionFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideValidVideoDefinitionFormatStringSpecs(),
            $this->provideValidImageDefinitionFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideInValidDefinitionFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideInvalidVideoCodecFormatStringSpecs(),
            $this->provideInvalidImageDefinitionFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideExplicitFpsFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp4:15fps',
                'specs' => [ 'container' => 'mp4', 'fps' => '15fps' ],
            ],
            [
                'format' => 'mp4:23.98fps',
                'specs' => [ 'container' => 'webm', 'fps' => '23.98fps' ],
            ],
            [
                'format' => 'mp4:25fps',
                'specs' => [ 'container' => 'flv', 'fps' => '25fps' ],
            ],
            [
                'format' => 'mp4:29.97fps',
                'specs' => [ 'container' => 'divx', 'fps' => '29.97fps' ],
            ],
            [
                'format' => 'mp4:30fps',
                'specs' => [ 'container' => 'mp4', 'fps' => '3Ofps' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideImplicitFpsFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp4:0fps',
                'specs' => [ 'container' => 'mp4' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideValidFpsFormatStringSpecs(): array
    {
        return array_merge(
            $this->provideImplicitFpsFormatStringSpecs(),
            $this->provideExplicitFpsFormatStringSpecs()
        );
    }

    /**
     * @return array
     */

    public function provideInValidFpsFormatStringSpecs(): array
    {
        return [
            [
                'format' => 'mp4:12fps',
                'specs' => [ 'container' => 'mp4' ],
            ],
            [
                'format' => 'mp4:29.98fps',
                'specs' => [ 'container' => 'mp4' ],
            ],
            [
                'format' => 'mp4:-15fps',
                'specs' => [ 'container' => 'mp4' ],
            ],
        ];
    }

    /**
     * @return array
     */

    public function provideValidVideoFormatStringSpecs(): array
    {
        return [
            // [
            //     'format' => 'mp4:1600x900',
            //     'specs' => [ 'container' => 'mp4', 'resolution' => '1600x900' ],
            // ],
            // [
            //     'format' => 'mp4:800x600_15fps',
            //     'specs' => [ 'container' => 'mp4', 'resolution' => '800x600', 'fps' => '15fps' ],
            // ],
            // [
            //     'format' => 'webm:1080p_145000k',
            //     'specs' => [ 'container' => 'webm', 'resolution' => '1920x1080', 'video_bitrate' => '145000k' ],
            // ],
        ];
    }

    /**
     * @return array
     */

    public function provideInvalidVideoFormatStringSpecs(): array
    {
        return [];
    }

    /**
     * Provides example dataset where each item contains:
     * - definition: video resolution definition
     * - specs: corresponding video specs
     *
     * @return array
     */

    public function provideNormalizedDefinitionSpecs(): array
    {
        return [
            [
                'definition' => '240p',
                'specs' => [ 'resolution' => '0x240', 'video_bitrate' => '500k' ],
            ],
            [
                'definition' => '360p',
                'specs' => [ 'resolution' => '0x360', 'video_bitrate' => '800k' ],
            ],
            [
                'definition' => '480p',
                'specs' => [ 'resolution' => '0x480' ],
            ],
            [
                'definition' => '540p',
                'specs' => [ 'resolution' => '0x540' ],
            ],
            [
                'definition' => '576p',
                'specs' => [ 'resolution' => '0x576' ],
            ],
            [
                'definition' => '720p',
                'specs' => [ 'resolution' => '1280x720', 'video_bitrate' => '2000k' ],
            ],
            [
                'definition' => '1080p',
                'specs' => [ 'resolution' => '1920x1080', 'video_bitrate' => '4000k' ],
            ],
            [
                'definition' => '2160p',
                'specs' => [ 'resolution' => '3840x2160', 'video_bitrate' => '8000k' ],
            ],
        ];
    }

    /**
     * Provides example dataset for video containers and their default format specs
     * - {string} $container: Video container
     * - {array} $specs: Default specs for video container
     *
     * @return array
     */

    public function provideImplicitVideoContainerSpecs(): array
    {
        $data = [];

        foreach (ConfigHelper::VIDEO_OUTPUT_CONTAINERS as $container)
        {
            $base = ConfigHelper::CONTAINER_ALIASES[$container] ?? $container;
            $specs = array_merge([],
                ConfigHelper::DEFAULT_VIDEO_SPECS,
                ConfigHelper::DEFAULT_AUDIO_SPECS,
                (ConfigHelper::CONTAINER_VIDEO_SPECS[$base] ?? []),
                (ConfigHelper::CONTAINER_AUDIO_SPECS[$base] ?? [])
            );

            $data[] = [
                'container' =>  $container,
                'specs' => $specs,
            ];
        }

        return $data;
    }

    /**
     * Provides example dataset for audio containers and their default format specs
     * - {string} $container: Audio container
     * - {array} $specs: Default specs for audio container
     *
     * @return array
     */

    public function provideImplicitAudioContainerSpecs(): array
    {
        $data = [];

        foreach (ConfigHelper::AUDIO_OUTPUT_CONTAINERS as $container)
        {
            $base = ConfigHelper::CONTAINER_ALIASES[$container] ?? $container;
            $specs = array_merge([],
                ConfigHelper::DEFAULT_AUDIO_SPECS,
                (ConfigHelper::DEFAULT_AUDIO_SPECS[$base] ?? [])
            );

            $data[] = [
                'container' =>  $container,
                'specs' => $specs,
            ];
        }

        return $data;
    }

    /**
     * Provides example dataset for image containers and their default format specs
     * - {string} $container: Image container
     * - {array} $specs: Default specs for image container
     *
     * @return array
     */

    public function provideImplicitImageContainerSpecs(): array
    {
        $data = [];

        foreach (ConfigHelper::IMAGE_OUTPUT_CONTAINERS as $container)
        {
            $base = ConfigHelper::CONTAINER_ALIASES[$container] ?? $container;
            $specs = array_merge([],
                ConfigHelper::DEFAULT_IMAGE_SPECS,
                (ConfigHelper::CONTAINER_IMAGE_SPECS[$base] ?? [])
            );

            $data[] = [
                'container' =>  $container,
                'specs' => $specs,
            ];
        }

        return $data;
    }

    /**
     * Provides example dataset for all output containers and their default format specs
     * - {string} $container: Format container
     * - {array} $specs: Default specs for format container
     *
     * @return array
     */

    public function provideImplicitContainerSpecs(): array
    {
        $videoSpecs = $this->provideImplicitVideoContainerSpecs();
        $audioSpecs = $this->provideImplicitAudioContainerSpecs();
        $imageSpecs = $this->provideImplicitImageContainerSpecs();

        return array_merge($videoSpecs, $audioSpecs, $imageSpecs);
    }

}
