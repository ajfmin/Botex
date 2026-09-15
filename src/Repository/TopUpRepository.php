<?php

namespace Botex\Repository;

use Botex\Model\TopUp;
use Illuminate\Support\Carbon;

/**
 * Reads over the top-up ledger.
 *
 * Every figure the financial report shows comes from here, and every one
 * of them is bounded by a window: a report over all of history is a
 * report whose cost grows forever, and nobody acts on a number that
 * includes a customer from three years ago.
 */
class TopUpRepository
{
    public function create(array $attributes): TopUp
    {
        return TopUp::create($attributes);
    }

    public function find(int $id): ?TopUp
    {
        return TopUp::find($id);
    }

    /**
     * Rows in a window, newest last.
     *
     * Only the columns the report reads, so a wide `meta` payload does
     * not travel for a figure nobody is going to show.
     *
     * @return array<TopUp>
     */
    public function since(Carbon $from): array
    {
        return TopUp::where('created_at', '>=', $from)
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'method', 'amount', 'created_at'])
            ->all();
    }

    /** @return array<TopUp> */
    public function latest(int $limit = 20): array
    {
        return TopUp::orderByDesc('id')->limit($limit)->get()->all();
    }

    public function countSince(Carbon $from): int
    {
        return TopUp::where('created_at', '>=', $from)->count();
    }

    public function sumSince(Carbon $from): int
    {
        return (int) TopUp::where('created_at', '>=', $from)->sum('amount');
    }

    /** Whether this method has ever taken money, so a toggle can warn. */
    public function usedBy(string $method): bool
    {
        return TopUp::where('method', $method)->exists();
    }
}
