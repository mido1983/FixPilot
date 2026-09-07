<?php
use PHPUnit\Framework\TestCase; final class FixPilotStaticTest extends TestCase{public function testMainPluginExists(){self::assertFileExists(dirname(__DIR__).'/wp-fixpilot.php');}public function testReadmeExists(){self::assertFileExists(dirname(__DIR__).'/readme.txt');}}
