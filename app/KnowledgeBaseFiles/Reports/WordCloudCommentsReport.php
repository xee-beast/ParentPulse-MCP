<?php

namespace App\Reports;

use App\Imports\Tenant\ImportBlockedWords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\{Collection, Facades\DB, Str};

class WordCloudCommentsReport
{
    protected Collection $blockedWords;

    public function __construct(
        private string $period,
        private string $custom_date = '',
        private array $filters,
        private array $filterPerQuestions,
        private array $tagged_filter = [],
         public ?string $search = null,
         private array $module_filters,
    private array $custom_nps_filter = [],
    private array $surveyProgressFilter = [],
    private array $selectedQuestion = []
    ) {
        $this->blockedWords = (new ImportBlockedWords())->records()->map(fn ($word) => Str::lower($word));
    }

    public function run(): array
    {
        $wordCloudQuery = DB::query()
            ->select(DB::raw('CASE 
                WHEN lng_main IS NOT NULL THEN value_en 
                ELSE value 
            END as value'))
            ->from($this->commentsQuery()->groupBy(
//                'people_name',
//                'student_name',
////                'type',
//                DB::raw('DATE(survey_answers.updated_at)')
            ))
            ->pluck('value')
            ->reduce(function (Collection $carry, $item) {
                /** Collection $words */
                $words = collect(
                        Str::of($item)->explode(' ')->toArray()
                    )->map(fn ($word) => $this->formattedWord($word));

                $words = $words->filter(fn($word) => !$this->blockedWords->contains($word));

                foreach ($words as $word) {

                    $count = $carry->get($word, 0);

                    $carry->put($word, $count + 1);
                }

                return $carry;
            }, collect())
            ->sortDesc()
            ->map(fn ($count, $word) => [$word, $count])
            ->values()
            ->all();
        return $wordCloudQuery;
    }

    private function commentsQuery(): Builder
    {
        $commentsQuery =  CommentsReport::query($this->filters,false,$this->surveyProgressFilter)
            ->when(isSequence($this->period), function ($query) {
                $query->sequenceFilter($this->period);
            }, function ($query) {
                $query->periodFilter($this->period, $this->custom_date);
            })
            ->comments()
            ->with(['tags'])
            ->when($this->custom_nps_filter, function (Builder $query) {
                $filtersToString = collect($this->custom_nps_filter)->toStringImploded();

                return $query->havingRaw("type in ({$filtersToString})");

            })->when($this->module_filters, function (Builder $query) {
                $filtersToStringModule = collect($this->module_filters)->toStringImploded();

                return $query->havingRaw("module_type in ({$filtersToStringModule})");
            })
            ->when($this->search, function ($query){

                $query->where('survey_answers.value','LIKE','%'.$this->search.'%');
            })
            ->when($this->selectedQuestion, function($query) {
                $query->where(function($subQuery) {
                    foreach($this->selectedQuestion as $question) {
                        $selectedQuestion = explode('.', $question);
                        $subQuery->orWhere(function($q) use ($selectedQuestion) {
                            $q->where('survey_answers.questionable_type', '=', $selectedQuestion[0])
                              ->where('survey_answers.questionable_id', '=', $selectedQuestion[1]);
                        });
                    }
                });
            })
            ->when($this->tagged_filter, function ($query){

                $query->WhereHas('tags', function($q)  {
                    return $q->whereIn('tags.id', $this->tagged_filter );
                });
            })
            ->when($this->filterPerQuestions, function (Builder $query) {
                return $query->whereIn('survey_answers.questionable_id', $this->filterPerQuestions);
            });


        return $commentsQuery;
    }

    private function formattedWord(string $word): string
    {
        return preg_replace('#[!?;:,.]#',"", Str::lower($word));
    }
}
