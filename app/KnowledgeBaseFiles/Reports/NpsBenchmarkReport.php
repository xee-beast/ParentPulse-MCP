<?php

namespace App\Reports;

use App\Models\Tenant\SurveyAnswer;
use App\Scopes\PeriodScope;
use Illuminate\Support\Str;
use App\Models\{Question, Tenant};
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query;
use Illuminate\Support\Facades\DB;

class NpsBenchmarkReport
{
    public bool $applyBenchmarkFilter = false;

    public array $filters = [];
    public array $custom_nps_filters = [];
    public $period = null;
    public $custom_date = null;
    public string $module_type = 'all';
    public $allActiveModules = [];

    public function __construct(bool $applyBenchmarkFilter = false, array $filters, array $custom_nps_filters,string $period=null,string $custom_date=null,string $module_type =null, array $allActiveModules = []) {
        $this->applyBenchmarkFilter = $applyBenchmarkFilter;
        $this->filters = $filters;
        $this->custom_nps_filters = $custom_nps_filters;
        $this->period = $period;
        $this->custom_date = $custom_date;
        $this->module_type = $module_type;
        if(!count($this->allActiveModules)){
            $this->allActiveModules = getActiveModules();
        }
    }

    public static function query(bool $applyBenchmarkFilter = false, array $filters = [], array $custom_nps_filters = [],string $period=null,string $custom_date=null, string $module_type =null): Builder
    {
        return (new static($applyBenchmarkFilter, $filters,$custom_nps_filters,$period,$custom_date,$module_type))->baseQuery();
    }

    public function baseQuery(): Builder
    {
        if(app()->runningInConsole()){
            $databases=[];
            $databasesData=Tenant::get();
            foreach($databasesData as $data){
                array_push($databases,$data->tenancy_db_name);
            }
            $firstDatabase = array_shift($databases);

        }else{
            $databases = Tenant::databases();
            $firstDatabase = array_shift($databases);
        }

        $query=$this->innerQuery()->from($this->npsQuery($firstDatabase));
        foreach ($databases as $database) {
            $npsQuery = $this->innerQuery()->from($this->npsQuery($database)) ;
            $query=$query->union($npsQuery);
        }
        return $query->getQuery();
    }

    private function npsQuery(string $database): Builder
    {
        $npsQuery =  DB::table("{$database}.survey_answers")
            ->selectRaw(<<<SQL
               "$database" as db_name,
               id,
               IFNULL(SUM(IF(`value` >= 9, 1, 0)), 0)                                          as promoters,
                IFNULL(SUM(IF(`value` <= 6, 1, 0)), 0)                                          as detractors,
                IFNULL(SUM(IF(`value` BETWEEN 7 AND 8, 1, 0)), 0)                               as passive,
                COUNT(survey_answers.id)                                                                     as responses,
                questionable_type,
                questionable_id,
                module_type
            SQL)
            ->whereNotNull('value')
            ->when($this->applyBenchmarkFilter, function (Query\Builder $query) use ($database) {
                return $query
                    ->tap(fn (Query\Builder $query) => $this->applyPeriodFilter($query))
                    ->tap(fn (Query\Builder $query) =>  $this->filterBenchmarkAnswers($query, $this->filters, $database,$this->custom_nps_filters));
            })
            ->where('question_type', Question::NPS);
            if($this->module_type == Tenant::ALL || $this->module_type == Tenant::ALLPULSE){
                if($this->allActiveModules){
                $npsQuery = $npsQuery->whereIn('survey_answers.module_type',getActiveModulesAgainstDatabase($database));
                }
            }
            else {
                $npsQuery = $npsQuery->where('module_type',$this->module_type);
            }

        $npsQuery = $npsQuery
            ->groupBy(
                'questionable_id',
                'questionable_type',
                'module_type'
            );
        return $npsQuery;
    }
    public function innerQuery(){
        return SurveyAnswer::selectRaw(
            <<<SQL
            id,
            IFNULL(SUM(responses),0) as responses,
            questionable_id,
            questionable_type,
            db_name,
            module_type,
            IFNULL(SUM(promoters),0) as promoters,
            IFNULL(SUM(detractors),0) as detractors,
            IFNULL(SUM(passive),0) as passive,
            IFNULL(ROUND(SUM(promoters) /  SUM(responses) * 100),0) as  promoters_percentage,
            IFNULL(ROUND(SUM(detractors) /  SUM(responses) * 100),0) as detractors_percentage,
            IFNULL(ROUND(SUM(passive) /  SUM(responses) * 100),0) as passive_percentage,
            IFNULL(ROUND(SUM(promoters) /  SUM(responses) * 100) - ROUND(SUM(detractors) /  SUM(responses) * 100),0) as benchmark     
        SQL
        );
    }
    private function applyPeriodFilter(Query\Builder $query): Query\Builder
    {
        if($this->period==PeriodScope::Custom){

            if(isset($this->custom_date) && !empty($this->custom_date)){
                $dates = convertCustomDateRange($this->custom_date);
            }

        }
        if($this->period){

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
        return $query;

    }

    public static function filterBenchmarkAnswers($query, array $filters, string $database,array $custom_nps_filters)
    {

        if(count($custom_nps_filters)){


                $query->whereExists(function ($query) use ($custom_nps_filters, $database) {
                    $filtersToString = collect($custom_nps_filters)->toStringImploded();
                    return $query
                        ->selectRaw(<<<SQL
                            CASE
                                WHEN survey_answers.value >= 9 THEN 'promoter'
                                WHEN survey_answers.value BETWEEN 7 AND 8 THEN 'passive'
                                WHEN survey_answers.value <= 6 THEN 'detractor'
                                ELSE 'unknown'
                            END as type
                        SQL)
                        ->from("{$database}.survey_answers as sub")
                        ->whereColumn('survey_answers.survey_invite_id', 'sub.survey_invite_id')
                        ->where('sub.questionable_type', Question::class)
                        ->where('sub.question_type', Question::NPS)
                        ->whereNotNull('sub.value')
                        ->havingRaw("type in ({$filtersToString})");
                });

        }

        collect($filters)->each(function (array $filters) use ($query, $database) {
            foreach ($filters as $id => $answers) {
                $query->whereExists(function ($query) use ($id, $answers, $database) {
                    return $query
                        ->selectRaw(1)
                        ->from("{$database}.survey_answers as sub")
                        ->whereColumn('survey_answers.survey_invite_id', 'sub.survey_invite_id')
                        ->where('sub.questionable_type', Question::class)
                        ->where('sub.questionable_id', $id)
                        ->where('sub.question_type', Question::FILTERING)
                        ->whereNotNull('sub.value')
                        ->where(function ($query) use ($answers) {
                            $answer = array_shift($answers);
                            $query->where('sub.value', 'like', "%\"{$answer}\"%");

                            foreach ($answers as $answer) {
                                $query->orWhere('sub.value', 'like', "%\"{$answer}\"%");
                            }
                        });
                });
            }
        });
    }
}
