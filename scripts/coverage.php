<?php
declare(strict_types=1);

/**
 * DoodhBee Code Coverage Runner
 *
 * Runs test suites under PCOV code coverage and reports line coverage
 * across the application source code (src/).
 *
 * Usage:
 *   php8.4 scripts/coverage.php                # Runs all test suites & generates combined + unit coverage
 *   php8.4 scripts/coverage.php --suite=unit   # Runs only tests/test_suite.php
 *   php8.4 scripts/coverage.php --suite=api    # Runs only tests/api_test_suite.php
 *   php8.4 scripts/coverage.php --suite=web    # Runs only tests/web_to_api_integration_test.php
 *   php8.4 scripts/coverage.php --suite=whatsapp # Runs only tests/whatsapp_test_suite.php
 *   php8.4 scripts/coverage.php --html         # Generates interactive HTML report in coverage/
 */

$projectRoot = realpath(__DIR__ . '/..');
$srcDir = $projectRoot . '/src';
$coverageDir = $projectRoot . '/coverage';

// Locate pcov extension
$pcovExt = null;
$possiblePcovPaths = [
    $projectRoot . '/scripts/ext/pcov.so',
    '/tmp/pcov/usr/lib/php/20240924/pcov.so',
    '/usr/lib/php/20240924/pcov.so'
];
foreach ($possiblePcovPaths as $p) {
    if (file_exists($p)) {
        $pcovExt = $p;
        break;
    }
}

// Find PHP 8.4 executable
$phpBin = '/usr/bin/php8.4';
if (!file_exists($phpBin)) {
    $phpBin = 'php8.4';
}

// Parse CLI options
$options = getopt('', ['suite:', 'html', 'json', 'help']);
if (isset($options['help'])) {
    echo "Usage: php8.4 scripts/coverage.php [--suite=unit|api|web|whatsapp|all] [--html] [--json]\n";
    exit(0);
}

$targetSuite = $options['suite'] ?? 'all';
$generateHtml = isset($options['html']) || $targetSuite === 'all';
$generateJson = isset($options['json']) || true;

$availableSuites = [
    'unit' => [
        'name' => 'Unit & Domain Test Suite',
        'file' => $projectRoot . '/tests/test_suite.php',
        'desc' => 'Core services, tenancy isolation, 4-step billing formula, quota guards'
    ],
    'api' => [
        'name' => 'REST API Test Suite',
        'file' => $projectRoot . '/tests/api_test_suite.php',
        'desc' => 'All API endpoints, bearer auth, rate limiting, and RBAC security'
    ],
    'web' => [
        'name' => 'Web-to-API Integration Suite',
        'file' => $projectRoot . '/tests/web_to_api_integration_test.php',
        'desc' => 'Web controllers delegating to internal REST APIs via ApiClient'
    ],
    'whatsapp' => [
        'name' => 'WhatsApp Notification Suite',
        'file' => $projectRoot . '/tests/whatsapp_test_suite.php',
        'desc' => 'Invoice messaging, UPI QR generator, async queue worker, anti-tamper bill links'
    ]
];

$suitesToRun = [];
if ($targetSuite === 'all') {
    $suitesToRun = $availableSuites;
} elseif (isset($availableSuites[$targetSuite])) {
    $suitesToRun[$targetSuite] = $availableSuites[$targetSuite];
} else {
    fwrite(STDERR, "Error: Unknown suite '{$targetSuite}'. Available: " . implode(', ', array_keys($availableSuites)) . "\n");
    exit(1);
}

// 1. Create temporary prepend script for PCOV
$tmpPrepend = sys_get_temp_dir() . '/doodhbee_pcov_prepend_' . getmypid() . '.php';
$prependCode = '<?php
if (function_exists("pcov\start")) {
    \pcov\start();
    register_shutdown_function(function() {
        \pcov\stop();
        $dump = getenv("PCOV_DUMP_FILE");
        if ($dump) {
            @file_put_contents($dump, json_encode(\pcov\collect()));
        }
    });
}
';
file_put_contents($tmpPrepend, $prependCode);

