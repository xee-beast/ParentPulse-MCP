<?php

namespace App\Reports;

use App\Models\Question;
use App\Models\Tenant\SurveyAnswer;
use App\Scopes\PeriodScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScoreOverTimeReport
{
    public function __construct(
        private int $questionId,
        private string $questionableType,
        private int $year,
        private $filtersTo = [],
        private $surveyProgressFilter = [],
        private  $period,
        private $custom_date = '',
        private $filters = [],
        private $module_type = 'all',
    ) {
    }

    public function monthlyGrouped(): Collection
    {
        return collect($this->getResults());
    }

    public function getResults(){
        $db_results = $this->baseQuery()
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        if(count($this->filtersTo) && count($db_results)){


            $previous_time_slot = $current_time_slot = " AND  (survey_answers.updated_at) is not null";

            if($this->period == PeriodScope::LAST_365_DAYS){
                $previous_time_slot = " AND  date(survey_answers.updated_at) Between '".now()->subDays(730)->format('Y-m-d')."' AND "." '".now()->subDays(365)->format('Y-m-d')."'" ;
                $current_time_slot = " AND  date(survey_answers.updated_at) >= '".now()->subDays(365)->format('Y-m-d')."'";
            }
            else if($this->period == PeriodScope::LAST_THREE_MONTHS){
                $previous_time_slot = " AND   date(survey_answers.updated_at) Between '".now()->subMonths(6)->format('Y-m-d')."' AND "." '".now()->subMonths(3)->format('Y-m-d')."'" ;
                $current_time_slot = " AND  date(survey_answers.updated_at) >= '".now()->subMonths(3)->format('Y-m-d')."'";
            }
            else if($this->period == PeriodScope::LAST_THIRTY_DAYS){
                $previous_time_slot = " AND  date(survey_answers.updated_at) Between '".now()->subDays(60)->format('Y-m-d')."' AND "." '".now()->subDays(30)->format('Y-m-d')."'" ;
                $current_time_slot = " AND  date(survey_answers.updated_at) >= '".now()->subDays(30)->format('Y-m-d')."'";
            }
            else if($this->period == PeriodScope::TODAY){
                $previous_time_slot = " AND  date(survey_answers.updated_at) = '".now()->subDay()->format('Y-m-d')."'";
                $current_time_slot = " AND  date(survey_answers.updated_at) = '".now()->format('Y-m-d')."'";
            }
            else if($this->period == PeriodScope::Custom){

                if(isset($this->custom_date) && !empty($this->custom_date)){

                    $dates = convertCustomDateRange($this->custom_date);
                    $current_time_slot = " AND   date(survey_answers.updated_at) Between '".$dates['start_date']->format('Y-m-d')."' AND "." '".$dates['end_date']->format('Y-m-d')."'" ;
                }
            }


            foreach ($db_results as $key=> $benchmarkReport){
                $answer_count = $benchmarkReport->answers_count;
                $multiple_invites = explode(',',$benchmarkReport->invites);
                $multipleInvites = rtrim($benchmarkReport->invites, ",");

                $operator = '';

                $nps_filter = "( ";


                if(in_array('promoter',$this->filtersTo))
                {

                    $nps_filter .= " value >= 9";
                    $operator = "OR";

                }

                if(in_array('passive',$this->filtersTo))
                {

                    $nps_filter .= " $operator  value between 7 and 8";
                    $operator = "OR";

                }

                if(in_array('detractor',$this->filtersTo))
                {
                    $nps_filter .= " $operator  value <=6";

                }
                $nps_filter .= " )";
                $answer_count_query = SurveyAnswer::query()->select(

                    DB::raw("(select COUNT(id) from survey_answers where survey_invite_id IN ($multipleInvites) $current_time_slot AND  question_type='nps' and $nps_filter AND value not like '%N/A%' ) as answers_count"),
                    DB::raw("(select SUM(value) from survey_answers where survey_invite_id IN ($multipleInvites)  $current_time_slot AND  question_type='nps' and $nps_filter  AND value not like '%N/A%'  ) as values_sum"),
                );

                $answer_count_query  = $answer_count_query->where('question_type','nps')->whereIn('survey_invite_id',$multiple_invites);
                $answer_count_query = $answer_count_query->first();
                if($answer_count_query){
                    $db_results[$key]['answers_count'] = $answer_count_query->answers_count;
                    $db_results[$key]['score_average'] = $answer_count_query->answers_count > 0 ? round(($answer_count_query->values_sum/$answer_count_query->answers_count)*10):0;
                }
            }
        }

        $data =  $db_results->groupBy('month')
            ->sortBy('month')->flatten()->toArray();

        return $data;

     }

    public function baseQuery()
    {


        $score_over_time = SurveyAnswer::query()
            ->selectRaw(<<<SQL
                IFNULL(ROUND(AVG(value) * 10), 0) as score_average,
                CONCAT( GROUP_CONCAT(survey_answers.survey_invite_id)) as invites,
                IFNULL(COUNT(value), 0) as answers_count,
                MONTH(survey_answers.updated_at) - 1 as month
            SQL)
            ->where('questionable_id', $this->questionId)
            ->where('questionable_type', $this->questionableType)
            ->where('value', 'not LIKE', '%N/A%')

            ->where(function (Builder $query) {
                $query->where('survey_answers.question_type', Question::BENCHMARK);
//                    ->orWhere('survey_answers.question_type', Question::NPS);
            })->whereYear('survey_answers.updated_at', $this->year);
        
            if ($this->surveyProgressFilter) {
                $score_over_time = $score_over_time->when($this->surveyProgressFilter,function ($query) {
                    $query->join('survey_invites',function($join){
                        $join->on('survey_answers.survey_invite_id','=','survey_invites.id');
                    })->whereIn('survey_invites.status',$this->surveyProgressFilter); 
                });
            }
        if($this->period){
            $score_over_time = $score_over_time->periodFilter($this->period,$this->custom_date)->filterAnswers($this->filters);
        }
        if($this->module_type !=='all'){
            $score_over_time = $score_over_time->where('survey_answers.module_type',$this->module_type);
        }
        else {
            if(getActiveModules()){
                $score_over_time = $score_over_time->whereIn('survey_answers.module_type',getActiveModules());
            }
        }

        return $score_over_time;
    }
}
