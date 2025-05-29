<?php

declare(strict_types=1);

namespace App\Tests;

use Infection\Testing\BaseMutatorTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Mutator\HmacAlgoToMd5;
use App\Mutator\HmacAlgoToSha1;
use App\Mutator\HmacAlgoToSha512;
use App\Mutator\HmacAlgoToSha384;
use App\Mutator\HmacAlgoToBlake2b;
use App\Mutator\HmacAlgoToBlake2s;
use App\Mutator\HmacAlgoToTiger1283;
use App\Mutator\HmacAlgoToWhirlpool;

#[CoversClass(HmacAlgoToMd5::class)]
#[CoversClass(HmacAlgoToSha1::class)]
#[CoversClass(HmacAlgoToSha512::class)]
#[CoversClass(HmacAlgoToSha384::class)]
#[CoversClass(HmacAlgoToBlake2b::class)]
#[CoversClass(HmacAlgoToBlake2s::class)]
#[CoversClass(HmacAlgoToTiger1283::class)]
#[CoversClass(HmacAlgoToWhirlpool::class)]
final class HmacAlgoMutatorsTest extends BaseMutatorTestCase
{
    /**
     * @param string|string[] $expected
     */
    #[DataProvider('mutationsProvider')]
    public function test_it_can_mutate(string $input, string|array $expected): void
    {
        $this->assertMutatesInput($input, $expected);
    }

    public static function mutationsProvider(): iterable
    {
        // Input kode yang akan diuji
        $inputCode = <<<'PHP'
            <?php
            $hashAlgo = 'sha256';
            PHP;

        // Output yang diharapkan untuk setiap alternatif
        yield 'It replaces sha256 with md5' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $hashAlgo = 'md5';
                    PHP,
            ],
        ];

        yield 'It replaces sha256 with sha1' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $hashAlgo = 'sha1';
                    PHP,
            ],
        ];

        yield 'It replaces sha256 with sha512' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $hashAlgo = 'sha512';
                    PHP,
            ],
        ];

        yield 'It replaces sha256 with sha384' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $hashAlgo = 'sha384';
                    PHP,
            ],
        ];

        yield 'It replaces sha256 with blake2b' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $hashAlgo = 'blake2b';
                    PHP,
            ],
        ];

        yield 'It replaces sha256 with blake2s' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $hashAlgo = 'blake2s';
                    PHP,
            ],
        ];

        yield 'It replaces sha256 with tiger128,3' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $hashAlgo = 'tiger128,3';
                    PHP,
            ],
        ];

        yield 'It replaces sha256 with whirlpool' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $hashAlgo = 'whirlpool';
                    PHP,
            ],
        ];
    }
}