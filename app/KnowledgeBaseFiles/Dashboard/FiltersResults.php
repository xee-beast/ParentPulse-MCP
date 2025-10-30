<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use App\Models\Tenant\SurveyAnswer;
use Illuminate\Support\Facades\DB;
use App\Models\{Question, Tenant};
use App\Scopes\PeriodScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * @property-read Collection $filteringAnswers
 * @property-read Collection $previousFilteringAnswers
 */
class FiltersResults extends Component
{
    public ?SurveyAnswer $surveyAnswer = null;

    public bool $isActiveSurvey = true;

    public bool $modal = false;

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $module_type = 'parent';

    public array $filters = [];
    public bool $is_custom_nps_filter  = false;
    public array $custom_nps_filter = [];
    public array $surveyProgressFilter = [];

    protected $listeners = [
        'results::open-filters' => 'open',
        'filters::surveys'      => 'setIsActiveSurveys',
        'filters::period'       => 'setPeriod',
        'filters::apply'        => 'setFilters',
    ];

    public function getFilteringAnswersProperty(): Collection
    {


        $filteringAnswers =  $this->getBaseQuery()
                    ->periodFilter($this->period,$this->custom_date);

        if (count($this->surveyProgressFilter)) {
            $filteringAnswers = $this->modifyBaseQueryOnSurveyProgressFilter($filteringAnswers);   
        }
        if(count($this->custom_nps_filter))
        {
            return  $this->modifyBaseQueryonNps($filteringAnswers,false);
        }
        return $filteringAnswers->get();


    }

    public function getPreviousFilteringAnswersProperty(): Collection
    {
        $previous_filtering_answers =  $this->getBaseQuery()
                    ->previousPeriodFilter($this->period,$this->custom_date);

        if(count($this->custom_nps_filter))
        {
            return  $this->modifyBaseQueryonNps($previous_filtering_answers,true);
        }
        return $previous_filtering_answers->get();
    }

    public function getSurveysWithSurveyAnswerProperty(): Collection
    {

        $survey_with_survey_answers =  SurveyAnswer::query()
             ->selectRaw(<<<SQL
                 survey_answers.*
             SQL)
            ->whereQuestionableType($this->surveyAnswer->questionable_type)
            ->whereQuestionableId($this->surveyAnswer->questionable_id)
            ->where('value', "!=", "[]")
            ->module($this->module_type)
            ->periodFilter($this->period,$this->custom_date)
            ->filterAnswers($this->filters)
            ->get();
        return $survey_with_survey_answers;
    }

    public function getLastPeriodSurveysWithSurveyAnswerProperty(): Collection
    {
        return SurveyAnswer::query()
             ->selectRaw(<<<SQL
                 survey_answers.*,
                 CASE
                     WHEN survey_answers.value >= 9 THEN 'promoter'
                     WHEN survey_answers.value BETWEEN 7 AND 8 THEN 'passive'
                     WHEN survey_answers.value <= 6 THEN 'detractor'
                     ELSE 'unknown'
                 END as type
             SQL)
            ->whereQuestionableType($this->surveyAnswer->questionable_type)
            ->whereQuestionableId($this->surveyAnswer->questionable_id)
            ->where('value', "!=", "[]")
            ->module($this->module_type)
            ->previousPeriodFilter($this->period,$this->custom_date)
            ->filterAnswers($this->filters)
            ->get();
    }

    public function getSurveyAnswerOptionsProperty(): Collection
    {
        if($this->surveyAnswer->isRankOrder())
        {
            $responses = $this->surveyAnswer->questionable_type::whereId($this->surveyAnswer->questionable_id)
                ->whereType($this->surveyAnswer->question_type)
                ->with(['answers' => function($query) {
                    $query->orderBy('order'); 
                }])
                ->first()->answers;
            return  $responses->pluck('name');
        }else
        {
            $responses = $this->surveysWithSurveyAnswer->pluck('value');
            $responses = clone $responses->map(function ($response) {
                    return json_decode($response);
                });
            return $responses->flatten()->unique();
        }
    }

