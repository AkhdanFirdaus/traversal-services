<?php

declare(strict_types=1);

namespace App\Tests;

use Infection\Testing\BaseMutatorTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Mutator\RandomBytesToOpenSslRandomPseudoBytes;
use App\Mutator\RandomBytesToRandomInt;
use App\Mutator\RandomBytesToMcryptCreateIv;
use App\Mutator\RandomBytesToMtRand;

#[CoversClass(RandomBytesToOpenSslRandomPseudoBytes::class)]
#[CoversClass(RandomBytesToRandomInt::class)]
#[CoversClass(RandomBytesToMcryptCreateIv::class)]
#[CoversClass(RandomBytesToMtRand::class)]
final class RandomBytesReplacementMutatorsTest extends BaseMutatorTestCase
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
            $bytes = random_bytes(32);
            PHP;

        // Output yang diharapkan untuk setiap alternatif
        yield 'It replaces random_bytes with openssl_random_pseudo_bytes' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $bytes = openssl_random_pseudo_bytes(32);
                    PHP,
            ],
        ];

        yield 'It replaces random_bytes with random_int' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $bytes = random_int(0, 32);
                    PHP,
            ],
        ];

        yield 'It replaces random_bytes with mcrypt_create_iv' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $bytes = mcrypt_create_iv(32, MCRYPT_DEV_URANDOM);
                    PHP,
            ],
        ];

        yield 'It replaces random_bytes with mt_rand' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $bytes = mt_rand(0, 32);
                    PHP,
            ],
        ];
    }
}