// 2. Build baseline executable line map for ALL files in src/
echo "======================================================================\n";
echo "           DOODHBEE CODE COVERAGE ANALYSIS ENGINE                    \n";
echo "======================================================================\n";
echo "• PHP Executable: {$phpBin}\n";
echo "• PCOV Extension: " . ($pcovExt ?: 'System / Built-in') . "\n";
echo "• Source Directory: {$srcDir}\n";
echo "• Scanning source files for baseline executable lines... ";

$baselineTmp = sys_get_temp_dir() . '/doodhbee_baseline_' . getmypid() . '.json';
$baselineScript = sys_get_temp_dir() . '/doodhbee_baseline_script_' . getmypid() . '.php';

$scriptCode = '<?php
require_once "' . $projectRoot . '/vendor/autoload.php";
\pcov\start();
$dir = new RecursiveDirectoryIterator("' . $srcDir . '", RecursiveDirectoryIterator::SKIP_DOTS);
$it = new RecursiveIteratorIterator($dir);
foreach ($it as $file) {
    if ($file->getExtension() === "php") {
        try {
            include_once $file->getPathname();
        } catch (\Throwable $e) {}
    }
}
\pcov\stop();
file_put_contents("' . $baselineTmp . '", json_encode(\pcov\collect()));
';
file_put_contents($baselineScript, $scriptCode);

$cmdPcovArgs = $pcovExt ? "-dextension={$pcovExt}" : "";
$cmd = sprintf(
    '%s %s -dpcov.enabled=1 -dpcov.directory=%s %s > /dev/null 2>&1',
    escapeshellarg($phpBin),
    $cmdPcovArgs,
    escapeshellarg($srcDir),
    escapeshellarg($baselineScript)
);
shell_exec($cmd);

$baselineMap = [];
if (file_exists($baselineTmp)) {
    $baselineMap = json_decode(file_get_contents($baselineTmp), true) ?: [];
    @unlink($baselineTmp);
}
@unlink($baselineScript);

echo "[" . count($baselineMap) . " files found]\n\n";

// 3. Execute each selected test suite and collect coverage
$suiteResults = [];
$suiteCoverageMaps = [];
$combinedCoverage = [];

foreach ($suitesToRun as $suiteKey => $suiteInfo) {
    echo "----------------------------------------------------------------------\n";
    echo "▶ Running Suite: {$suiteInfo['name']} ({$suiteKey})\n";
    echo "  File: {$suiteInfo['file']}\n";
    echo "  Desc: {$suiteInfo['desc']}\n";
    echo "----------------------------------------------------------------------\n";

    $dumpFile = sys_get_temp_dir() . '/doodhbee_cov_' . $suiteKey . '_' . getmypid() . '.json';
    $cmd = sprintf(
        'PCOV_DUMP_FILE=%s %s %s -dpcov.enabled=1 -dpcov.directory=%s -dauto_prepend_file=%s %s 2>&1',
        escapeshellarg($dumpFile),
        escapeshellarg($phpBin),
        $cmdPcovArgs,
        escapeshellarg($srcDir),
        escapeshellarg($tmpPrepend),
        escapeshellarg($suiteInfo['file'])
    );

    $startTime = microtime(true);
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $duration = round(microtime(true) - $startTime, 2);

    $outText = implode("\n", $output);
    // Extract passed / failed counts from test runner output
    $passed = 0;
    $failed = 0;
    if (preg_match('/(\d+)\s+PASSED,\s+(\d+)\s+FAILED/i', $outText, $matches)) {
        $passed = (int)$matches[1];
        $failed = (int)$matches[2];
    }

    echo "  Duration: {$duration}s | Status: " . ($exitCode === 0 ? "\033[32mSUCCESS\033[0m" : "\033[31mFAIL (code {$exitCode})\033[0m");
    echo " | Tests: {$passed} Passed, {$failed} Failed\n";

    $covData = [];
    if (file_exists($dumpFile)) {
        $covData = json_decode(file_get_contents($dumpFile), true) ?: [];
        @unlink($dumpFile);
    }
    $suiteCoverageMaps[$suiteKey] = $covData;
    $suiteResults[$suiteKey] = [
        'name' => $suiteInfo['name'],
        'duration' => $duration,
        'passed' => $passed,
        'failed' => $failed,
        'exitCode' => $exitCode,
        'filesCovered' => count($covData)
    ];

    // Merge into combined coverage
    foreach ($covData as $filePath => $lines) {
        if (!isset($combinedCoverage[$filePath])) {
            $combinedCoverage[$filePath] = [];
        }
        foreach ($lines as $lineNum => $status) {
            if ($status === 1) {
                $combinedCoverage[$filePath][$lineNum] = 1;
            } elseif (!isset($combinedCoverage[$filePath][$lineNum]) || $combinedCoverage[$filePath][$lineNum] !== 1) {
                $combinedCoverage[$filePath][$lineNum] = $status;
            }
        }
    }
}

