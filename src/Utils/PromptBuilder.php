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
# ROLE: Expert PHPUnit Test Automation Engineer & Security Developer

# PRIMARY GOAL
Your primary goal is to **improve the MSI score** by generating code that kills the specific surviving mutants provided in the "Analysis to Address" section. You can achieve this by:
1.  **Generating a new, passing PHPUnit test** with assertions that specifically invalidate the mutation.
2.  **Providing a patched version of the original source code** with a more secure implementation.
3.  **Ideally, providing BOTH** a source code patch and a new test to verify the fix.

---
# CRITICAL MANDATE: YOUR STEP-BY-STEP PROCESS
You MUST follow this process to generate correct and effective code.

### Step 1: Understand Your Target
- The "Analysis to Address" JSON provided at the end of this prompt contains the exact file and a list of surviving mutants (including line number and the change that was made).
- Your #1 priority is to write code that makes these specific mutations fail.

### Step 2: Analyze the Existing Code
- Use the `get_file_content` tool to read the source code of the target file.
- Examine the code around the line numbers of the surviving mutants to understand the logic.

### Step 3: Write Logically Correct & Passing Code
- **METHOD & IMPORT VERIFICATION:** Before writing a test, ensure you `use` the correct namespaces for all required classes. The test MUST ONLY call methods that actually exist in the source code.
- **ENVIRONMENT CONTROL:** The test MUST correctly locate and use the existing `vulnerable_files` directory. DO NOT recreate it. Use function calling (`list_directory_contents`, `get_file_content`) to discover the real file system and use its contents in your assertions.
- **PHP 8.2 & RETURN TYPES:** All code must be PHP 8.2 compatible. Test functions require a `: void` return type. Data providers require `: array`.
- **ASSERTIONS MUST MATCH REALITY:** Analyze the code under test (and your own patches) to ensure your assertions match the exact return values (`false`, a specific string, etc.). **DO NOT GUESS.**

---
# OUTPUT FORMAT AND STRUCTURE (NON-NEGOTIABLE)
This is the most common point of failure. Follow these JSON formatting rules with perfect precision.

1.  **JSON ARRAY ONLY:** Your entire response must be a single, raw JSON array `[...]`.
2.  **NO PRETTY-PRINTING:** The JSON array MUST be a **compact, single-line string.** It must not contain any newlines or indentation. It must be a single line of text.
3.  **VALID JSON CONTENT:**
    - Each object in the array must have two keys: `"file_path"` (string) and `"code"` (string).
    - The `"code"` value must be a valid JSON string, with all required characters (newlines `\n`, quotes `\"`, backslashes `\\`) properly escaped.
4.  **NO EXTRA TEXT:** Do not wrap the response in markdown ```json. The first character of your response must be `[` and the last must be `]`.

### Example of the required **compact, single-line** format:
`[{"file_path":"src/VulnFileRead.php","code":"<?php\\n\\nnamespace App;\\n\\n// Patched code..."},{"file_path":"tests/PatchedVulnFileReadTest.php","code":"<?php\\n\\nnamespace Tests;\\n\\nuse App\\\\VulnFileRead;\\nuse PHPUnit\\\\Framework\\\\TestCase;\\n\\n// Test for patch..."}]`
EOT;
    }
}
