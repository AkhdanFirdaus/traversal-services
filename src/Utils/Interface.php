<?php

namespace Utils;

class IStats {
    public int $totalMutantsCount;
    public int $killedCount;
    public int $notCoveredCount;
    public int $escapedCount;
    public int $errorCount;
    public int $syntaxErrorCount;
    public int $skippedCount;
    public int $ignoredCount;
    public int $timedOutCount;
    public float $msi;
    public float $mutationCodeCoverage;
    public float $coveredCodeMsi;
    
    public function __construct(mixed $data) {
        $this->totalMutantsCount = $data['totalMutantsCount'];
        $this->killedCount = $data['killedCount'];
        $this->notCoveredCount = $data['notCoveredCount'];
        $this->escapedCount = $data['escapedCount'];
        $this->errorCount = $data['errorCount'];
        $this->syntaxErrorCount = $data['syntaxErrorCount'];
        $this->skippedCount = $data['skippedCount'];
        $this->ignoredCount = $data['ignoredCount'];
        $this->timedOutCount = $data['timedOutCount'];
        $this->msi = $data['msi'];
        $this->mutationCodeCoverage = $data['mutationCodeCoverage'];
        $this->coveredCodeMsi = $data['coveredCodeMsi'];
    }
}

class IMutator {
    public string $mutatorName;
    public string $originalFilePath;
    public string $originalStartLine;

    public function __construct(mixed $data) {
        $this->mutatorName = $data['mutatorName'];
        $this->originalFilePath = $data['originalFilePath'];
        $this->originalStartLine = $data['originalStartLine'];
    }
}

class IMutationEntry {
    public string $mutator;
    public string $diff;

    public function __construct(mixed $data) {
        $this->mutator = $data['mutator'];
        $this->diff = $data['diff'];
    }
}

class IMutationReport {
    public static function fromJson(string $path): mixed {
        $result = FileHelper::readFile($path);
        
        $stats = $result['stats'];
        $escaped = $result['escaped'];
        $timeouted = $result['timeouted'];
        $killed = $result['killed'];
        $errored = $result['errored'];
        $syntaxError = $result['syntaxError'];
        $uncovered = $result['uncovered'];
        $ignored = $result['ignored'];
        
        return [
            'stats' => $stats,
            'escaped' => $escaped,
            'timeouted' => $timeouted,
            'killed' => $killed,
            'errored' => $errored,
            'syntaxError' => $syntaxError,
            'uncovered' => $uncovered,
            'ignored' => $ignored,
        ];
    }
}

class IsAnalyzeResult {
    public function __construct(
        private string $file,
        private string $reason,
        private array $relatedTestFiles,
    ) {}
}

class ITestGenResult {
    public function __construct(
        private string $filePath,
        private string $code,
    ) {}
}

class IFinalReport {
    public function __construct(
        private array $testCases,
        private array $analyzeResults,
        private array $beforeResult,
        private array $afterResult,
    ) {}
}

class ITestGenTarget {
    public function __construct(
        private string $file,
        private string $reason,
        private string $code,
        private array $relatedTests,
    ) {}
}
class IProjectContext {
    public function __construct(
        private string $projectContent,
        private string $unitTestResult,
        private string $coverageReport,
        private string $mutationTestReport,
    ) {}
}