    public function getPercentage(Collection $surveys, string $filterValue, string $surveyAnswerOptionValue)
    {
        if ($surveys->isEmpty()) return 0;
        
        $surveysWithFilterValue = $surveys->filter(fn($survey) => $survey->value->contains($filterValue));
        
        if($this->surveyAnswer->isRankOrder())
        {
            $options = json_decode($this->surveyAnswer->value) ?? 1;    
            $invites = $surveysWithFilterValue->pluck('survey_invite_id');
            $answers = $this->surveysWithSurveyAnswer
                ->whereIn('survey_invite_id', $invites)
                ->pluck('value')
                ->map(function ($value) {
                    return collect(json_decode($value));
                });
            $weightage =  _getRankOrderCount($answers, $surveyAnswerOptionValue,true);
            $percentage = ($weightage / count($options) * 100);
            $weightage = number_format($weightage, 1, '.', '');
             return [
                 'weightage' => $weightage,
                 'percentage' => $percentage
             ];
        }
        $surveysWithsurveyAnswerOptionValue = $this->surveysWithSurveyAnswer->filter(fn($survey) => collect(json_decode($survey->value))->contains($surveyAnswerOptionValue));

        $allSurveys = $surveysWithFilterValue->pluck('survey_invite_id')->merge($surveysWithsurveyAnswerOptionValue->pluck('survey_invite_id'));
        $duplicatesSurveys = $allSurveys->duplicates();

        if($surveysWithFilterValue->isEmpty()) {
            return 0;
        }

        return round($duplicatesSurveys->count() / $surveysWithFilterValue->count() * 100);
    }

    public function getLastPeriodPercentage(Collection $surveys, string $filterValue, string $surveyAnswerOptionValue)
    {
        if ($surveys->isEmpty()) return 0;

        $surveysWithFilterValue = $surveys->filter(fn($survey) => $survey->value->contains($filterValue));
        $surveysWithsurveyAnswerOptionValue = $this->lastPeriodSurveysWithSurveyANswer->filter(fn($survey) => collect(json_decode($survey->value))->contains($surveyAnswerOptionValue));

        $allSurveys = $surveysWithFilterValue->pluck('survey_invite_id')->merge($surveysWithsurveyAnswerOptionValue->pluck('survey_invite_id'));
        $duplicatesSurveys = $allSurveys->duplicates();

        if($surveysWithFilterValue->isEmpty()) {
            return 0;
        }

        return round($duplicatesSurveys->count() / $surveysWithFilterValue->count() * 100);
    }

    public function getSurveysOfFilteringQuestion(SurveyAnswer $filteringAnswer)
    {
        return SurveyAnswer::query()
            ->whereIn('survey_invite_id', $this->surveysWithSurveyAnswer->pluck('survey_invite_id'))
            ->where('value', "!=", "[]")
            ->module($this->module_type)
            ->whereQuestionableId($filteringAnswer->questionable_id)
            ->whereQuestionableType($filteringAnswer->questionable_type)
            ->get();
    }

    public function getLastPeriodSurveysOfFilteringQuestion(SurveyAnswer $filteringAnswer)
    {
        return SurveyAnswer::query()
            ->whereIn('survey_invite_id', $this->lastPeriodSurveysWithSurveyAnswer->pluck('survey_invite_id'))
            ->where('value', "!=", "[]")
            ->module($this->module_type)
            ->whereQuestionableId($filteringAnswer->questionable_id)
            ->whereQuestionableType($filteringAnswer->questionable_type)
            ->get();
    }

