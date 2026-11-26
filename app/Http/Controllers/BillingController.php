<?php

namespace App\Http\Controllers;

use App\Services\Credits\Ledger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    /** Credit packs on offer, in whole dollars. Purchases are simulated. */
    public const PACKS = [5, 10, 25];

    public function index(Request $request): Response
    {
        $transactions = $request->user()->creditTransactions()
            ->with('generation:id,uuid,prompt')
            ->latest('id')
            ->paginate(25)
            ->through(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'amount_nanos' => $t->amount_nanos,
                'balance_after_nanos' => $t->balance_after_nanos,
                'description' => $t->description,
                'generation' => $t->generation ? ['id' => $t->generation->uuid, 'prompt' => $t->generation->prompt] : null,
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        return Inertia::render('billing/Index', [
            'packs' => self::PACKS,
            'transactions' => $transactions,
        ]);
    }

    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $dollars = (int) $request->validate(['amount' => ['required', 'integer', Rule::in(self::PACKS)]])['amount'];

        $ledger->purchase($request->user(), $dollars * Money::NANOS_PER_DOLLAR);

        return back()->with('success', "Added \${$dollars}.00 in credits.");
    }
}
