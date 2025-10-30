<?php

namespace App\Http\Livewire\Tenant\Dashboard\Nps;

use App\Models\Question;
use App\Models\Tenant;
use App\Models\Tenant\Question as TenantQuestion;
use App\Models\Tenant\SurveyInvite;
use Cache;
use Illuminate\Database\Eloquent\Builder;
use App\Reports\{NpsBenchmarkReport, NpsBenchmarkReportCentral, NpsReport, NpsReportCentral};
use App\Scopes\PeriodScope;
use DB;
use Illuminate\Support\Collection;
use Livewire\Component;
use Matrix\Decomposition\QR;
use Session;
use Str;

/**
 * @property-read Collection $currentPeriod
 * @property-read Collection $previousPeriod
 * @property-read float|int|null $schoolsBenchmark
 */
class OverallBenchmark extends Component
{

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';

    public bool $applyBenchmarkFilter = false;

    public bool $graphsModal = false;
    public bool $is_custom_nps_filter = false;
    public array $filters = [];
    public array $custom_nps_filter = [];
    public string  $module_type = 'all';
    public $currentSchoolScore = 0;
    public $onlyCustomFilter = false;
    public $getPermissionForModule = [];
    public $allActiveModules = [];
    public $setResultEmpty = false;
    public array $surveyProgressFilter = [SurveyInvite::ANSWERED, SurveyInvite::SEND];
    public $benchmarkSchoolFilter = null;

    public $schoolsBenchmark = ['benchmark' => '...', 'percentile' => '...'];

    protected $listeners = [
        'filters::clear-cache' => 'clearCache',
    ];

    public function mount()
    {
        if (Session::has('period')) {
            $this->period = Session::get('period');
        }
        if (Session::has('custom_period')) {
            $this->custom_date = Session::get('custom_period');
        }
        $this->allActiveModules = getActiveModules();
        if (!$this->filters) {
            $this->filters = getFiltersFromRestrictions($this->module_type);
        }
    }


    public function getCustomFilterProperty()
    {
        return $this->onlyCustomFilter;
    }

    // public function setFilters(array $filters, $setResultEmpty = false, $toRemove = []): void
    // {
    //     $this->filters = $filters;
    //     $this->setResultEmpty = $setResultEmpty;

    //     if (count($this->filters) == 0) {
    //         $this->is_custom_nps_filter = false;
    //         $this->custom_nps_filter = [];
    //     } else {
    //         if (isset($this->filters) && isset($this->filters['custom_nps_filter'])) {
    //             $this->is_custom_nps_filter = true;
    //             foreach ($this->filters['custom_nps_filter'] as $nps_filter) {
    //                 $this->custom_nps_filter = $nps_filter;
    //             }
    //             unset($this->filters['custom_nps_filter']);
    //         } else {
    //             $this->is_custom_nps_filter = false;
    //             $this->custom_nps_filter = [];
    //         }
    //     }
    //     $this->surveyProgressFilter = setSurveyProgressFilter($this->filters);
    //     unset($this->filters['survey_progess']);
    // }


