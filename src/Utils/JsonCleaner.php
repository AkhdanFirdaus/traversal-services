<?php

namespace Utils;

use JsonException;

class JsonCleaner
{
    /**
     * Cleans and parses a raw string from an LLM response, expecting it to be JSON.
     * It handles the most common LLM formatting issues, like adding markdown code blocks.
     *
     * @param string $rawOutput The raw text response from the Gemini API.
     * @return array The decoded JSON as a PHP array.
     * @throws JsonException if the string cannot be parsed after cleaning.
     */
    public static function parse(string $rawOutput): array
    {
        // Step 1: Trim leading/trailing whitespace.
        $cleanedJson = trim($rawOutput);

        // Step 2: Remove the most common issue - Markdown code blocks.
        // This regex removes ```json at the start and ``` at the end.
        if (str_starts_with($cleanedJson, '```')) {
            $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', $cleanedJson);
            $cleanedJson = trim($cleanedJson); // Trim again after regex
        }

        // Step 3: Attempt to decode the cleaned string.
        $decoded = json_decode($cleanedJson, true, 512, JSON_THROW_ON_ERROR);
        
        // The json_decode was successful, return the array.
        return $decoded;
    }
}
