<?php

/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

namespace ElephantIO\Test\Websocket;

use ElephantIO\Parser\Websocket\Decoder;
use ElephantIO\Parser\Websocket\Encoder;
use ElephantIO\Parser\Websocket\Payload as BasePayload;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class PayloadTest extends TestCase
{
    public function testMaskData()
    {
        $payload = new Payload();

        $refl = new ReflectionProperty(Payload::class, 'maskKey');
        $refl->setAccessible(true);
        $refl->setValue($payload, '?EV!');

        $refl = new ReflectionMethod(Payload::class, 'maskData');
        $refl->setAccessible(true);

        $this->assertSame('592a39', bin2hex($refl->invoke($payload, 'foo')));
    }

    protected function encodeDecode($sz, $filename)
    {
        $payload = file_get_contents($filename);
        $encoder = new Encoder($payload, Decoder::OPCODE_TEXT, false);
        $encoded = (string) $encoder;
        $decoder = new Decoder($encoded);
        $decoded = (string) $decoder;
        $this->assertEquals($payload, $decoded, 'Properly encode and decode payload '.$sz.' content');
    }

    public function testPayload7D()
    {
        $this->encodeDecode('125-bytes', __DIR__.'/data/payload-7d.txt');
    }

    public function testPayloadFFFF()
    {
        $this->encodeDecode('64-kilobytes', __DIR__.'/data/payload-ffff.txt');
    }

    public function testPayloadAboveFFFF()
    {
        $this->encodeDecode('100-kilobytes', __DIR__.'/data/payload-100k.txt');
    }
}

/** Fixtures for these tests */
class Payload extends BasePayload
{
}
