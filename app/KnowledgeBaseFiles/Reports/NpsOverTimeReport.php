<?php

namespace App\Reports;

use App\Models\Tenant\SurveyAnswer;
use App\Scopes\PeriodScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NpsOverTimeReport
{
    public function __construct(
        private int $year,
        private $filtersTo = [],
        private $surveyProgressFilter = [],
        private string $period,
        private string $custom_date = '',
        private $filters = [],
        private $module_type = 'all',
    ) {
    }

    public function monthlyGrouped(): Collection
    {
        return collect($this->baseQuery()
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray());
    }

    public function baseQuery(): Builder
    {

        $promotersSum =  !count($this->filtersTo) || in_array('promoter',$this->filtersTo) ? 'SUM(IF(`value` >= 9, 1, 0))' : '0';
        $detractorsSum =  !count($this->filtersTo) || in_array('detractor',$this->filtersTo) ? 'SUM(IF(`value` <= 6, 1, 0))' : '0';
        $passiveSum =  !count($this->filtersTo) || in_array('passive',$this->filtersTo) ? 'SUM(IF(`value` BETWEEN 7 AND 8, 1, 0))' : '0';
        $sumCount = 'COALESCE('.$promotersSum.',0)+'.'COALESCE('.$detractorsSum.',0)+'.'COALESCE('.$passiveSum.',0)';
        $allDividentSum = 'Count(value)';
        $timePeriod = " AND survey_answers.updated_at IS NOT NULL";
        if($this->period && $this->period==PeriodScope::ALL_TIME){
            $timePeriod = " AND survey_answers.updated_at IS NOT NULL";
        }
        else if($this->period==PeriodScope::LAST_365_DAYS){

            $timePeriod = " AND date(survey_answers.updated_at) >= '".now()->subDays(365)->format('Y-m-d')."'";
        }
        else if($this->period==PeriodScope::LAST_THREE_MONTHS){

            $timePeriod = " AND date(survey_answers.updated_at) >='".now()->subMonths(3)->format('Y-m-d')."'";
        }
        else if($this->period==PeriodScope::LAST_THIRTY_DAYS){
            $timePeriod = " AND date(survey_answers.updated_at) >='".now()->subDays(30)->format('Y-m-d')."'";
        }
        else if($this->period==PeriodScope::TODAY){
            $timePeriod = " AND date(survey_answers.updated_at) ='".now()->format('Y-m-d')."'";
        }
        else if($this->period==PeriodScope::Custom){

            if(isset($this->custom_date) && !empty($this->custom_date)){

                $dates = convertCustomDateRange($this->custom_date);
                $timePeriod = " AND date(survey_answers.updated_at) Between '".$dates['start_date']->format('Y-m-d')."' AND "." '".$dates['end_date']->format('Y-m-d')."'" ;
            }


        }
        if (count($this->filtersTo)){
            $promotersDivider = in_array('promoter',$this->filtersTo)  ? ' (select count(id) from survey_answers WHERE value >=9  AND question_type = \'nps\' AND value not LIKE \'%N/A%\' '.$timePeriod.') ' : '0';
            $passiveDivider = in_array('passive',$this->filtersTo)  ? ' (select count(id) from survey_answers WHERE value BETWEEN 7 AND 8  AND question_type = \'nps\' AND value not LIKE \'%N/A%\''.$timePeriod.') ' : '0';
            $detractorsDivider =in_array('detractor',$this->filtersTo)  ? '(select count(id) from survey_answers WHERE value <=6  AND question_type = \'nps\' AND value not LIKE \'%N/A%\''.$timePeriod.')' : '0';

            $allDividentSum = "($promotersDivider + $passiveDivider + $detractorsDivider)";
        }

//        IFNULL(
//            ROUND(
//                IFNULL(ROUND(SUM(IF(`value` >= 9, 1, 0)) / COUNT(`value`) * 100), 0)
//                        - IFNULL(ROUND(SUM(IF(`value` <= 6, 1, 0)) / COUNT(`value`) * 100), 0)
//                    ), 0
//                ) as score_average,


        $surveyInviteTableSelect = $this->surveyProgressFilter?',survey_invites.status':'';
        $nps_report =  SurveyAnswer::query()
            ->selectRaw(<<<SQL
                    IFNULL(
                        Floor(
                            ($promotersSum / $allDividentSum) * 100
                            - ($detractorsSum / $allDividentSum) * 100
                        ),
                        null) as score_average,
                IFNULL($sumCount, 0) as answers_count,
                MONTH(survey_answers.updated_at) - 1 as month
                $surveyInviteTableSelect
            SQL)
            ->where('value', 'not LIKE', '%N/A%');

            if($this->module_type !=='all'){
                $nps_report = $nps_report->where('survey_answers.module_type',$this->module_type);
            }
            else {
                if(getActiveModules()){
                    $nps_report = $nps_report->whereIn('survey_answers.module_type',getActiveModules());
                }
            }
            if ($this->surveyProgressFilter) {
                $nps_report = $nps_report->when($this->surveyProgressFilter,function ($query) {
                    $query->join('survey_invites',function($join){
                        $join->on('survey_answers.survey_invite_id','=','survey_invites.id');
                    })->whereIn('survey_invites.status',$this->surveyProgressFilter); 
                });
            }
            $nps_report = $nps_report->nps()
            ->periodFilter($this->period,$this->custom_date)
            ->filterAnswers($this->filters)
            // ->latestSurvey()
            ->whereYear('survey_answers.updated_at', $this->year);
        return $nps_report;
    }
}