@unlink($tmpPrepend);

// 4. Calculate coverage stats against baseline
function computeCoverageStats(array $rawCoverage, array $baselineMap, string $srcDir): array {
    $fileStats = [];
    $totalExecLines = 0;
    $totalCoveredLines = 0;

    // Iterate through all known source files in baselineMap
    foreach ($baselineMap as $filePath => $baseLines) {
        // Filter baseline lines to only executable lines (-1 or 1)
        $executableLines = [];
        foreach ($baseLines as $lineNo => $st) {
            if ($st === 1 || $st === -1) {
                $executableLines[$lineNo] = $lineNo;
            }
        }

        $lineCount = count($executableLines);
        if ($lineCount === 0) {
            continue;
        }

        $covered = 0;
        $uncoveredLines = [];
        $hasRun = isset($rawCoverage[$filePath]);

        if ($hasRun) {
            foreach ($executableLines as $lineNo) {
                $status = $rawCoverage[$filePath][$lineNo] ?? -1;
                if ($status === 1) {
                    $covered++;
                } else {
                    $uncoveredLines[] = $lineNo;
                }
            }
        } else {
            $uncoveredLines = array_values($executableLines);
        }

        $pct = $lineCount > 0 ? round(($covered / $lineCount) * 100, 1) : 0.0;
        $relPath = str_replace($srcDir . '/', '', $filePath);

        // Classify component / layer
        $component = 'Other';
        if (str_starts_with($relPath, 'Services/')) $component = 'Services';
        elseif (str_starts_with($relPath, 'Core/')) $component = 'Core Framework';
        elseif (str_starts_with($relPath, 'Controllers/Api/')) $component = 'API Controllers';
        elseif (str_starts_with($relPath, 'Controllers/')) $component = 'Web Controllers';
        elseif (str_starts_with($relPath, 'Enums/')) $component = 'Enums';
        elseif ($relPath === 'helpers.php') $component = 'Helpers';

        $fileStats[$relPath] = [
            'absolutePath' => $filePath,
            'relativePath' => $relPath,
            'component' => $component,
            'coveredLines' => $covered,
            'executableLines' => $lineCount,
            'coveragePct' => $pct,
            'uncoveredLines' => $uncoveredLines,
            'isCovered' => $hasRun && $covered > 0
        ];

        $totalExecLines += $lineCount;
        $totalCoveredLines += $covered;
    }

    // Sort by Component then relative path
    uasort($fileStats, function($a, $b) {
        if ($a['component'] === $b['component']) {
            return strcmp($a['relativePath'], $b['relativePath']);
        }
        return strcmp($a['component'], $b['component']);
    });

    $overallPct = $totalExecLines > 0 ? round(($totalCoveredLines / $totalExecLines) * 100, 1) : 0.0;

    // Component aggregations
    $components = [];
    foreach ($fileStats as $stat) {
        $c = $stat['component'];
        if (!isset($components[$c])) {
            $components[$c] = [
                'component' => $c,
                'filesCount' => 0,
                'coveredLines' => 0,
                'executableLines' => 0,
            ];
        }
        $components[$c]['filesCount']++;
        $components[$c]['coveredLines'] += $stat['coveredLines'];
        $components[$c]['executableLines'] += $stat['executableLines'];
    }
    foreach ($components as &$comp) {
        $comp['coveragePct'] = $comp['executableLines'] > 0
            ? round(($comp['coveredLines'] / $comp['executableLines']) * 100, 1)
            : 0.0;
    }
    unset($comp);

    return [
        'files' => $fileStats,
        'components' => $components,
        'totalExecutableLines' => $totalExecLines,
        'totalCoveredLines' => $totalCoveredLines,
        'overallCoveragePct' => $overallPct,
        'totalFiles' => count($fileStats)
    ];
}

