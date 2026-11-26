<?php

namespace App\Services\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Credit spend, provider cost and profit per UTC day and per user.
 *
 * Generations are bucketed by the UTC day they completed, which is how Pixelforge bills
 * (GET /v1/usage), so our provider cost per day can be compared with theirs directly.
 */
class SpendReport
{
    /**
     * @param  array<string, mixed>|null  $usage  body of GET /v1/usage for the same range, if available
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $start, CarbonImmutable $end, ?array $usage): array
    {
        $rows = Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereBetween('completed_at', [$start->startOfDay(), $end->endOfDay()])
            ->groupBy('user_id', DB::raw('DATE(completed_at)'))
            ->select([
                'user_id',
                DB::raw('DATE(completed_at) as day'),
                DB::raw('COUNT(*) as generations'),
                DB::raw('SUM(provider_cost_nanos) as provider_nanos'),
                DB::raw('SUM(charged_nanos) as charged_nanos'),
            ])
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'day' => substr((string) $row->day, 0, 10),
                'generations' => (int) $row->generations,
                'provider_nanos' => (int) $row->provider_nanos,
                'charged_nanos' => (int) $row->charged_nanos,
            ]);

        $pixelforgeByDay = collect($usage['data'] ?? [])->mapWithKeys(fn ($d) => [$d['date'] => [
            'generations' => (int) $d['generations'],
            'cost_nanos' => Money::decimalToNanos($d['cost_usd']),
        ]]);

        $days = collect(CarbonPeriod::create($start, $end))->map(fn ($date) => $date->toDateString())->reverse()->values();
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');

        return [
            'days' => $days->map(function (string $day) use ($rows, $pixelforgeByDay, $users) {
                $dayRows = $rows->where('day', $day);
                $reported = $pixelforgeByDay->get($day);

                return [
                    'date' => $day,
                    ...$this->totals($dayRows),
                    'pixelforge_generations' => $reported['generations'] ?? null,
                    'pixelforge_cost_nanos' => $reported['cost_nanos'] ?? null,
                    'users' => $dayRows->map(fn ($row) => [
                        'user' => $this->userSummary($users->get($row['user_id'])),
                        ...$this->totals(collect([$row])),
                    ])->sortByDesc('charged_nanos')->values(),
                ];
            }),
            'users' => $rows->groupBy('user_id')->map(fn ($userRows, $userId) => [
                'user' => $this->userSummary($users->get($userId)),
                ...$this->totals($userRows),
            ])->sortByDesc('charged_nanos')->values(),
            'total' => [
                ...$this->totals($rows),
                'pixelforge_generations' => $usage['total']['generations'] ?? null,
                'pixelforge_cost_nanos' => isset($usage['total']['cost_usd']) ? Money::decimalToNanos($usage['total']['cost_usd']) : null,
            ],
        ];
    }

    /** @return array{generations: int, charged_nanos: int, provider_nanos: int, profit_nanos: int} */
    private function totals($rows): array
    {
        $charged = (int) $rows->sum('charged_nanos');
        $provider = (int) $rows->sum('provider_nanos');

        return [
            'generations' => (int) $rows->sum('generations'),
            'charged_nanos' => $charged,
            'provider_nanos' => $provider,
            'profit_nanos' => $charged - $provider,
        ];
    }

    private function userSummary(?User $user): array
    {
        return ['id' => $user?->id, 'name' => $user?->name ?? 'Deleted user', 'email' => $user?->email];
    }
}
