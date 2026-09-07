<?php

namespace Extensions\Feedback;

use Botex\Extension\AbstractExtension;
use Botex\Support\Schema;
use Extensions\Feedback\Admin\FeedbackSection;
use Extensions\Feedback\Callback\AddQuestion;
use Extensions\Feedback\Callback\ManageQuestion;
use Extensions\Feedback\Command\Feedback;
use Extensions\Feedback\Flow\AddQuestionFlow;
use Extensions\Feedback\Flow\FeedbackFlow;

/**
 * Uses every extension hook: commands, callbacks, flows, an admin
 * section, and its own tables.
 */
class Extension extends AbstractExtension
{
    public static function commands(): array
    {
        return [
            Feedback::class,
        ];
    }

    public static function callbacks(): array
    {
        return [
            AddQuestion::class,
            ManageQuestion::class,
        ];
    }

    public static function flows(): array
    {
        return [
            FeedbackFlow::class,
            AddQuestionFlow::class,
        ];
    }

    public static function adminSections(): array
    {
        return [
            FeedbackSection::class,
        ];
    }

    /** Idempotent, as the interface requires. */
    public static function install(): void
    {
        Schema::createIfMissing('feedback_questions', function ($table) {
            $table->id();
            $table->string('text');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::createIfMissing('feedback_responses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->index();
            $table->unsignedTinyInteger('rating');
            $table->text('answers')->nullable();
            $table->timestamps();
        });
    }

    public static function uninstall(): void
    {
        Schema::dropIfExists('feedback_responses');
        Schema::dropIfExists('feedback_questions');
    }
}