    public function checkCustomQuestions()
    {
        $this->onlyCustomFilter = false;
        if ($this->applyBenchmarkFilter) {

            /*
            * Check if the Question is Demographic and Editable By client
            * is selected and user is trying to apply filter on the custom
            * answers created by school;
            * 
            * */
            if (isset($this->filters[Question::class]) && count($this->filters[Question::class]) == 1) {
                $questionIds = array_keys($this->filters[Question::class]);
                if (isset($questionIds[0])) {
                    $firstKey             = $questionIds[0];
                    $firstAnswer          = array_keys($this->filters[Question::class][$firstKey])[0];
                    $editableClientExists = Tenant\EditableQuestionAnswer::where('questionable_id', $firstKey)
                        ->where('questionable_type', Question::class)
                        ->where('name', $firstAnswer)
                        ->where('custom_answer', 1)->exists();
                    if ($editableClientExists) {
                        $this->onlyCustomFilter = true;
                        $this->emit('dashboard::onlyCustomFilter', $this->onlyCustomFilter);
                    }
                }
            }
            /*
             *  Custom Question Answers filter should not be
             *  applied on NPS card benchmark and percentile
             *  should be 0
             * */
            if (isset($this->filters[TenantQuestion::class])) {
                $this->onlyCustomFilter = true;
                $this->emit('dashboard::onlyCustomFilter', $this->onlyCustomFilter);
            }
        }
    }
    public function loadBenchmarkOverall()
    {
        if ($this->setResultEmpty) {
            Cache::forget(getCacheKeyUserBased('SchoolsBenchmarkDashboard'));
            Cache::remember(getCacheKeyUserBased('SchoolsBenchmarkDashboard'), 3600, function () {
                return  'N/A';
            });
            $this->schoolsBenchmark = ['benchmark' => '...', 'percentile' => 'N/A'];
            return $this->schoolsBenchmark;
        }
        // Check Custom Question Or Editable Question in Filter Then no need to calculate Benchmark
        $this->checkCustomQuestions();
        if ($this->onlyCustomFilter) {
            $this->schoolsBenchmark = ['benchmark' => '...', 'percentile' => 'N/A'];
            return $this->schoolsBenchmark;
        }
        $benchmark_avg = 0;
        $percentile = 0;

        $cacheKey = getCacheKeyDashboard('nps_benchmark_report_central', $this->module_type, $this->period, '', [], [], true, $this->surveyProgressFilter);
        
        // Return cached result if exists
        $time = config('constants.CACHE_TIME');
        if ($this->applyBenchmarkFilter) {
            $cacheKey = $this->applyBenchmarkFilter ? $cacheKey . '_apply_benchmark' . rand(1, 1000000) : $cacheKey;
            $time = 0;
        }
        
        $cacheKey = $this->benchmarkSchoolFilter ? $cacheKey .'_'. strtolower(str_replace(' ', '_', implode('_', $this->benchmarkSchoolFilter))) : $cacheKey;
        
        $nps_benchmark_report =  Cache::remember($cacheKey, $time, function () {
            return (new NpsBenchmarkReportCentral($this->applyBenchmarkFilter, $this->filters, $this->custom_nps_filter, $this->surveyProgressFilter, $this->period, $this->custom_date, $this->module_type, $this->allActiveModules, $this->getPermissionForModule, $this->benchmarkSchoolFilter, tenant()))->calculateNpsBenchmark();
      
        });

        $benchmark = [];
        foreach ($nps_benchmark_report as $benchmarkReport) {
            $benchmark[] = $benchmarkReport->benchmark;
        }
        if (count($benchmark)) {
            $sum = array_sum($benchmark);
            $count = count($benchmark);
            $benchmark_avg = Round($sum / $count);
            // Only Calculate Percentile When Apply BenchmarkFilter Click
            if ($this->applyBenchmarkFilter) {
                $filteredArray = array_filter($benchmark, function ($value) {
                    return round($value) < $this->currentSchoolScore;
                });
                $percentile = Round((count($filteredArray) / $count) * 100);
            } else {
                $percentile = 'N/A';
            }
        }
        Cache::forget(getCacheKeyUserBased('SchoolsBenchmarkDashboard'));
        Cache::remember(getCacheKeyUserBased('SchoolsBenchmarkDashboard'), 3600, function () use ($benchmark_avg, $percentile) {
            return  ['benchmark' => $benchmark_avg, 'percentile' => $percentile];
        });
        return $this->schoolsBenchmark = ['benchmark' => $benchmark_avg, 'percentile' => $percentile];
    }

    public function clearCache()
    {
        $cacheKey = getCacheKeyDashboard('nps_benchmark_report_central', $this->module_type, '', '', [], [], true, $this->surveyProgressFilter);
        Cache::forget($cacheKey);
        Cache::forget(getCacheKeyUserBased('SchoolsBenchmarkDashboard'));
    }

  
}
