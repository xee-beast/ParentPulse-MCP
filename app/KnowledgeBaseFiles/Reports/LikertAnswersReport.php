<?php

namespace App\Reports;

use App\Models\Tenant\SurveyAnswer;
use App\Models\{Question, Tenant};
use App\Scopes\PeriodScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query;
use Illuminate\Support\Facades\DB;

class LikertAnswersReport
{
    public function __construct(
        private string $period,
        private string $custom_date= '',
        private array  $filters = [],
        private bool   $scopeActiveSurvey = true,
        private bool   $filterBenchmark = false,
        private $filtersTo = [],
        private string $module_type = 'all',
        private bool  $calculateBenchmakr = true,
        private array $getPermissionForModule = []

    ) {
        if(!count($this->getPermissionForModule)){
            $this->getPermissionForModule = getPermissionForModule('_view_dashboard');
        }
    }

    public function run($is_previous=false): Builder
    {
        $reportColumns = [
            'MIN(survey_answers.id) as id',
            'CONCAT( GROUP_CONCAT(survey_answers.survey_invite_id)) as invites',
            'survey_answers.questionable_id',
            'survey_answers.questionable_type',
            "survey_answers.module_type",
            'score',
            'answers_count',
            'IFNULL(score - previous_score, 0) as period_diff',

        ];
        if($this->calculateBenchmakr)
        {
            $reportColumns[] = "IFNULL(benchmark, 'N/A') as benchmark";
        }
        $reportColumns = implode(',',$reportColumns);
        $benchmarkReport =  SurveyAnswer::query()
            ->selectRaw(
                <<<SQL
                $reportColumns
                
            SQL
            )
            ->with('questionable:id,name')
            ->filterAnswers($this->filters);
           if($is_previous){
            $benchmarkReport = $benchmarkReport->when(isSequence($this->period), function ($query) {
                                    $query->sequenceFilter($this->period, true);
                                }, function ($query) {
                                    $query->previousPeriodFilter($this->period, $this->custom_date);
                                });
           }
           else {

            $benchmarkReport = $benchmarkReport->when(isSequence($this->period), function ($query) {
                                    $query->sequenceFilter($this->period,true);
                                }, function ($query) {
                                    $query->periodFilter($this->period, $this->custom_date);
                                });
            }
        $benchmarkReport = $benchmarkReport->applySurveyProgressFilter(isSequence($this->period));
        $benchmarkReport = $benchmarkReport->benchmark()
            ->whereNotExists(function (Query\Builder $query) {
                return $query
                    ->selectRaw(1)
                    ->from('surveys')
                    ->whereNotNull('surveys.question_id')
                    ->where('survey_answers.questionable_type', Question::class)
                    ->whereColumn('survey_answers.questionable_id', 'surveys.question_id')
                    ->latest();
            })
            ->tap(fn (Builder $query) => $this->performJoins($query))
            ->tap(fn (Builder $query) => $this->applySurveyActiveFilter($query));
            if($this->module_type == Tenant::ALL){
                $benchmarkReport = $benchmarkReport->whereIn('survey_answers.module_type',array_keys($this->getPermissionForModule));
            }
            elseif($this->module_type == Tenant::ALLPULSE)
            {
                $benchmarkReport = $benchmarkReport->whereIn('survey_answers.module_type',array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
            }
            else {
                $benchmarkReport = $benchmarkReport->module($this->module_type);
            }
        $reportGroupByColumns = [
                'survey_answers.questionable_id',
                'survey_answers.questionable_type',
                'score',
                'answers_count',
                'previous_score',
                'survey_answers.module_type'
        ];
        if($this->calculateBenchmakr)
        {
            $reportGroupByColumns[] = 'benchmark';
        }

        $reportGroupByColumns = implode(',',$reportGroupByColumns);

        $benchmarkReport = $benchmarkReport->groupByRaw(
                $reportGroupByColumns
        );
        return $benchmarkReport;
    }

    private function performJoins(Builder $query): Builder
    {
         $query
            ->joinSub($this->scoreSubQuery(), 'nps_scores', function (Query\JoinClause $join) {
                return $join
                    ->on('survey_answers.questionable_id', 'nps_scores.questionable_id')
                    ->whereColumn('survey_answers.questionable_type', 'nps_scores.questionable_type')
                    ->whereColumn('survey_answers.module_type', 'nps_scores.module_type');
            })
            ->joinSub($this->answersCountSubQuery(), 'nps_answers_count', function (Query\JoinClause $join) {
                return $join
                    ->on('survey_answers.questionable_id', 'nps_answers_count.questionable_id')
                    ->whereColumn('survey_answers.questionable_type', 'nps_answers_count.questionable_type')
                    ->whereColumn('survey_answers.module_type', 'nps_answers_count.module_type');
//                    ->where('survey_answers.value','not like', '%N/A%');
            })
            ->leftJoinSub($this->previousScoreSubQuery(), 'previous_answers', function (Query\JoinClause $join) {
                return $join
                    ->on('survey_answers.questionable_id', 'previous_answers.questionable_id')
                    ->whereColumn('survey_answers.questionable_type', 'previous_answers.questionable_type')
                    ->whereColumn('survey_answers.module_type', 'previous_answers.module_type');
            });
            if($this->calculateBenchmakr){
              $query = $query->leftJoinSub($this->benchmarkScoreSubQuery(), 'benchmark_scores', function (Query\JoinClause $join) {
                    return $join
                        ->on('survey_answers.questionable_id', 'benchmark_scores.questionable_id')
                        ->whereColumn('survey_answers.questionable_type', 'benchmark_scores.questionable_type')
                        ->whereColumn('survey_answers.module_type', 'benchmark_scores.module_type');
                });
            }
            return $query;

    }

    private function scoreSubQuery(): Builder
    {
//        $promotersSum =  !count($this->filtersTo) || in_array('promoter',$this->filtersTo) ? 'AVG(IF(`value` >= 9, value, 0))' : '0';
//        $detractorsSum =  !count($this->filtersTo) || in_array('detractor',$this->filtersTo) ? 'AVG(IF(`value` <= 6, value, 0))' : '0';
//        $passiveSum =  !count($this->filtersTo) || in_array('passive',$this->filtersTo) ? 'AVG(IF(`value` BETWEEN 7 AND 8, value, 0))' : '0';
//        $avgCount = "($promotersSum + $detractorsSum + $passiveSum)";

        $avgCount = 'AVG(value)';

    $scoreQuery =   SurveyAnswer::query()
            ->selectRaw(
                <<<SQL
                ROUND($avgCount * 10) as score,
                questionable_id,
                questionable_type,
               survey_answers.module_type
            SQL
            )
            ->filterAnswers($this->filters)
            ->when(isSequence($this->period), function ($query) {
                $query->sequenceFilter($this->period,true);
            }, function ($query) {
                $query->periodFilter($this->period, $this->custom_date);
            })
            ->where('value', 'not LIKE', '%N/A%')
            ->whereNotNull('value')
            ->benchmark();
            if($this->module_type == Tenant::ALL){
                $scoreQuery = $scoreQuery->whereIn('module_type',array_keys($this->getPermissionForModule));
            }
            elseif($this->module_type == Tenant::ALLPULSE)
            {
                $scoreQuery = $scoreQuery->whereIn('module_type',array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
            }
            else {
                $scoreQuery = $scoreQuery->module($this->module_type);
            }

            $scoreQuery = $scoreQuery->groupBy('questionable_id', 'questionable_type','module_type');
            return $scoreQuery;
    }

    private function answersCountSubQuery(): Builder
    {

        $sumCount = 'COUNT(value)';
        $answersCount =  SurveyAnswer::query()
            ->selectRaw(
                <<<SQL
                $sumCount as answers_count,
                questionable_id,
                questionable_type,
                module_type
            SQL
            )
            ->filterAnswers($this->filters)
            ->periodFilter($this->period,$this->custom_date)
            ->benchmark()
            ->where('value', 'not like', "%N/A%");
            if($this->module_type == Tenant::ALL){
                $answersCount = $answersCount->whereIn('module_type',array_keys($this->getPermissionForModule));
            }
            elseif($this->module_type == Tenant::ALLPULSE)
            {
                $answersCount = $answersCount->whereIn('module_type',array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
            }
            else {
                $answersCount = $answersCount->module($this->module_type);
            }

        $answersCount = $answersCount->groupBy('questionable_id', 'questionable_type','module_type');
        return $answersCount;
    }

    private function previousScoreSubQuery(): Builder
    {

        $avgCount = 'AVG(value)';

        $previousScoreQuery =  SurveyAnswer::query()
            ->selectRaw(
                <<<SQL
                ROUND($avgCount * 10) as previous_score,
                questionable_id,
                questionable_type,
                module_type
            SQL
            )
            ->filterAnswers($this->filters)
            ->where('value', 'not LIKE', '%N/A%')
            ->previousPeriodFilter($this->period,$this->custom_date)
            ->benchmark();
            if($this->module_type == Tenant::ALL){
                $previousScoreQuery = $previousScoreQuery->whereIn('module_type',array_keys($this->getPermissionForModule));
            }
            elseif($this->module_type == Tenant::ALLPULSE)
            {
                $previousScoreQuery = $previousScoreQuery->whereIn('module_type',array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
            }
            else {
                $previousScoreQuery = $previousScoreQuery->module($this->module_type);
            }
        $previousScoreQuery = $previousScoreQuery->groupBy('questionable_id', 'questionable_type','module_type');
        return $previousScoreQuery;
    }

    public function benchmarkScoreSubQuery(): Query\Builder
    {
        $databases = Tenant::databases();
        $query = $this->makeBenchmarkQuery(array_shift($databases));

        foreach ($databases as $database) {
            $query->union($this->makeBenchmarkQuery($database));
        }
        return  DB::query()
            ->selectRaw(
                <<<SQL
                ROUND(AVG(benchmark),2) as benchmark,
                questionable_id,
                questionable_type,
                module_type
            SQL
            )
            ->from($query)
            ->groupBy(
                'questionable_id',
                'questionable_type',
                'module_type'
            );
    }

    private function makeBenchmarkQuery(string $database): Query\Builder
    {

        $avgCount = 'AVG(value)';

        $benchamarkQuery =  DB::table("{$database}.survey_answers")
            ->selectRaw(
                <<<SQL
                "$database" as db_name,
                questionable_id,
                questionable_type,
                module_type,
                IFNULL(ROUND($avgCount * 10) , 'N/A') as benchmark
            SQL
            )
            ->where('value', 'not LIKE', '%N/A%')
            ->whereNotNull('value')
            ->where('survey_answers.question_type', Question::BENCHMARK)
            ->where('survey_answers.questionable_type', Question::class);
            if($this->module_type == Tenant::ALL){
                $benchamarkQuery = $benchamarkQuery->whereIn('module_type',array_keys($this->getPermissionForModule));
            }
            elseif($this->module_type == Tenant::ALLPULSE)
            {
                $benchamarkQuery = $benchamarkQuery->whereIn('module_type',array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
            }
            else {
                $benchamarkQuery = $benchamarkQuery->where('module_type',$this->module_type);
            }
            $benchamarkQuery = $benchamarkQuery->when($this->filterBenchmark, function (Query\Builder $query) use ($database) {
                return $query
                    ->tap(fn (Query\Builder $query) => $this->applyPeriodFilter($query))
                    ->tap(fn (Query\Builder $query) => SurveyAnswer::filterBenchmarkAnswers($query, $this->filters, $database));
            })
            ->groupBy('questionable_id', 'questionable_type','module_type');
            return $benchamarkQuery;
    }

    private function applyPeriodFilter(Query\Builder $query): Query\Builder
    {
        if($this->period==PeriodScope::Custom){

            if(isset($this->custom_date) && !empty($this->custom_date)){
                $dates = convertCustomDateRange($this->custom_date);
            }

        }
        return match ($this->period) {
            PeriodScope::ALL_TIME          => $query->whereNotNull('updated_at'),
            PeriodScope::LAST_365_DAYS     => $query->whereDate('updated_at', '>=', now()->subDays(365)->format('Y-m-d')),
            PeriodScope::LAST_THREE_MONTHS => $query->whereDate('updated_at', '>=', now()->subMonths(3)->format('Y-m-d')),
            PeriodScope::LAST_THIRTY_DAYS  => $query->whereDate('updated_at', '>=', now()->subDays(30)->format('Y-m-d')),
            PeriodScope::TODAY             => $query->whereDate('updated_at', '=', now()->format('Y-m-d')),
            PeriodScope::Custom             => (isset($this->custom_date) && !empty($this->custom_date) && count($dates)>0) ? $query->whereDate('updated_at', '>=', $dates['start_date']->format('Y-m-d'))->whereDate('updated_at', '<=', $dates['end_date']->format('Y-m-d')) :$query->whereDate('updated_at', '=', now()->format('Y-m-d')),
            default                         =>  $query,
        };
    }

    private function applySurveyActiveFilter(Builder $query): Builder
    {
        return $query->when($this->scopeActiveSurvey, function (Builder $query) {
            return $query->where(function (Builder $query) {
                return $query->whereExists(function (Query\Builder $query) {
                    return $query
                        ->selectRaw(1)
                        ->from('question_survey')
                        ->where('survey_answers.questionable_type', Question::class)
                        ->whereColumn('survey_answers.questionable_id', 'question_survey.question_id')
                        ->whereColumn('survey_answers.module_type', 'question_survey.module_type')
                        ->tap(fn (Query\Builder $query) => $this->applySurveyActiveClause($query));
                })->orWhereExists(function (Query\Builder $query) {
                    return $query
                        ->selectRaw(1)
                        ->from('question_survey')
                        ->where('survey_answers.questionable_type', Tenant\Question::class)
                        ->whereColumn('survey_answers.questionable_id', 'question_survey.custom_question_id')
                        ->whereColumn('survey_answers.module_type', 'question_survey.module_type')
                        ->tap(fn (Query\Builder $query) => $this->applySurveyActiveClause($query));
                });
            })->orWhereExists(function ($query) {
                return $query
                    ->selectRaw(1)
                    ->fromCentral('questions')
                    ->where('system_default', '=', true)
                    ->where('active', '=', true)
                    ->whereNull('deleted_at')
                    ->where('survey_answers.questionable_type', Question::class)
                    ->whereColumn('survey_answers.questionable_id', 'questions.id');
            });
        });
    }

    private function applySurveyActiveClause(Query\Builder $query): Query\Builder
    {
        return $query->whereExists(function (Query\Builder $query) {
            return $query
                ->selectRaw(1)
                ->from('surveys')
                ->whereColumn('question_survey.survey_id', 'surveys.id');
        });
    }
}
