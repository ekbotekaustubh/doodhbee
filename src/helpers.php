<?php
declare(strict_types=1);

use App\Core\Env;

// Global Helper Functions for DoodhBee SaaS

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function isDemoMode(): bool
{
    return filter_var(Env::get('ALLOW_DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN);
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatMoney(float|int|string|null $amount = 0, string $symbol = '₹'): string
{
    return $symbol . number_format((float)($amount ?? 0), 2);
}

function formatDate(?string $date, string $format = 'd M Y'): string
{
    if (empty($date)) {
        return '-';
    }
    return (new \DateTime($date))->format($format);
}

function url(string $path = '/'): string
{
    static $base = null;
    if ($base === null) {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($scriptName));
        $baseDir = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');

        // Check if index.php rewrite is supported or disabled
        $useIndexInUrl = filter_var(Env::get('APP_USE_INDEX_IN_URL', str_ends_with($scriptName, 'index.php') && !filter_var(Env::get('APP_REWRITE_ENABLED', false), FILTER_VALIDATE_BOOLEAN)), FILTER_VALIDATE_BOOLEAN);

        if ($useIndexInUrl && str_ends_with($scriptName, 'index.php')) {
            $base = $scriptName;
        } else {
            $base = $baseDir;
        }
    }
    $cleanPath = '/' . ltrim($path, '/');
    if ($cleanPath === '/') {
        return $base === '' ? '/' : $base;
    }
    return ($base === '' ? '' : $base) . $cleanPath;
}

function asset(string $path): string
{
    static $baseDir = null;
    static $docRootIsPublic = null;
    if ($baseDir === null) {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($scriptName));
        $baseDir = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');

        $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        $docRootIsPublic = str_ends_with(rtrim($docRoot, '/'), '/public') || str_ends_with(rtrim($dir, '/'), '/public');
    }

    $cleanPath = ltrim($path, '/');
    // If DocumentRoot points directly to public/, strip 'public/' prefix from asset path
    if ($docRootIsPublic && str_starts_with($cleanPath, 'public/')) {
        $cleanPath = substr($cleanPath, 7);
    }

    return ($baseDir === '' ? '' : $baseDir) . '/' . $cleanPath;
}

function isActiveRoute(string $prefix): bool
{
    $req = new \App\Core\Request();
    $current = $req->path();
    if ($prefix === '/' && $current === '/') {
        return true;
    }
    return $prefix !== '/' && str_starts_with($current, $prefix);
}

/**
 * @return string[]
 */
function parseMilkTypes(?string $types): array
{
    if (empty($types)) {
        return ['cow'];
    }
    $items = array_values(array_filter(array_map('trim', explode(',', $types))));
    return !empty($items) ? $items : ['cow'];
}

function formatMilkTypesBadge(?string $types): string
{
    $list = parseMilkTypes($types);
    $badges = [];
    foreach ($list as $t) {
        if ($t === 'cow') {
            $badges[] = '<span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full font-bold bg-amber-50 text-amber-900 border border-amber-300">🐄 Cow</span>';
        } elseif ($t === 'buffalo') {
            $badges[] = '<span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full font-bold bg-indigo-50 text-indigo-900 border border-indigo-300">🐃 Buffalo</span>';
        } else {
            $badges[] = '<span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full font-bold bg-slate-100 text-slate-800 border border-slate-300">' . e(ucfirst($t)) . '</span>';
        }
    }
    return implode(' ', $badges);
}

function formatMilkTypeText(?string $types): string
{
    $list = parseMilkTypes($types);
    if (empty($list)) {
        return 'Cow Milk';
    }
    $labels = [];
    foreach ($list as $t) {
        if ($t === 'cow') {
            $labels[] = 'Cow Milk';
        } elseif ($t === 'buffalo') {
            $labels[] = 'Buffalo Milk';
        } else {
            $labels[] = ucfirst($t) . ' Milk';
        }
    }
    return implode(' & ', $labels);
}

/**
 * Builds a 1-to-N calendar matrix of days for an invoice, marking each date with its milk quantity and status.
 *
 * @param array $breakdown The invoice breakdown data (from breakdown_json or BillingService)
 * @param int $year Billing year
 * @param int $month Billing month
 * @return array{matrix: array<int, array>, first_day_of_week: int, days_in_month: int}
 */
function buildCalendarMatrix(array $breakdown, int $year, int $month): array
{
    if (!empty($breakdown['calendar_matrix']) && !empty($breakdown['first_day_of_week'])) {
        return [
            'matrix' => $breakdown['calendar_matrix'],
            'first_day_of_week' => (int)$breakdown['first_day_of_week'],
            'days_in_month' => (int)($breakdown['days_in_month'] ?? count($breakdown['calendar_matrix'])),
        ];
    }

    $daysInMonth = (int)($breakdown['days_in_month'] ?? cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $firstDayOfMonth = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
    $firstDayOfWeek = (int)$firstDayOfMonth->format('N'); // 1 = Mon, 7 = Sun

    // Index existing itemized deliveries by date
    $itemized = $breakdown['itemized_deliveries'] ?? [];
    $byDate = [];
    foreach ($itemized as $it) {
        $dStr = $it['date'] ?? '';
        if (!empty($dStr)) {
            if (!isset($byDate[$dStr])) {
                $byDate[$dStr] = [];
            }
            $byDate[$dStr][] = $it;
        }
    }

    $matrix = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $curDate = new \DateTime($dStr);
        $dayOfWeek = (int)$curDate->format('N');
        $dayDeliveries = $byDate[$dStr] ?? [];

        $dayQty = 0.0;
        $dayStatus = 'unrecorded';
        $notes = [];
        $shifts = [];

        if (!empty($dayDeliveries)) {
            $hasDelivered = false;
            $hasSkipped = false;
            $hasLeave = false;

            foreach ($dayDeliveries as $item) {
                $st = $item['status'] ?? '';
                $q = (float)($item['quantity'] ?? 0);
                $shift = $item['shift'] ?? 'morning';
                $shifts[$shift] = ['status' => $st, 'quantity' => $q];

                if (!empty($item['notes'])) {
                    $notes[] = $item['notes'];
                }

                if ($st === 'delivered') {
                    $hasDelivered = true;
                    $dayQty += $q;
                } elseif ($st === 'skipped') {
                    $hasSkipped = true;
                } elseif ($st === 'leave') {
                    $hasLeave = true;
                }
            }

            if ($hasDelivered) {
                $dayStatus = 'delivered';
            } elseif ($hasSkipped) {
                $dayStatus = 'skipped';
                $dayQty = 0.0;
            } elseif ($hasLeave) {
                $dayStatus = 'leave';
                $dayQty = 0.0;
            }
        }

        $matrix[$d] = [
            'day' => $d,
            'date' => $dStr,
            'day_of_week' => $dayOfWeek,
            'day_name' => $curDate->format('D'),
            'is_weekend' => $dayOfWeek >= 6,
            'quantity' => round($dayQty, 2),
            'status' => $dayStatus, // 'delivered', 'skipped', 'leave', 'unrecorded'
            'shifts' => $shifts,
            'notes' => implode(', ', $notes),
        ];
    }

    return [
        'matrix' => $matrix,
        'first_day_of_week' => $firstDayOfWeek,
        'days_in_month' => $daysInMonth,
    ];
}
