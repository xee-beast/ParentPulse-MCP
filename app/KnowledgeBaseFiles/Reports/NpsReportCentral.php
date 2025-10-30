<?php

namespace App\Reports;

use App\Models\CentralSurveyAnswer;
use App\Models\Question;
use App\Models\Tenant;
use App\Models\Tenant\SurveyAnswer;
use App\Scopes\PeriodScope;
use App\Services\QueryFilterService;
use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class NpsReportCentral
{
    public function __construct(
        private string $period,
        private string $custom_date = '',
        private  $comparisionFilter = '',
        private  $comparisionCustomDate = '',
        private string $module_type = 'all',
        private array $filters = [],
        private $filtersTo = [],
        private bool $is_custom_nps_filter = false,
        private $surveyProgressFilter = [],
        private array $allActiveModules = [],

    ) {
        if(!count($this->allActiveModules)){
            $this->allActiveModules = getActiveModules();
        }
    }

    public function currentPeriod(): Collection
    {
        $currentPeriod = $this->baseQueryCentral();
        if (isSequence($this->period)) {
            $currentPeriod = QueryFilterService::applySequence($currentPeriod,$this->period,'central_survey_answers','=',tenant());
        }else
        {
            $currentPeriod = QueryFilterService::applyDateFilter($currentPeriod,$this->period,$this->custom_date,'central_survey_answers.survey_answers_updated_at');
        }
        $currentPeriod = QueryFilterService::surveyProgressFilter($this->period,$currentPeriod,$this->surveyProgressFilter,tenant(),'central_survey_answers');
        return collect(
                $currentPeriod
                ->first()
        );
    }

    public function previousPeriod(): Collection
    {


        $previousPeriod = $this->baseQueryCentral();
        if (isSequence($this->comparisionFilter)) {
            $previousPeriod = QueryFilterService::applySequence($previousPeriod,$this->comparisionFilter,'central_survey_answers','=',tenant());
        }else
        {
            $previousPeriod = QueryFilterService::applyPreviousDateFilter($previousPeriod,$this->comparisionFilter,$this->comparisionCustomDate,'central_survey_answers.survey_answers_updated_at');
        }
        $previousPeriod = QueryFilterService::surveyProgressFilter($this->comparisionFilter,$previousPeriod,$this->surveyProgressFilter,tenant(),'central_survey_answers');
        
        return collect(
                $previousPeriod
                ->first()
        );
    }

    public function baseQueryCentral() : QueryBuilder
    {
        $selectedModuleType = [];
        if($this->module_type == Tenant::ALL){
            $selectedModuleType = array_keys($this->allActiveModules);
        }
        elseif($this->module_type == Tenant::ALLPULSE)
        {
            $selectedModuleType = array_intersect(array_keys($this->allActiveModules), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]);
        }
        else {
            $selectedModuleType = [$this->module_type];
        }
       
        $tenant = tenant();
        tenancy()->central(function() use(&$base_query,$tenant,$selectedModuleType){
            
            $promoter   = 'SUM(IF(`nps_benchmark_value` >= 9, 1, 0))';
            $detractors = 'SUM(IF(`nps_benchmark_value` <= 6, 1, 0))';
            $passive    = 'SUM(IF(`nps_benchmark_value` BETWEEN 7 AND 8, 1, 0))';
            $parentPromoter = "SUM(IF (`nps_benchmark_value` >= 9 AND central_survey_answers.module_type = 'parent',1,0))";
            $parentDetractors = "SUM(IF (`nps_benchmark_value`<=6 AND central_survey_answers.module_type = 'parent',1,0))";
            $studentPromoter = "SUM(IF (`nps_benchmark_value` >= 9 AND central_survey_answers.module_type = 'student',1,0))";
            $studentDetractor = "SUM(IF (`nps_benchmark_value`<=6 AND central_survey_answers.module_type = 'student',1,0))";
            $employeePromoter = "SUM(IF (`nps_benchmark_value` >= 9 AND central_survey_answers.module_type = 'employee',1,0))";
            $employeeDetractor = "SUM(IF (`nps_benchmark_value`<=6 AND central_survey_answers.module_type = 'employee',1,0))";
            $parentResponses  = "SUM(if(central_survey_answers.module_type = 'parent', 1, 0))";
            $studentResponses = "SUM(if(central_survey_answers.module_type = 'student', 1, 0))";
            $employeeResponses = "SUM(if(central_survey_answers.module_type = 'employee', 1, 0))";
            $valueCount = "COUNT(`nps_benchmark_value`)";
            $base_query = DB::table('central_survey_answers')
            ->selectRaw(<<<SQL
                central_survey_answers.id,
                central_survey_answers.value,
                COUNT(Distinct central_survey_answers.survey_invite_id) as responses,
                $parentPromoter as parent_promoters,
                $parentDetractors as parent_detractors,
                $studentPromoter as student_promoters,
                $studentDetractor as student_detractors,
                $employeePromoter as employee_promoters,
                $employeeDetractor as employee_detractors,
                $parentResponses as parent_responses,
                $studentResponses as student_responses,
                $employeeResponses as employee_responses,
                central_survey_answers.questionable_id,
                central_survey_answers.questionable_type,
                central_survey_answers.module_type,
                IFNULL($promoter,0) as promoters,
                IFNULL($detractors, 0) as detractors,
                IFNULL($passive, 0) as passive,
                IFNULL(ROUND($promoter / $valueCount * 100), 0) as promoters_percentage,
                IFNULL(ROUND($detractors / $valueCount * 100), 0) as detractors_percentage,
                IFNULL(ROUND($passive / $valueCount * 100), 0) as passive_percentage,
                IFNULL(ROUND(($promoter / $valueCount ) * 100 - ($detractors / $valueCount) * 100),null) as score,
                IFNULL(ROUND($parentPromoter / $parentResponses * 100 - $parentDetractors / $parentResponses * 100),0) as parent_score,
                IFNULL(ROUND($studentPromoter / $studentResponses * 100 - $studentDetractor/ $studentResponses * 100),0) as student_score,
                IFNULL(ROUND($employeePromoter / $employeeResponses * 100 - $employeeDetractor / $employeeResponses * 100),0) as employee_score
            SQL)
            ->whereNotNull('nps_benchmark_value')
            ->whereIn('central_survey_answers.module_type',$selectedModuleType);
            $base_query = QueryFilterService::npsFilter($base_query,$this->filtersTo);
            $base_query = QueryFilterService::applyFilterAnswers($base_query,$this->filters, $this->filtersTo,'central_survey_answers',$tenant);
            
        $base_query  = $base_query
            ->where('central_survey_answers.question_type', Question::Questionable_TYPE)
            ->where('central_survey_answers.tenant_id',$tenant->id);
        });       
        return $base_query;
    }

}
