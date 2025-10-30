<?php

namespace App\Reports;
use App\Models\{Question, Tenant};
use App\Services\QueryFilterService;
use App\Traits\Dashboard\DashboardResultTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class NpsBenchmarkReportCentral
{
    use DashboardResultTrait;
    public bool $applyBenchmarkFilter = false;

    public array $filters = [];
    public array $custom_nps_filters = [];
    public array $surveyProgressFilter = [];
    public $period = null;
    public $custom_date = null;
    public string $module_type = 'all';
    public $allActiveModules = [];
    public $selectedModuleType = [];
    public $getPermissionForModule = [];
    public $benchmarkSchoolFilter = null;
    public $tenant = null;

    public function __construct(bool $applyBenchmarkFilter = false, array $filters, array $custom_nps_filters,array $surveyProgressFilter,string $period=null,string $custom_date=null,string $module_type =null, array $allActiveModules = [],$getPermissionForModule = [], $benchmarkSchoolFilter = null, $tenant = null) {
        $this->applyBenchmarkFilter = $applyBenchmarkFilter;
        $this->filters = $filters;
        $this->custom_nps_filters = $custom_nps_filters;
        $this->surveyProgressFilter = $surveyProgressFilter;
        $this->period = $period;
        $this->custom_date = $custom_date;
        $this->module_type = $module_type;
        if(!count($this->allActiveModules)){
            $this->allActiveModules = getActiveModules();
        }
        $this->getPermissionForModule = $getPermissionForModule;
        $this->benchmarkSchoolFilter = $benchmarkSchoolFilter;
        $this->tenant = $tenant;
    }

    public function calculateNpsBenchmark() {
   
        $benchmark = collect();
        tenancy()->central(function () use(&$benchmark){
            $this->setSelectModule();
            $benchmark  =  DB::table('central_survey_answers')
                ->select(DB::raw('(
                    (
                        SUM(CASE WHEN nps_benchmark_value >= 9 THEN 1 ELSE 0 END) / COUNT(central_survey_answers.id) * 100
                    ) - (
                        SUM(CASE WHEN nps_benchmark_value <= 6 THEN 1 ELSE 0 END) / COUNT(central_survey_answers.id) * 100
                    )
                ) as benchmark'))
                ->where('central_survey_answers.question_type', Question::NPS_ID)
                ->whereNotNull('central_survey_answers.nps_benchmark_value')
                ->whereIn('central_survey_answers.module_type', $this->selectedModuleType);
                
                if (isSequence($this->period)){
                    $benchmark = QueryFilterService::applySequence($benchmark,$this->period,'central_survey_answers');    
                }
                
                if($this->applyBenchmarkFilter)
                {   
                    if (!isSequence($this->period)) {
                        $benchmark = QueryFilterService::applyDateFilter($benchmark,$this->period,$this->custom_date,'central_survey_answers.survey_answers_updated_at');
                    }
                    $benchmark = QueryFilterService::npsFilter($benchmark,$this->custom_nps_filters);
                    $benchmark = QueryFilterService::applyBenchmarkFilterAnswers($benchmark,$this->filters,'central_survey_answers');
					$benchmark = QueryFilterService::surveyProgressFilter($this->period, $benchmark,$this->surveyProgressFilter,tenant(),'central_survey_answers');
                }
                $benchmark = QueryFilterService::surveyProgressFilter($this->period, $benchmark,$this->surveyProgressFilter,tenant(),'central_survey_answers');

                
                if ($this->tenant) {
                    $benchmark = $benchmark->where('central_survey_answers.client_type_id', $this->tenant->client_type_id);
                    if ($this->benchmarkSchoolFilter) {
                        $tenantIds = getTenantsByClientTypeAndOtherFields($this->benchmarkSchoolFilter, $this->tenant->client_type_id, $this->tenant->id);
                        $benchmark = $benchmark->whereIn('central_survey_answers.tenant_id',$tenantIds);
                    }
        
                }
                
                $benchmark = $benchmark->groupBy('central_survey_answers.tenant_id')
                ->get();
        }); 

        return $benchmark;
    }
    
}
