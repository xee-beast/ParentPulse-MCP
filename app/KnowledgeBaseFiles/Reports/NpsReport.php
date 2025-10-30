<?php

namespace App\Reports;

use App\Models\Tenant;
use App\Models\Tenant\SurveyAnswer;
use App\Models\Tenant\SurveyInvite;
use App\Scopes\PeriodScope;
use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NpsReport
{
    public function __construct(
        private string $period,
        private string $custom_date = '',
        private string $module_type = 'all',
        private array $filters = [],
        private array $surveyProgressFilter = [SurveyInvite::ANSWERED,SurveyInvite::SEND],
        private $filtersTo = [],
        private bool $is_custom_nps_filter = false,
        private array $allActiveModules = []

    ) {
        if(!count($this->allActiveModules)){
            $this->allActiveModules = getActiveModules();
        }
    }

    public function currentPeriod(): Collection
    {
        return collect(SurveyAnswer::selectRaw(
            <<<SQL
            id,
            value,
            IFNULL(SUM(responses),0) as responses,
            questionable_id,
            questionable_type,
            module_type,
            IFNULL(SUM(promoters),0) as promoters,
            IFNULL(SUM(detractors),0) as detractors,
            IFNULL(SUM(passive),0) as passive,
            IFNULL(ROUND(SUM(promoters) /  SUM(responses) * 100),0) as  promoters_percentage,
            IFNULL(ROUND(SUM(detractors) /  SUM(responses) * 100),0) as detractors_percentage,
            IFNULL(ROUND(SUM(passive) /  SUM(responses) * 100),0) as passive_percentage,
            IFNULL(ROUND(SUM(promoters) /  SUM(responses) * 100) - ROUND(SUM(detractors) /  SUM(responses) * 100),0) as score,
            IFNULL(
                    ROUND( ROUND(SUM(parent_promoters) / SUM(parent_responses) * 100) - ROUND(SUM(parent_detractors) / SUM(parent_responses) * 100))
                ,0) as parent_score,
                IFNULL(
                    ROUND( ROUND(SUM(student_promoters) / SUM(student_responses) * 100) - ROUND(SUM(student_detractors) / SUM(student_responses) * 100))
                ,0) as student_score,
                IFNULL(
                    ROUND( ROUND(SUM(employee_promoters) / SUM(employee_responses) * 100) - ROUND(SUM(employee_detractors) / SUM(employee_responses) * 100))
                ,0) as employee_score
          
        SQL
        )
        ->from(
            $this->baseQuery()
            ->when(isSequence($this->period), function ($query) {
                $query->sequenceFilter($this->period);
            }, function ($query) {
                $query->periodFilter($this->period, $this->custom_date);
            })
            ->filterAnswers($this->filters, $this->filtersTo)
        )
        ->first()?->toArray());
    }

    public function previousPeriod(): Collection
    {

        return collect (SurveyAnswer::selectRaw(
            <<<SQL
            id,
            IFNULL(SUM(responses),0) as responses,
            questionable_id,
            questionable_type,
            module_type,
            IFNULL(SUM(promoters),0) as promoters,
            IFNULL(SUM(detractors),0) as detractors,
            IFNULL(SUM(passive),0) as passive,
            IFNULL(ROUND(SUM(promoters) /  SUM(responses) * 100),0) as  promoters_percentage,
            IFNULL(ROUND(SUM(detractors) /  SUM(responses) * 100),0) as detractors_percentage,
            IFNULL(ROUND(SUM(passive) /  SUM(responses) * 100),0) as passive_percentage,
            IFNULL(ROUND(SUM(promoters) /  SUM(responses) * 100) - ROUND(SUM(detractors) /  SUM(responses) * 100),0) as score,
            IFNULL(
                    ROUND(parent_promoters / parent_responses * 100 - parent_detractors / parent_responses * 100)
                ,0) as parent_score,
                IFNULL(
                    ROUND(SUM(IF (`value` >= 9 AND module_type = 'student',1,0)) / SUM(if(module_type = 'student', 1, 0)) * 100 - SUM(IF (`value`<=6 AND module_type = 'student',1,0)) / SUM(if(module_type = 'student', 1, 0)) * 100)
                ,0) as student_score,
                  IFNULL(
                    ROUND(SUM(IF (`value` >= 9 AND module_type = 'employee',1,0)) / SUM(if(module_type = 'employee', 1, 0)) * 100 - SUM(IF (`value`<=6 AND module_type = 'employee',1,0)) / SUM(if(module_type = 'employee', 1, 0)) * 100)
                ,0) as employee_score
          
        SQL
        )->from($this->baseQuery()
        ->when(isSequence($this->period), function ($query) {
            $query->sequenceFilter($this->period);
        }, function ($query) {
            $query->previousPeriodFilter($this->period, $this->custom_date);
        })    
        ->filterAnswers($this->filters,$this->filtersTo)
    )->first()?->toArray());

    }

    public function getIndicatorPersons($indicator){

        return $this->baseIndicatorQuery();
    }
public function baseQuery(): Builder|SurveyAnswer
    {
        $base_query = SurveyAnswer::query()
            ->selectRaw(<<<SQL
                survey_answers.id,
                survey_answers.value,
                COUNT(survey_answers.id)                                                                     as responses,
                SUM(IF (`value` >= 9 AND survey_answers.module_type = 'parent',1,0)) as parent_promoters,
                SUM(IF (`value`<=6 AND survey_answers.module_type = 'parent',1,0)) as parent_detractors,
                SUM(IF (`value` >= 9 AND survey_answers.module_type = 'student',1,0)) as student_promoters,
                SUM(IF (`value`<=6 AND survey_answers.module_type = 'student',1,0)) as student_detractors,
                SUM(IF (`value` >= 9 AND survey_answers.module_type = 'employee',1,0)) as employee_promoters,
                SUM(IF (`value`<=6 AND survey_answers.module_type = 'employee',1,0)) as employee_detractors,
                SUM(if(survey_answers.module_type = 'parent', 1, 0)) as parent_responses,
                SUM(if(survey_answers.module_type = 'student', 1, 0)) as student_responses,
                SUM(if(survey_answers.module_type = 'employee', 1, 0)) as employee_responses,
                survey_answers.questionable_id,
                survey_answers.questionable_type,
                survey_answers.module_type,
                IFNULL(SUM(IF(`value` >= 9, 1, 0)), 0)                                          as promoters,
                IFNULL(SUM(IF(`value` <= 6, 1, 0)), 0)                                          as detractors,
                IFNULL(SUM(IF(`value` BETWEEN 7 AND 8, 1, 0)), 0)                               as passive,
                IFNULL(ROUND(SUM(IF(`value` >= 9, 1, 0)) / COUNT(`value`) * 100), 0)            as promoters_percentage,
                IFNULL(ROUND(SUM(IF(`value` <= 6, 1, 0)) / COUNT(`value`) * 100), 0)            as detractors_percentage,
                IFNULL(ROUND(SUM(IF(`value` BETWEEN 7 AND 8, 1, 0)) / COUNT(`value`) * 100), 0) as passive_percentage,
                IFNULL(
                    ROUND(
                        (SUM(IF (`value` >= 9, 1, 0)) / COUNT(`value`)) * 100
                        - (SUM(IF (`value` <= 6, 1, 0)) / COUNT(`value`)) * 100
                    ),
                    null) as score
            SQL)
            ->join('survey_invites', 'survey_invites.id', '=', 'survey_answers.survey_invite_id')
            ->whereNotNull('value')
            ->where('value', 'not LIKE', '%N/A%');

            if($this->module_type == Tenant::ALL){
                $base_query = $base_query->whereIn('survey_answers.module_type',array_keys($this->allActiveModules));
            }
            elseif($this->module_type == Tenant::ALLPULSE)
            {
                $base_query = $base_query->whereIn('survey_answers.module_type',array_intersect(array_keys($this->allActiveModules), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
            }
            else {
                $base_query = $base_query->module($this->module_type);
            }
            $base_query =$base_query->whereIn('survey_invites.status',$this->surveyProgressFilter);
        $base_query  = $base_query->nps()
            ->groupBy(
                'survey_answers.questionable_id','survey_answers.questionable_type','survey_answers.module_type'
            );
        return $base_query;
    }
}