// Compute stats
$unitStats = isset($suiteCoverageMaps['unit']) ? computeCoverageStats($suiteCoverageMaps['unit'], $baselineMap, $srcDir) : null;
$combinedStats = computeCoverageStats($combinedCoverage, $baselineMap, $srcDir);

// 5. Render CLI Table
function renderCliProgressBar(float $pct, int $width = 20): string {
    $filled = (int)round(($pct / 100) * $width);
    $empty = $width - $filled;
    $color = $pct >= 80 ? "\033[32m" : ($pct >= 50 ? "\033[33m" : "\033[31m");
    $reset = "\033[0m";
    return $color . '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']' . $reset;
}

function printCoverageReport(string $title, array $stats): void {
    echo "\n======================================================================\n";
    echo "  " . strtoupper($title) . "\n";
    echo "======================================================================\n";
    echo sprintf(
        "%-40s %-16s %-15s %s\n",
        "File / Class",
        "Coverage",
        "Lines",
        "Progress"
    );
    echo str_repeat('-', 90) . "\n";

    $currentComponent = '';
    foreach ($stats['files'] as $stat) {
        if ($stat['component'] !== $currentComponent) {
            $currentComponent = $stat['component'];
            $compData = $stats['components'][$currentComponent];
            $compPct = sprintf("%5.1f%%", $compData['coveragePct']);
            echo "\n\033[1;36m▶ {$currentComponent}\033[0m (Total: {$compPct} - {$compData['coveredLines']}/{$compData['executableLines']} lines, {$compData['filesCount']} files)\n";
        }

        $pctStr = sprintf("%5.1f%%", $stat['coveragePct']);
        $linesStr = sprintf("%d/%d", $stat['coveredLines'], $stat['executableLines']);
        $bar = renderCliProgressBar($stat['coveragePct'], 15);

        $color = $stat['coveragePct'] >= 80 ? "\033[32m" : ($stat['coveragePct'] >= 50 ? "\033[33m" : "\033[37m");
        if ($stat['coveredLines'] === 0) {
            $color = "\033[90m"; // Gray for 0%
        }
        $reset = "\033[0m";

        echo sprintf(
            "  %-38s %s%10s%s %15s  %s\n",
            $stat['relativePath'],
            $color,
            $pctStr,
            $reset,
            $linesStr,
            $bar
        );
    }

    echo "\n" . str_repeat('=', 90) . "\n";
    echo "LAYER / COMPONENT SUMMARY:\n";
    echo str_repeat('-', 90) . "\n";
    foreach ($stats['components'] as $comp) {
        $pctStr = sprintf("%5.1f%%", $comp['coveragePct']);
        $linesStr = sprintf("%d/%d", $comp['coveredLines'], $comp['executableLines']);
        $bar = renderCliProgressBar($comp['coveragePct'], 20);
        echo sprintf(
            "  %-25s %8s   %12s lines   %s\n",
            $comp['component'],
            $pctStr,
            $linesStr,
            $bar
        );
    }

    echo str_repeat('=', 90) . "\n";
    $overallBar = renderCliProgressBar($stats['overallCoveragePct'], 30);
    echo sprintf(
        "OVERALL CODE COVERAGE: \033[1;32m%.1f%%\033[0m (%d of %d executable lines covered across %d files)\n",
        $stats['overallCoveragePct'],
        $stats['totalCoveredLines'],
        $stats['totalExecutableLines'],
        $stats['totalFiles']
    );
    echo "Progress: {$overallBar}\n";
    echo str_repeat('=', 90) . "\n\n";
}

// Print reports
if ($unitStats !== null && $targetSuite === 'all') {
    printCoverageReport("1. Unit Tests Coverage (tests/test_suite.php)", $unitStats);
}

$mainReportTitle = $targetSuite === 'all'
    ? "Full Suite Combined Code Coverage (Unit + API + Web + WhatsApp)"
    : "Suite Code Coverage: " . ($availableSuites[$targetSuite]['name'] ?? $targetSuite);

