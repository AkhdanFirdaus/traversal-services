<?php

declare(strict_types=1);

namespace App\Tests;

use Infection\Testing\BaseMutatorTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Mutator\HashEqualsToStrcmp;
use App\Mutator\HashEqualsToStrcasecmp;
use App\Mutator\HashEqualsToStrcoll;
use App\Mutator\HashEqualsToLevenshtein;

#[CoversClass(HashEqualsToStrcmp::class)]
#[CoversClass(HashEqualsToStrcasecmp::class)]
#[CoversClass(HashEqualsToStrcoll::class)]
#[CoversClass(HashEqualsToLevenshtein::class)]
final class HashEqualsReplacementMutatorsTest extends BaseMutatorTestCase
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
        // Input code to be tested
        $inputCode = <<<'PHP'
            <?php
            $result = hash_equals($hash1, $hash2);
            PHP;

        // Expected output for each alternative
        yield 'It replaces hash_equals with strcmp' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $result = strcmp($hash1, $hash2);
                    PHP,
            ],
        ];

        yield 'It replaces hash_equals with strcasecmp' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $result = strcasecmp($hash1, $hash2);
                    PHP,
            ],
        ];

        yield 'It replaces hash_equals with strcoll' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $result = strcoll($hash1, $hash2);
                    PHP,
            ],
        ];

        yield 'It replaces hash_equals with levenshtein' => [
            $inputCode,
            [
                <<<'PHP'
                    <?php
                    $result = levenshtein($hash1, $hash2);
                    PHP,
            ],
        ];
    }
}