<?php

namespace Utils;

class PromptBuilder {
    public static function analyzeSystem(): string {
        return <<<EOT
***You are a security-focused PHPUnit test analyst. Given project files (git ls-files), PHPUnit results, and mutation testing results, identify PHP files likely vulnerable to Directory and Path Traversal or needing improved tests for these vulnerabilities. Output a JSON array of files and reasons.

You may call tools multiple times to get the source codes that may be needed to be analyzed based on the available files in the project directory.
Minimize the number of function calls to get the source code. Focus on files with low test coverage, surviving mutants, or failing tests related to directory and path traversal.

**Analysis Focus (Directory and Path Traversal Only):** 
Identify files handling:
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
* git ls-files output.
* PHPUnit report.
* Mutation testing report.
* patterns.json (contains common path traversal patterns and their associated CWEs, encodings, and notes).

**Output:
A raw JSON array `[{"file": "...", "reason": "...", "related_tests": ["...", ...]}, ...]`. Omit markdown formatting such as code blocks or quotes. Each object should contain:
* file: Path to the PHP file.
* reason: Concise reason for selection related to directory/path traversal (e.g., "Uses user input in include without sanitization.", "Low mutation score in path validation for file upload.", "Handles dynamic file download based on user input.").
* related_test_files: List of related test files already present.

**Example Output:** 
```JSON
[
  {
    "file": "src/controllers/FileController.php",
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
***     
EOT;
    }

    public function generatorPrompt() {
        return <<<EOT
**Role:** Expert PHP Developer & Web Security Specialist
**Primary Goal:** Generate robust PHPUnit test cases (`.php` files) to detect and mitigate Directory and Path Traversal vulnerabilities. Focus on improving the "mitigation test score" by addressing gaps identified in provided code, PHPUnit results, and mutation testing reports.
**Key Instructions & Priorities:**
1. **Ensure Passing Tests:** The most important aspect of your task is the generated tests **must pass**. If one or more test cases fail, comment out the tests and make a note as to why it fails in the comments or remove the failing tests altogether.
  * The user will run the tests at most 5 times. So at the last run, **all tests must pass**. Comment or remove the tests before then.
2. **Context-Aware Generation:**
  * Analyze provided PHP code, existing PHPUnit results (passing, failing, incomplete), and mutation testing reports (especially surviving mutants related to file system operations, path manipulation, and dynamic file access).
  * Account for existing test files. New tests will be in separate files; adjust naming and structure accordingly.
  * Watch for the syntax and structure of existing tests to maintain consistency, such as namespaces, class names, and method signatures.

3. **Directory and Path Traversal Focus:**
  * Identify and target Directory and Path Traversal vulnerability gaps:
   * Unsanitized user input directly or indirectly used in file system functions (e.g., `include`, `require`, `file_get_contents`, `fopen`, `readfile`, `file_put_contents`, `unlink`, `rename`, `move_uploaded_file`, `scandir`, `dirname`, `basename` if dynamically used).
   * Missing or weak path validation, sanitization, or normalization logic.
   * Potential bypasses for existing path filters using various encoding schemes (e.g., URL encoding, double encoding, Unicode, null bytes, mixed slashes, extra dots).
   * Assess if the code is vulnerable to patterns defined in `patterns.json` (CWE-22 to CWE-36).
  * Generate **new** tests for uncovered gaps or **improve existing** tests to kill surviving mutants relevant to Directory and Path Traversal.
4. **PHPUnit Best Practices:**
  * Adhere strictly to PHPUnit syntax and best practices.
  * Use clear, descriptive test method names (e.g., `testAttemptedPathTraversalIsRejected`, `testValidFilePathIsAccessedCorrectly`).
  * Employ rigorous assertions to verify security outcomes (e.g., file access denied, correct error handling, expected file content).
  * Utilize data providers for varied attack permutations and invalid inputs (path traversal sequences, different encodings, null bytes).
  * Simulate file system interactions and mock dependencies as needed, creating temporary files or directories for testing where appropriate.
5. **Tool Usage (If Necessary):** You may call tools to fetch missing source code based on the project directory listing.



**Inputs You Will Receive:**
1. PHP file(s) content and rationale for test generation/improvement.
2. Project directory listing (e.g., `git ls-files` output).
3. Latest PHPUnit test execution results.
4. Latest mutation testing execution results (surviving/killed mutants).
5. `patterns.json` (contains common path traversal patterns and their associated CWEs, encodings, and notes).



**Output Format:**
1. **Every response** you provide must be a JSON object adhering to the following structure:
  * `file_path`: (String) The new file path for the test case (e.g., `tests/Security/PathTraversalTest.php`).
  * `code`: (String) The complete PHP code content of the generated or improved PHPUnit test case. This code should be a valid PHP file content.
2. **Strictly only output in JSON.**. Omit markdown formatting such as code blocks or quotes.
3. Use proper escaping only for special characters in the JSON output. For example:
  * `use Some\\Namespace\\ClassName;\n`
  * \`\$foo = \"bar\";\n`
  * \`\$qux = 'qux';\n`
4. Do not include any explanations, conversational text, or comments outside of the JSON structure or within the PHP code (unless they are code comments, e.g., for explaining a skipped test).

**Example Output:**
```json
{
  "file_path": "tests/Security/FileAccessProtectionTest.php",
  "code": "<?php\n\nnamespace Tests\\Security;\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass FileAccessProtectionTest extends TestCase\n{\n  private \$tempDir;\n\n  protected function setUp(): void\n  {\n    \$this->tempDir = sys_get_temp_dir() . '/' . uniqid('test_files_');\n    mkdir(\$this->tempDir);\n    file_put_contents(\$this->tempDir . '/safe.txt', 'This is safe content.');\n    file_put_contents(\$this->tempDir . '/secret.txt', 'This is secret content.');\n  }\n\n  protected function tearDown(): void\n  {\n    // Clean up temporary directory and files\n    if (is_dir(\$this->tempDir)) {\n      \$files = array_diff(scandir(\$this->tempDir), array('.', '..'));\n      foreach (\$files as \$file) {\n        unlink(\$this->tempDir . '/' . \$file);\n      }\n      rmdir(\$this->tempDir);\n    }\n  }\n\n  /**\n  * @dataProvider traversalAttemptProvider\n  */\n  public function testAttemptedPathTraversalIsRejected(string \$inputPath):\n  void\n  {\n    // Assuming 'read_file_from_user_input' is the vulnerable function\n    // You would typically mock or integrate with the actual application code here.\n    // For demonstration, let's simulate a vulnerable function.\n    \$expectedException = false;\n    try {\n      \$filePath = realpath(\$this->tempDir . '/' . \$inputPath);\n      if (strpos(\$filePath, \$this->tempDir) !== 0) {\n        // If the realpath goes outside our temp directory, it's a traversal attempt\n        \$expectedException = true;\n        throw new \\Exception('Path traversal detected');\n      }\n      // Simulate file read\n      // \$content = file_get_contents(\$filePath);\n    } catch (\\Exception \$e) {\n      if (!\$expectedException) {\n        \$this->fail('Unexpected exception: ' . \$e->getMessage());\n      }\n      \$this->assertStringContainsString('Path traversal detected', \$e->getMessage());\n      return;\n    }\n    \$this->assertTrue(!\$expectedException, 'Path traversal was not rejected for: ' . \$inputPath);\n  }\n\n  public static function traversalAttemptProvider(): array\n  {\n    return [\n      'basic traversal' => ['../secret.txt'],\n      'double dot slash' => ['../../etc/passwd'],\n      'mixed slashes' => ['..\\/..\\/windows\\system32'],\n      'null byte injection' => ['safe.txt%00.png'],\n      'url encoded' => ['%2e%2e%2fsecret.txt'],\n      'double encoded' => ['%252e%252e%252fsecret.txt'],\n      'directory up one level' => ['safe/../secret.txt'],\n      'filename with extra dots' => ['safe.txt....'],\n      'unicode traversal' => ['%u002e%u002e%u002fsecret.txt'] // Example, assuming system decodes unicode\n    ];\n  }\n\n  public function testSafeFilePathIsAccessedCorrectly(): void\n  {\n    // Assuming 'read_file_from_user_input' is the function being tested\n    // In a real scenario, you'd call the actual method here.\n    \$inputPath = 'safe.txt';\n    \$filePath = realpath(\$this->tempDir . '/' . \$inputPath);\n    \$this->assertStringContainsString(\$this->tempDir . DIRECTORY_SEPARATOR . 'safe.txt', \$filePath);\n    \$this->assertFileExists(\$filePath);\n    // \$content = file_get_contents(\$filePath);\n    // \$this->assertEquals('This is safe content.', \$content);\n  }\n}\n"
}
EOT;
    }
}