printCoverageReport($mainReportTitle, $combinedStats);

// 6. Generate HTML Report in coverage/index.html
if ($generateHtml) {
    if (!is_dir($coverageDir)) {
        mkdir($coverageDir, 0777, true);
    }

    $htmlFile = $coverageDir . '/index.html';
    $jsonFile = $coverageDir . '/coverage.json';

    // Save JSON
    file_put_contents($jsonFile, json_encode([
        'generatedAt' => date('Y-m-d H:i:s'),
        'unitStats' => $unitStats,
        'combinedStats' => $combinedStats,
        'suites' => $suiteResults
    ], JSON_PRETTY_PRINT));

    // Build HTML
    $rowsHtml = '';
    foreach ($combinedStats['files'] as $stat) {
        $pct = $stat['coveragePct'];
        $badgeClass = $pct >= 80 ? 'bg-emerald-100 text-emerald-800' : ($pct >= 50 ? 'bg-amber-100 text-amber-800' : ($pct > 0 ? 'bg-orange-100 text-orange-800' : 'bg-gray-100 text-gray-500'));
        $barColor = $pct >= 80 ? 'bg-emerald-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-red-500');

        $uncoveredSample = '';
        if (!empty($stat['uncoveredLines'])) {
            $count = count($stat['uncoveredLines']);
            $firstFew = array_slice($stat['uncoveredLines'], 0, 8);
            $uncoveredSample = 'L' . implode(', L', $firstFew) . ($count > 8 ? " + " . ($count - 8) . " more" : '');
        } else {
            $uncoveredSample = '100% Covered';
        }

        $rowsHtml .= "
        <tr class='border-b hover:bg-slate-50 transition-colors'>
            <td class='py-3 px-4 text-xs font-semibold text-slate-500 uppercase'>{$stat['component']}</td>
            <td class='py-3 px-4 font-mono text-sm text-slate-800 font-medium'>{$stat['relativePath']}</td>
            <td class='py-3 px-4'>
                <div class='flex items-center gap-3'>
                    <div class='w-24 bg-slate-200 rounded-full h-2 overflow-hidden'>
                        <div class='{$barColor} h-2 rounded-full' style='width: {$pct}%'></div>
                    </div>
                    <span class='px-2 py-0.5 rounded text-xs font-bold {$badgeClass}'>{$pct}%</span>
                </div>
            </td>
            <td class='py-3 px-4 text-sm text-slate-600 text-right font-mono'>{$stat['coveredLines']} / {$stat['executableLines']}</td>
            <td class='py-3 px-4 text-xs text-slate-400 font-mono'>{$uncoveredSample}</td>
        </tr>";
    }

    $compCardsHtml = '';
    foreach ($combinedStats['components'] as $comp) {
        $pct = $comp['coveragePct'];
        $barColor = $pct >= 80 ? 'bg-emerald-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-red-500');
        $compCardsHtml .= "
        <div class='bg-white p-5 rounded-xl border border-slate-200 shadow-sm'>
            <div class='flex justify-between items-center mb-2'>
                <h3 class='text-sm font-semibold text-slate-700'>{$comp['component']}</h3>
                <span class='text-sm font-bold text-slate-900'>{$pct}%</span>
            </div>
            <div class='w-full bg-slate-100 rounded-full h-2 mb-3 overflow-hidden'>
                <div class='{$barColor} h-2 rounded-full' style='width: {$pct}%'></div>
            </div>
            <div class='flex justify-between text-xs text-slate-500 font-mono'>
                <span>{$comp['filesCount']} files</span>
                <span>{$comp['coveredLines']} / {$comp['executableLines']} lines</span>
            </div>
        </div>";
    }

    $suitesCardsHtml = '';
    foreach ($suiteResults as $k => $res) {
        $statusBadge = $res['exitCode'] === 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800';
        $suitesCardsHtml .= "
        <div class='p-4 bg-slate-50 rounded-lg border border-slate-200'>
            <div class='flex justify-between items-center mb-1'>
                <span class='font-bold text-slate-800 text-sm'>{$res['name']}</span>
                <span class='text-xs font-bold px-2 py-0.5 rounded {$statusBadge}'>" . ($res['exitCode'] === 0 ? 'PASSED' : 'FAILED') . "</span>
            </div>
            <div class='text-xs text-slate-500'>Tests: {$res['passed']} passed, {$res['failed']} failed | Time: {$res['duration']}s</div>
        </div>";
    }

    $htmlContent = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>DoodhBee SaaS - Code Coverage Report</title>
    <script src='https://cdn.tailwindcss.com'></script>
