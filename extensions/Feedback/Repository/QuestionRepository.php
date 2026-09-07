<?php

namespace Extensions\Feedback\Repository;

use Extensions\Feedback\Model\Question;

class QuestionRepository
{
    /** @return array<Question> active questions in admin-defined order */
    public function active(): array
    {
        return Question::where('active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return array<Question> */
    public function all(): array
    {
        return Question::orderBy('position')->orderBy('id')->get()->all();
    }

    public function find(int $id): ?Question
    {
        return Question::find($id);
    }

    public function add(string $text): Question
    {
        return Question::create([
            'text' => $text,
            'position' => (int) Question::max('position') + 1,
            'active' => true,
        ]);
    }

    public function delete(int $id): bool
    {
        return Question::where('id', $id)->delete() > 0;
    }

    public function toggle(int $id): ?Question
    {
        $question = $this->find($id);

        if (!$question) {
            return null;
        }

        $question->active = !$question->active;
        $question->save();

        return $question;
    }

    public function count(): int
    {
        return Question::count();
    }
}
