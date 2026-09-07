<?php

namespace Extensions\Feedback\Repository;

use Extensions\Feedback\Model\Response;

class ResponseRepository
{
    public function create(int $telegramId, int $rating, array $answers): Response
    {
        return Response::create([
            'telegram_id' => $telegramId,
            'rating' => $rating,
            'answers' => $answers,
        ]);
    }

    public function count(): int
    {
        return Response::count();
    }

    /** @return float|null null when nothing has been submitted yet */
    public function averageRating(): ?float
    {
        $average = Response::avg('rating');

        return $average === null ? null : round((float) $average, 2);
    }

    /** @return array<Response> newest first */
    public function latest(int $limit = 5): array
    {
        return Response::orderByDesc('id')->limit($limit)->get()->all();
    }
}
