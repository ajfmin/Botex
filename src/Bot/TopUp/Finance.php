<?php

namespace Botex\Bot\TopUp;

use Botex\Model\TopUp;
use Botex\Repository\TopUpRepository;
use Botex\Service\WalletService;
use Illuminate\Support\Carbon;

/**
 * The financial report, as data.
 *
 * Deliberately not as text and not as HTML. The same figures are read
 * twice -- once into a Telegram screen an operator checks on a phone,
 * once into a web page with charts -- and a report that formats itself
 * can only be read the way it was written. So this answers in arrays and
 * lets each surface decide what a number should look like.
 *
 * One query per window, aggregated in PHP. The alternative is a GROUP BY
 * per figure and a date expression written twice for two database
 * engines, to summarise a set that comfortably fits in memory: a bot
 * whose customers top up by hand does not produce a month of rows a PHP
 * loop cannot hold.
 *
 * Every figure is bounded by a window. There is no all-time total here,
 * and that is a choice rather than an omission: it is the one number
 * that would oblige an install to keep every payment it ever took.
 */
class Finance
{
    /** Windows the report offers, in days. */
    public const TODAY = 1;

    public const WEEK = 7;

    public const MONTH = 30;

    public function __construct(
        private TopUpRepository $topups,
        private PaymentMethods $methods,
        private MethodState $state,
        private WalletService $wallet
    ) {
    }

    /**
     * Everything both surfaces need, from one read.
     *
     * @param  int $days how far back to look, today included
     * @return array{
     *     days: int,
     *     from: string,
     *     currency: string,
     *     windows: array<string, array{count:int, amount:int, customers:int}>,
     *     methods: array<string, array{key:string, title:string, count:int, amount:int, enabled:bool, registered:bool, configured:bool}>,
     *     daily: array<int, array{day:string, count:int, amount:int}>,
     *     average: int,
     *     largest: int,
     *     held: int,
     *     wallets: int
     * }
     */
    public function report(int $days = self::MONTH): array
    {
        $days = max(1, min(365, $days));
        $from = Carbon::now()->subDays($days - 1)->startOfDay();
        $rows = $this->topups->since($from);

        $walletStats = $this->walletStats();

        return [
            'days' => $days,
            'from' => $from->format('Y-m-d'),
            'currency' => $this->wallet->currency(),
            'windows' => [
                'today' => $this->tally($this->within($rows, self::TODAY)),
                'week' => $this->tally($this->within($rows, self::WEEK)),
                'window' => $this->tally($rows),
            ],
            'methods' => $this->byMethod($rows),
            'daily' => $this->daily($rows, $from),
            'average' => $this->average($rows),
            'largest' => $this->largest($rows),
            'held' => $walletStats['total'],
            'wallets' => $walletStats['wallets'],
        ];
    }

    /**
     * Per method, including ones that took money and are now switched off.
     *
     * A report that only listed what is currently registered would make
     * revenue vanish the moment an extension is disabled -- which is
     * exactly when an operator goes looking for it. So the ledger decides
     * who appears, and the registry only decides what is said about them.
     *
     * @param  array<TopUp> $rows
     * @return array<string, array{key:string, title:string, count:int, amount:int, enabled:bool, registered:bool, configured:bool}>
     */
    private function byMethod(array $rows): array
    {
        $methods = [];

        foreach ($this->methods->all() as $key => $_) {
            $instance = $this->methods->make($key);

            $methods[$key] = [
                'key' => $key,
                'title' => $instance === null ? $key : $instance::title(),
                'count' => 0,
                'amount' => 0,
                'enabled' => $this->state->isEnabled($key),
                'registered' => true,
                'configured' => $instance !== null && $instance->isConfigured(),
            ];
        }

        foreach ($rows as $row) {
            $key = (string) $row->method;

            $methods[$key] ??= [
                'key' => $key,
                'title' => $key,
                'count' => 0,
                'amount' => 0,
                'enabled' => $this->state->isEnabled($key),
                'registered' => false,
                'configured' => false,
            ];

            $methods[$key]['count']++;
            $methods[$key]['amount'] += (int) $row->amount;
        }

        // Biggest earner first; that is the order the question "where is
        // the money coming from" wants to be answered in.
        uasort($methods, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        return $methods;
    }

    /**
     * A row per calendar day, oldest first, empty days included.
     *
     * The gaps are the point: a chart drawn from only the days that had
     * a payment compresses a quiet week into one bar and lies about the
     * shape of the month.
     *
     * @param  array<TopUp> $rows
     * @return array<int, array{day:string, count:int, amount:int}>
     */
    private function daily(array $rows, Carbon $from): array
    {
        $buckets = [];

        for ($day = $from->copy(); $day <= Carbon::now(); $day->addDay()) {
            $buckets[$day->format('Y-m-d')] = ['count' => 0, 'amount' => 0];
        }

        foreach ($rows as $row) {
            $key = $row->created_at?->format('Y-m-d');

            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key]['count']++;
                $buckets[$key]['amount'] += (int) $row->amount;
            }
        }

        $daily = [];

        foreach ($buckets as $day => $bucket) {
            $daily[] = ['day' => $day, 'count' => $bucket['count'], 'amount' => $bucket['amount']];
        }

        return $daily;
    }

    /**
     * @param  array<TopUp> $rows
     * @return array<TopUp>
     */
    private function within(array $rows, int $days): array
    {
        $from = Carbon::now()->subDays($days - 1)->startOfDay();

        return array_values(array_filter(
            $rows,
            fn (TopUp $row) => $row->created_at !== null && $row->created_at >= $from
        ));
    }

    /**
     * @param  array<TopUp> $rows
     * @return array{count:int, amount:int, customers:int}
     */
    private function tally(array $rows): array
    {
        $amount = 0;
        $customers = [];

        foreach ($rows as $row) {
            $amount += (int) $row->amount;
            $customers[(int) $row->user_id] = true;
        }

        return [
            'count' => count($rows),
            'amount' => $amount,
            'customers' => count($customers),
        ];
    }

    /** @param array<TopUp> $rows */
    private function average(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return (int) round(array_sum(array_map(fn (TopUp $r) => (int) $r->amount, $rows)) / count($rows));
    }

    /** @param array<TopUp> $rows */
    private function largest(array $rows): int
    {
        $largest = 0;

        foreach ($rows as $row) {
            $largest = max($largest, (int) $row->amount);
        }

        return $largest;
    }

    /**
     * Held balance and wallet count, or zeroes.
     *
     * Read separately because it is the one figure here that is not a
     * top-up: it is what customers are holding now, which is what an
     * operator compares the month's intake against. Never fatal -- an
     * unmigrated wallet table must not take the report down.
     *
     * @return array{total:int, wallets:int}
     */
    private function walletStats(): array
    {
        try {
            $stats = $this->wallet->stats();

            return ['total' => (int) $stats['total'], 'wallets' => (int) $stats['wallets']];
        } catch (\Throwable) {
            return ['total' => 0, 'wallets' => 0];
        }
    }
}
