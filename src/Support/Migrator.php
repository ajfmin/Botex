<?php

namespace Botex\Support;

use Botex\Bot\Action\ActionStore;
use Botex\Bot\Action\Run;
use Botex\Bot\Conversation\Store;
use Botex\Bot\Job\WorkerLease;

/**
 * Core tables. Idempotent, so running migrate repeatedly is harmless
 * and an existing install picks up only what it is missing.
 */
class Migrator
{
    /** @return array<string> tables that were created */
    public static function run(): array
    {
        $created = [];

        if (Schema::createIfMissing('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('phone_number')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        })) {
            $created[] = 'users';
        }

        // Added after users, so an existing install may lack the column.
        if (!Schema::hasColumn('users', 'status')) {
            Schema::addMissing('users', function ($table) {
                $table->string('status')->default('active');
            });

            $created[] = 'users.status';
        }

        if (!Schema::hasTable('conversations')) {
            Store::migrate();
            $created[] = 'conversations';
        }

        if (!Schema::hasTable('run_actions')) {
            ActionStore::migrate();
            $created[] = 'run_actions';
        }

        // Added when a Run target could first be a command as well as a
        // runnable, so an existing install may lack the column. Defaulting
        // to an action is what every row was before it existed.
        if (!Schema::hasColumn('run_actions', 'target')) {
            Schema::addMissing('run_actions', function ($table) {
                $table->string('target', 16)->default(Run::ACTION);
            });

            $created[] = 'run_actions.target';
        }

        if (Schema::createIfMissing('wallets', function ($table) {
            $table->id();
            // One wallet per user, enforced by the database rather than by
            // a check in PHP that two concurrent first-time credits could
            // both pass.
            $table->unsignedBigInteger('user_id')->unique();
            // Minor units. Never a float, never negative.
            $table->bigInteger('balance')->default(0);
            $table->string('currency', 8)->default('IRT');
            $table->timestamps();
        })) {
            $created[] = 'wallets';
        }

        if (Schema::createIfMissing('wallet_transactions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('wallet_id');
            // Denormalized so history can be read by internal user id
            // without a join.
            $table->unsignedBigInteger('user_id');
            $table->string('type', 16);
            // Signed, so SUM(amount) must equal wallets.balance. That is
            // the reconciliation check the tests assert.
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reason');
            // Generic pointer an extension uses to link an entry to its own
            // order or entity, without the wallet knowing those tables.
            $table->string('reference_type', 191)->nullable();
            $table->string('reference_id', 191)->nullable();
            // Set only on refunds, pointing at the debit being reversed.
            $table->unsignedBigInteger('refunds_transaction_id')->nullable();
            // Unique where present; NULLs repeat freely, so a key is
            // optional per operation.
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->text('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'id']);
            $table->index(['user_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('refunds_transaction_id');
        })) {
            $created[] = 'wallet_transactions';
        }

        if (Schema::createIfMissing('jobs', function ($table) {
            $table->id();
            // Optional dedupe name. Unique where present, so scheduling a
            // recurring job at every boot re-arms one row instead of
            // stacking up a copy per restart. NULLs repeat freely.
            $table->string('key', 191)->nullable()->unique();
            // Slug + job name, resolved through the Jobs allowlist. Never a
            // class name: a tampered row must not be able to name an
            // arbitrary class.
            $table->string('extension', 191);
            $table->string('job', 191);
            $table->text('data')->nullable();
            $table->string('schedule_type', 16);
            // Null for one-shot jobs.
            $table->unsignedInteger('interval')->nullable();
            $table->string('status', 16)->default('pending');
            // Null means nothing scheduled, which is how a paused job stays
            // invisible to the worker's due query.
            $table->timestamp('next_run_at')->nullable();
            // 0 means forever.
            $table->unsignedInteger('max_runs')->default(1);
            $table->unsignedInteger('runs')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->unsignedInteger('failures')->default(0);
            // Which worker holds this job, and until when. A lapsed lease
            // means that worker died and the job may be reclaimed.
            $table->string('locked_by', 64)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            // The worker's hot path: due rows in schedule order.
            $table->index(['status', 'next_run_at']);
            $table->index(['extension', 'job']);
            $table->index('lease_until');
        })) {
            $created[] = 'jobs';
        }

        if (!Schema::hasTable(WorkerLease::TABLE)) {
            WorkerLease::migrate();
            $created[] = WorkerLease::TABLE;
        }

        return $created;
    }
}