    public function getAnswerScoreByFilterQuestion(string $questionType, int $questionableId ,string $questionFilter)
    {
        $invitesId = SurveyAnswer::whereQuestionableType($questionType)
            ->whereQuestionableId($questionableId)
            ->where('value', 'like', "%\"$questionFilter\"%")
            ->where('value', 'not like', 'N/A')
            ->where('value','!=','')
            ->module($this->module_type)
            ->periodFilter($this->period,$this->custom_date)
            ->pluck('survey_invite_id');
        
        $average   = SurveyAnswer::whereIn('survey_invite_id', $invitesId)
            ->whereQuestionableId($this->surveyAnswer->questionable_id)
            ->whereQuestionableType($this->surveyAnswer->questionable_type)
            ->where('value', 'not like', 'N/A')
            ->where('value','!=','')
            ->where('question_type','<>',Question::RATING_GRID)
            ->module($this->module_type)
            ->periodFilter($this->period,$this->custom_date)
            ->get();
            if(count($average) > 0)
            {
               $average = $average->avg('value');
            }else
            {
                $average = null;
            }      
        return is_null($average) ? 'N/A' : $average * 10;
    }

    public function getPriorAnswerScoreByFilterQuestion(string $questionType, int $questionableId, string $questionFilter)
    {
        $invitesId = SurveyAnswer::whereQuestionableType($questionType)
            ->whereQuestionableId($questionableId)
            ->where('value', 'like', "%\"{$questionFilter}\"%")
            ->module($this->module_type)
            ->previousPeriodFilter($this->period,$this->custom_date)
            ->pluck('survey_invite_id');

        $average   = SurveyAnswer::whereIn('survey_invite_id', $invitesId)
            ->whereQuestionableId($this->surveyAnswer->questionable_id)
            ->whereQuestionableType($this->surveyAnswer->questionable_type)
            ->where('value', 'not like', 'N/A')
            ->whereRaw('value REGEXP "^-?[0-9]+(\.[0-9]+)?$"') // Only select numeric values
            ->module($this->module_type)
            ->previousPeriodFilter($this->period,$this->custom_date)
            ->get();
        if(count($average) > 0)
        {
           $average = $average->avg('value');
        }else
        {
            $average = null;
        }        

        return is_null($average) ? 'N/A' : $average * 10;
    }

    private function getBaseQuery(): Builder
    {

        return SurveyAnswer::query()
            ->selectRaw(
                <<<SQL
                    MIN(survey_answers.id) as id,
                    survey_answers.questionable_id,
                    CONCAT( GROUP_CONCAT(survey_invite_id)) as invites,
                    survey_answers.questionable_type,
                    CONCAT('[', GROUP_CONCAT(value), ']') as answers
                SQL
            )
            ->where('questionable_id', '!=', $this->surveyAnswer->questionable_id)
            ->where(function (Builder $query) {
                return $query->whereExists(function (Query\Builder $query) {
                    return $query
                        ->selectRaw(1)
                        ->fromCentral('questions')
                        ->whereColumn('survey_answers.questionable_id', 'questions.id')
                        ->where('survey_answers.questionable_type', Question::class)
                        ->where('questions.type', Question::FILTERING);
                })->orWhereExists(function (Query\Builder $query) {
                    return $query
                        ->selectRaw(1)
                        ->fromTenant('questions')
                        ->whereColumn('survey_answers.questionable_id', 'questions.id')
                        ->where('survey_answers.questionable_type', Tenant\Question::class)
                        ->where('questions.type', Tenant\Question::FILTERING);
                });
            })
            ->whereExists(function (Query\Builder $query) {
                return $query
                    ->selectRaw(1)
                    ->fromTenant('survey_answers as sub')
                    ->whereColumn('survey_answers.survey_invite_id', 'sub.survey_invite_id')
                    ->where('sub.questionable_type', $this->surveyAnswer->questionable_type)
                    ->where('sub.questionable_id', $this->surveyAnswer->questionable_id);
            })
            ->when($this->isActiveSurvey, fn(Builder $query) => $query
                ->whereExists(function (Query\Builder $query) {
                    return $query
                        ->selectRaw(1)
                        ->fromCentral('questions')
                        ->whereColumn('survey_answers.questionable_id', 'questions.id')
                        ->where('survey_answers.questionable_type', Question::class)
                        ->whereNull('deleted_at');
                })
                ->orWhereExists(function (Query\Builder $query) {
                    return $query
                        ->selectRaw(1)
                        ->fromTenant('questions')
                        ->whereColumn('survey_answers.questionable_id', 'questions.id')
                        ->where('questionable_id', '!=', $this->surveyAnswer->questionable_id)
                        ->where('survey_answers.questionable_type', tenant\Question::class)
                        ->whereNull('deleted_at');
                })
            )
            ->multipleOrFiltering()
            ->filterAnswers($this->filters)
            ->module($this->module_type)
            ->groupBy(
                'survey_answers.questionable_id',
                'survey_answers.questionable_type',
            );
    }