</head>
<body class='bg-slate-100 min-h-screen text-slate-800 antialiased'>
    <div class='max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8'>
        <!-- Header -->
        <div class='bg-white rounded-2xl p-8 border border-slate-200 shadow-sm mb-8'>
            <div class='flex flex-col md:flex-row md:items-center justify-between gap-6'>
                <div>
                    <div class='flex items-center gap-3'>
                        <span class='text-3xl font-black tracking-tight text-slate-900'>🥛 DoodhBee SaaS</span>
                        <span class='bg-indigo-100 text-indigo-800 text-xs font-semibold px-2.5 py-1 rounded-full'>Automated Verification</span>
                    </div>
                    <p class='text-slate-500 mt-2 text-sm'>Multi-Tenant Dairy Management Platform & API Engine Test Coverage</p>
                    <div class='flex items-center gap-4 text-xs text-slate-400 mt-3 font-mono'>
                        <span>Generated: " . date('Y-m-d H:i:s') . "</span>
                        <span>•</span>
                        <span>Engine: PHP 8.4 + PCOV</span>
                        <span>•</span>
                        <span>Source: src/ (44 modules)</span>
                    </div>
                </div>
                <div class='bg-slate-900 text-white rounded-xl p-5 flex items-center gap-6 shadow-md'>
                    <div>
                        <div class='text-xs text-slate-400 uppercase tracking-wider font-semibold'>Combined Coverage</div>
                        <div class='text-3xl font-black text-emerald-400 mt-1'>{$combinedStats['overallCoveragePct']}%</div>
                    </div>
                    <div class='border-l border-slate-700 pl-6 text-xs text-slate-300 font-mono space-y-1'>
                        <div>Covered: <strong class='text-white'>{$combinedStats['totalCoveredLines']}</strong></div>
                        <div>Executable: <strong class='text-white'>{$combinedStats['totalExecutableLines']}</strong></div>
                        <div>Modules: <strong class='text-white'>{$combinedStats['totalFiles']}</strong></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Suites Status Grid -->
        <div class='mb-8'>
            <h2 class='text-lg font-bold text-slate-900 mb-4'>Test Suites Executed</h2>
            <div class='grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4'>
                {$suitesCardsHtml}
            </div>
        </div>

        <!-- Component Architecture Coverage Breakdown -->
        <div class='mb-8'>
            <h2 class='text-lg font-bold text-slate-900 mb-4'>Architecture Layer Breakdown</h2>
            <div class='grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4'>
                {$compCardsHtml}
            </div>
        </div>

        <!-- Detailed Table -->
        <div class='bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden'>
            <div class='px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50'>
                <h2 class='text-base font-bold text-slate-900'>File-by-File Coverage Details</h2>
                <span class='text-xs text-slate-500 font-mono'>{$combinedStats['totalFiles']} files analyzed</span>
            </div>
            <div class='overflow-x-auto'>
                <table class='w-full text-left border-collapse'>
                    <thead>
                        <tr class='bg-slate-50/50 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wider'>
                            <th class='py-3 px-4'>Component</th>
                            <th class='py-3 px-4'>Source File</th>
                            <th class='py-3 px-4'>Line Coverage</th>
                            <th class='py-3 px-4 text-right'>Covered / Total</th>
                            <th class='py-3 px-4'>Uncovered Lines Sample</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rowsHtml}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>";

    file_put_contents($htmlFile, $htmlContent);
    echo "✓ Interactive HTML Coverage Report saved to: {$htmlFile}\n";
    echo "✓ Coverage JSON dataset saved to: {$jsonFile}\n";
}

echo "======================================================================\n";
