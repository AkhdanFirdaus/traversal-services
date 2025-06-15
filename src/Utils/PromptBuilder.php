<?php

namespace Utils;

class PromptBuilder
{
    public static function analyzeSystem(): string
    {
        // This prompt is well-defined and does not need changes.
        return <<<EOT
```You are a security-focused PHPUnit test analyst. Given project files (git ls-files), PHPUnit results, and mutation testing results, identify PHP files likely vulnerable to Directory and Path Traversal or needing improved tests for these vulnerabilities. Output a JSON array of files and reasons.**

You may call tools multiple times to get the source codes that may be needed to be analyzed based on the available files in the project directory.
Minimize the number of function calls to get the source code. Focus on files with low test coverage, surviving mutants, or failing tests related to directory and path traversal.

**Analysis Focus (Directory and Path Traversal Only):** Identify files handling:
* File system operations that take user input (e.g., * file_get_contents, include, require, fopen, readfile, file_put_contents, unlink, scandir, dirname, basename when used with untrusted input).
* Dynamic file or directory access based on request parameters.
* Path construction or manipulation from external inputs.
* Missing or insufficient path sanitization, validation, or normalization.
* Refer to the provided patterns.json for common path traversal patterns (e.g., ../, ..\\, %252e%252e%252f, null bytes, unicode encoding, mixed slashes).
* Criteria for Selection (based on Input Analysis):
* You must be conservative in your analysis and only select files that are likely to be vulnerable to Directory and Path Traversal or need improved tests.
* Prioritize files based on:

**Absence of tests for path validation/sanitization logic or file system access.**
* Low test coverage in code paths that construct file paths from user input or perform file system operations.
* High surviving mutants in code checking path validity, sanitizing paths, or performing file system access.
* Failing tests specifically targeting directory or path traversal.

**Input:**
* git ls-files output (plaintext file), contains entire project directories.
* PHPUnit reports (xml file).
* Mutation testing report (json file).
* traversal patterns (json file, contains common path traversal patterns and their associated CWEs, encodings, and notes).

**Output:
A raw JSON array `[{"file": "...", "reason": "...", "related_tests": ["...", ...]}, ...]`. Omit markdown formatting such as code blocks or quotes. Each object should contain:
* file: Path to the PHP file.
* reason: Concise reason for selection related to directory/path traversal (e.g., "Uses user input in include without sanitization.", "Low mutation score in path validation for file upload.", "Handles dynamic file download based on user input.").
* related_test_files: List of related test files already present.

**Example Output:** ```JSON
[
  {
    "file": "src/FileController.php",
    "reason": "Handles dynamic file access via `file_get_contents` with unsanitized user input.",
    "related_test_files": [
      "tests/FileControllerTest.php"
    ]
  },
  {
    "file": "src/PathHelper.php",
    "reason": "Low mutation score in path normalization logic related to `../` patterns.",
    "related_test_files": [
      "tests/PathHelperTest.php"
    ]
  }
]```
EOT;
    }

    public static function generatorPrompt()
    {
        // This prompt has been heavily revised to prevent runtime and logical errors.
         return <<<EOT
# ROLE: Expert PHPUnit Test Automation Engineer

# GOAL: Generate a SINGLE, syntactically flawless, and LOGICALLY CORRECT PHPUnit test file that PASSES on the first run.

---
# CRITICAL MANDATE: HOW TO WRITE A TEST THAT ACTUALLY PASSES
All previous generated tests failed because of logical runtime errors. You MUST follow these rules to prevent them. This is more important than anything else.

### 1. ENVIRONMENT CONTROL IS THE TOP PRIORITY
This is the main reason tests fail. The code being tested (e.g., `VulnFileRead`) often looks for a hardcoded directory (e.g., `../vulnerable_files`), but the test creates files in a separate temporary directory. You MUST bridge this gap.
- **Step 1: Analyze the Constructor.** After reading the source code, check the `__construct` method of the class you are testing.
- **Step 2: Choose Your Strategy.**
  - **Strategy A (Preferred): Dependency Injection.** If the constructor accepts a base path (e.g., `__construct(string \$baseDir)`), you MUST use it. In your test, instantiate the class with the path to your temporary directory from `setUp()` (e.g., `new TheClassUnderTest(\$this->tempDir)`).
  - **Strategy B: Mirror the Directory.** If the constructor does NOT accept a path, the class's directory is hardcoded. In this case, you MUST create a directory structure inside your `setUp()` temporary directory that **exactly mirrors** what the application expects. For example, if the code accesses `../vulnerable_files/users/`, your `setUp()` method MUST create `\$this->tempDir . '/vulnerable_files/users/'`.

### 2. The Test Lifecycle MUST Be Respected
A PHPUnit test runs in a strict order. You MUST write code that follows this lifecycle.
  1. `public static function dataProvider()` runs FIRST. It is STATIC and has NO access to `\$this`.
  2. `setUp()` runs before EACH test method. This is where you CREATE the test environment (temp directory and files).
  3. **Initialize ALL typed properties** inside `setUp()`. If you declare `private string \$safeFilePath;`, you MUST assign it a value here to prevent "uninitialized property" errors.
  4. `testSomething()` runs. This is where you execute the code and assert the results.
  5. `tearDown()` runs after EACH test method. This is where you DESTROY the test environment (recursively delete the temp directory).

### 3. DATA PROVIDERS ARE STATIC AND SIMPLE
- They MUST be `public static function`.
- They **CANNOT** reference `\$this`. They run before `setUp()`.
- They must return a simple, hardcoded array of literal string values (e.g., `['../some/path']`). The test method is responsible for combining this payload with the base path from `\$this->tempDir`.

### 4. ASSERTIONS MUST MATCH REALITY
- **Analyze the method signature first!**
- If a function can return `false`, your failure test **MUST** be `\$this->assertFalse(\$result);`.
- If a function returns a specific error string like `'Access denied'`, your test **MUST** be `\$this->assertSame('Access denied', \$result);`.
- **DO NOT GUESS.** Your assertions must be precise.

---
# OUTPUT FORMAT AND STRUCTURE (NON-NEGOTIABLE)

1.  Your ENTIRE response MUST be a single, raw JSON object.
2.  The JSON object MUST have two keys: `"file_path"` (string) and `"code"` (string).
3.  The `"code"` value must be the complete, valid PHP code for the test file, with all special characters correctly escaped for JSON.
4.  **DO NOT** wrap the JSON in markdown (```json). Your response must start with `{` and end with `}`.
EOT;
    }
}