    private function modifyBaseQueryonNps($filtering_questions,$is_previous){


        $filtering_questions  = $filtering_questions->get();

        if(count($this->custom_nps_filter) && count($filtering_questions)){

            $previous_time_slot = $current_time_slot = " AND  (updated_at) is not null";

            if($this->period == PeriodScope::LAST_365_DAYS){
                $previous_time_slot = " AND  date(updated_at) Between '".now()->subDays(730)->format('Y-m-d')."' AND "." '".now()->subDays(365)->format('Y-m-d')."'" ;
                $current_time_slot = " AND  date(updated_at) >= '".now()->subDays(365)->format('Y-m-d')."'";
            }

            else if($this->period == PeriodScope::LAST_THREE_MONTHS){
                $previous_time_slot = " AND   date(updated_at) Between '".now()->subMonths(6)->format('Y-m-d')."' AND "." '".now()->subMonths(3)->format('Y-m-d')."'" ;
                $current_time_slot = " AND  date(updated_at) >= '".now()->subMonths(3)->format('Y-m-d')."'";
            }
            else if($this->period == PeriodScope::LAST_THIRTY_DAYS){
                $previous_time_slot = " AND  date(updated_at) Between '".now()->subDays(60)->format('Y-m-d')."' AND "." '".now()->subDays(30)->format('Y-m-d')."'" ;
                $current_time_slot = " AND  date(updated_at) >= '".now()->subDays(30)->format('Y-m-d')."'";
            }
            else if($this->period == PeriodScope::TODAY){
                $previous_time_slot = " AND  date(updated_at) = '".now()->subDay()->format('Y-m-d')."'";
                $current_time_slot = " AND  date(updated_at) = '".now()->format('Y-m-d')."'";
            }
            else if($this->period == PeriodScope::Custom){


                if(isset($this->custom_date) && !empty($this->custom_date)){

                    $dates = convertCustomDateRange($this->custom_date);
                    $dates_diff = $dates['start_date']->diffInDays( $dates['end_date'] );
                    $previous_time_start_date = $dates['start_date']->copy()->subYears(1)->format('Y-m-d');
                    $previous_time_end_date = $dates['end_date']->copy()->subYears(1)->format('Y-m-d');
                    $current_time_slot = " AND   date(updated_at) Between '".$dates['start_date']->format('Y-m-d')."' AND "." '".$dates['end_date']->format('Y-m-d')."'" ;
                    $previous_time_slot = " AND   date(updated_at) Between '".$previous_time_start_date."' AND "." '".$previous_time_end_date."'" ;
                }

            }

            DB::statement('SET GLOBAL group_concat_max_len = 1000000');

            foreach ($filtering_questions as $key=> $filtering_question){

                $multiple_invites = explode(',',$filtering_question->invites);

                $operator = '';

                $nps_filter = "( ";

                //value > 9 OR value between 8 and 8 OR value < =6

                if(in_array('promoter',$this->custom_nps_filter))
                {

                    $nps_filter .= " value >= 9";
                    $operator = "OR";

                }

                if(in_array('passive',$this->custom_nps_filter))
                {

                    $nps_filter .= " $operator  value between 7 and 8";
                    $operator = "OR";

                }

                if(in_array('detractor',$this->custom_nps_filter))
                {
                    $nps_filter .= " $operator  value <=6";

                }
                $nps_filter .= " )";
                $answer_count_query = SurveyAnswer::query()->select(

                    DB::raw("(select GROUP_CONCAT(survey_invite_id) from survey_answers where survey_invite_id IN ($filtering_question->invites) $current_time_slot AND  question_type='nps' and $nps_filter AND value not like '%N/A%' ) as all_nps_people_invites")
                );
                $answer_count_query  = $answer_count_query->where('question_type','nps')->whereIn('survey_invite_id',$multiple_invites);
                $answer_count_query = $answer_count_query->first();


                $multi_q_report = SurveyAnswer::query()
                    ->selectRaw(
                        <<<SQL
                    survey_answers.questionable_id,
                    CONCAT('[', GROUP_CONCAT(value), ']') as filtered_ans
                SQL
                    )
                    ->where('questionable_id', '!=', $this->surveyAnswer->questionable_id)
                    ->module($this->module_type)
                    ->whereIn('survey_invite_id',explode(',',$answer_count_query->all_nps_people_invites))
                    ->where(function (Builder $query) {
                        return $query->whereExists(function (Query\Builder $query) {
                            return $query
                                ->selectRaw(1)
                                ->fromCentral('questions')
                                ->whereColumn('survey_answers.questionable_id', 'questions.id')
                                ->where('survey_answers.questionable_type', Question::class)
                                ->where('questions.type', Question::FILTERING);
                        })->orWhereExists(function (Query\Builder $query) {
                            return $query
                                ->selectRaw(1)
                                ->fromTenant('questions')
                                ->whereColumn('survey_answers.questionable_id', 'questions.id')
                                ->where('survey_answers.questionable_type', Tenant\Question::class)
                                ->where('questions.type', Tenant\Question::FILTERING);
                        });
                    })
                    ->whereExists(function (Query\Builder $query) {
                        return $query
                            ->selectRaw(1)
                            ->fromTenant('survey_answers as sub')
                            ->whereColumn('survey_answers.survey_invite_id', 'sub.survey_invite_id')
                            ->where('sub.questionable_type', $this->surveyAnswer->questionable_type)
                            ->where('sub.questionable_id', $this->surveyAnswer->questionable_id);
                    })
                    ->when($this->isActiveSurvey, fn(Builder $query) => $query
                        ->whereExists(function (Query\Builder $query) {
                            return $query
                                ->selectRaw(1)
                                ->fromCentral('questions')
                                ->whereColumn('survey_answers.questionable_id', 'questions.id')
                                ->where('survey_answers.questionable_type', Question::class)
                                ->whereNull('deleted_at');
                        })
                        ->orWhereExists(function (Query\Builder $query) {
                            return $query
                                ->selectRaw(1)
                                ->fromTenant('questions')
                                ->whereColumn('survey_answers.questionable_id', 'questions.id')
                                ->where('questionable_id', '!=', $this->surveyAnswer->questionable_id)
                                ->where('survey_answers.questionable_type', tenant\Question::class)
                                ->whereNull('deleted_at');
                        })
                    )
                    ->filtering()
                    ->filterAnswers($this->filters);



                if($is_previous==true){

                    $multi_q_report = $multi_q_report->previousPeriodFilter($this->period,$this->custom_date);
                }
                else {

                    $multi_q_report = $multi_q_report->periodFilter($this->period,$this->custom_date);
                }

                $multi_q_report = $multi_q_report->first();
                if($answer_count_query && $multi_q_report && $multi_q_report->filtered_ans){

                    $filtering_questions[$key]['answers'] = $multi_q_report->filtered_ans;
                }
            }
        }

        return $filtering_questions;

    }

