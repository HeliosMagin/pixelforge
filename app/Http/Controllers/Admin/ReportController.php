<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Reports\SpendReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /** Pixelforge's /v1/usage accepts at most this many days per request. */
    private const MAX_DAYS = 92;

    public function __invoke(Request $request, SpendReport $report, PixelforgeClient $pixelforge): Response
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = CarbonImmutable::parse($validated['end'] ?? now('UTC')->toDateString(), 'UTC');
        $start = CarbonImmutable::parse($validated['start'] ?? $end->subDays(13)->toDateString(), 'UTC');

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        try {
            $usage = $pixelforge->usage($start->toDateString(), $end->toDateString());
            $usageError = null;
        } catch (PixelforgeException $e) {
            $usage = null;
            $usageError = "Couldn't load Pixelforge usage ({$e->errorCode}).";
        }

        return Inertia::render('admin/Report', [
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'report' => $report->build($start, $end, $usage),
            'usageError' => $usageError,
        ]);
    }
}