    private function modifyBaseQueryOnSurveyProgressFilter($query)
    {
            return $query->when($this->surveyProgressFilter,function ($query) {
                $query->join('survey_invites',function($join){
                    $join->on('survey_answers.survey_invite_id','=','survey_invites.id');
                })->whereIn('survey_invites.status',$this->surveyProgressFilter); 
            });
    }
    public function getLastPeriodDifference(SurveyAnswer $answer, string $answerValue): int | string
    {
        $score = $this->getAnswerScoreByFilterQuestion($answer->questionable_type, $answer->questionable_id, $answerValue);

        /** @var SurveyAnswer|null $previous */
        $previous = $this->previousFilteringAnswers
            ->where('questionable_id', $answer->questionable_id)
            ->where('questionable_type', $answer->questionable_type)
            ->first();

        if (!$previous) {
            return 0;
        }

        $previousScore = $this->getPriorAnswerScoreByFilterQuestion($previous->questionable_type, $previous->questionable_id, $answerValue);

        return ($previousScore == 'N/A' || $score == 'N/A') ? 0 : round($score) - round($previousScore);
    }

    public function getLastPeriodPercentageDifference(SurveyAnswer $answer, string $answerValue): int | string
    {
        $percentage = round($answer->answers->collapse()->percentage($answerValue));

        /** @var SurveyAnswer|null $previous */
        $previous = $this->previousFilteringAnswers
            ->where('questionable_id', $answer->questionable_id)
            ->where('questionable_type', $answer->questionable_type)
            ->first();

        if (!$previous) {
            return 0;
        }

        $previousPercentage = round($previous?->answers->collapse()->percentage($answerValue) ?? 0);

        return $percentage - $previousPercentage;
    }

    public function getFormattedScore($score): int | string
    {
        $formattedScore = is_string($score) ? 'N/A' : round($score);

        return $this->surveyAnswer->isFiltering() || $this->surveyAnswer->isMultipleChoice() ? $formattedScore . '%' : $formattedScore;
    }

    public function open(SurveyAnswer $surveyAnswer,string $period=null,string $custom_date=null,string $module_type=null): void
    {
        $this->surveyAnswer = $surveyAnswer;
        $this->period = $period;
        $this->custom_date = $custom_date;
        $this->module_type = $module_type;
        $this->modal = true;
        if (!$this->filters) {
            $this->filters = getFiltersFromRestrictions($this->module_type);
        }
    }

    public function setPeriod(string $period, string $custom_date): void
    {
        $this->period = $period;
        $this->custom_date = $custom_date;
    }

    public function setFilters(array $filters, $setResultEmpty = false, $toRemove = []): void
    {
        $this->filters = $filters;

        foreach ($toRemove as $modelQuestion => $questions) {
            foreach ($questions as $questionableId => $value) {
                unset($this->filters[$modelQuestion][$questionableId]);
            }
        }
        
        if(count($this->filters)==0){
            $this->is_custom_nps_filter = false;
            $this->custom_nps_filter = [];
        }
        else {
            if(isset($this->filters) && isset($this->filters['custom_nps_filter']) ){
                $this->is_custom_nps_filter = true;
                foreach ($this->filters['custom_nps_filter'] as $nps_filter){
                    $this->custom_nps_filter = $nps_filter;
                }
                unset($this->filters['custom_nps_filter']);
            }
            else {
                $this->is_custom_nps_filter = false;
                $this->custom_nps_filter = [];
            }
        }
        $this->surveyProgressFilter = setSurveyProgressFilter($this->filters);
        unset($this->filters['survey_progess']);
    }

    public function setIsActiveSurveys(bool $onlyActive): void
    {
        $this->isActiveSurvey = $onlyActive;
    }

    public function render()
    {
        return view('livewire.tenant.dashboard.filters-results');
    }
}
