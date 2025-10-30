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
class Index extends Component
{
    public const PROMOTERS  = 'promoters';
    public const PASSIVE    = 'passive';
    public const DETRACTORS = 'detractors';

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';

    public ?string $activeChart = null;

    public bool $applyBenchmarkFilter = false;

    public bool $graphsModal = false;
    public bool $is_custom_nps_filter = false;
    public array $filters = [];
    public array $custom_nps_filter = [];
    public string  $module_type = 'all';
    public $currentSchoolScore = 0;
    public $onlyCustomFilter = false;
    public $getPermissionForModule = [];
    public $activeTenantModules = [];
    public $allActiveModules = [];
    public $setResultEmpty = false;
    public array $surveyProgressFilter = [SurveyInvite::ANSWERED,SurveyInvite::SEND];
    public $comparisionFilter = '';
    public string $comparisionCustomDate = '';
    public $benchmarkSchoolFilter = null;


    protected $listeners = [
        'filters::period'            => 'setPeriod',
        'filters::apply'             => 'setFilters',
        'filters::comparision'          => 'setComparisionFilter',
        'comparision::reset'       => 'resetComparision',
        'dashboard::open-graphs-nps' => 'openGraphs',
        'dashboard-page::change_module_type' => 'setModuleType',
        'filters::apply-benchmark'           => 'setApplyBenchmarkFilters',
        'filters::clear-cache' => 'clearCache',
        'set-benchmark-school-filter' => 'setBenchmarkSchoolFilter',
    ];

    public function mount(){
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
        $this->surveyProgressFilter = getSurveyProgressFilter($this->filters);
    }

    public function getCurrentPeriodProperty(): Collection
    {
        if ($this->setResultEmpty) {
            Cache::forget(getCacheKeyUserBased('currentPeriodDashboard'));
            Cache::remember(getCacheKeyUserBased('currentPeriodDashboard'), 3600, function ()  {
                return  collect([]);
            });
            return collect([]);
        }
        $cacheKey = getCacheKeyDashboard('nps_card',$this->module_type,$this->period,$this->custom_date,$this->filters,$this->custom_nps_filter,surveyProgressFilter:$this->surveyProgressFilter);
        // Return cached result if exists
        $cacheKey =  $this->comparisionFilter ? $cacheKey . '_' . $this->comparisionFilter . '_' . md5($this->comparisionCustomDate) : $cacheKey;
        $currentPeriod =  Cache::remember($cacheKey, config('constants.CACHE_TIME'), function ()  {
        return (new NpsReportCentral($this->period, $this->custom_date,$this->comparisionFilter,$this->comparisionCustomDate, $this->module_type, $this->filters, $this->custom_nps_filter, $this->is_custom_nps_filter,$this->surveyProgressFilter, $this->allActiveModules))
            ->currentPeriod();
        });

        $this->currentSchoolScore = $currentPeriod['score'] ?? 0; 
        Cache::forget(getCacheKeyUserBased('currentPeriodDashboard'));
        Cache::remember(getCacheKeyUserBased('currentPeriodDashboard'), 3600, function () use($currentPeriod) {
            return  $currentPeriod;
        });
        return $currentPeriod;
    }
    public function setApplyBenchmarkFilters(bool $apply)
    {
        $this->applyBenchmarkFilter = $apply;
    }

    public function getPreviousPeriodProperty(): Collection
    {
        if ($this->setResultEmpty) {
        Cache::forget(getCacheKeyUserBased('PreviousPeriodDashboard'));
        Cache::remember(getCacheKeyUserBased('PreviousPeriodDashboard'), 3600, function ()  {
            return collect([]) ;
        });
        return collect([]);
        }
        $cacheKey = getCacheKeyDashboard('nps_report_central',$this->period,$this->custom_date,$this->module_type,$this->filters,$this->custom_nps_filter,surveyProgressFilter:$this->surveyProgressFilter);
        $cacheKey =  $this->comparisionFilter ? $cacheKey . '_' . $this->comparisionFilter . '_' . md5($this->comparisionCustomDate) : $cacheKey;
        $previous_period_nps_report = Cache::remember($cacheKey, config('constants.CACHE_TIME'), function ()  {
            return (new NpsReportCentral($this->period, $this->custom_date,$this->comparisionFilter,$this->comparisionCustomDate, $this->module_type, $this->filters, $this->custom_nps_filter, $this->is_custom_nps_filter,$this->surveyProgressFilter, $this->allActiveModules))
            ->previousPeriod();
        });

        if ($this->period == PeriodScope::Custom) {

            if (isset($this->custom_date) && !empty($this->custom_date)) {

                $dates = convertCustomDateRange($this->custom_date);
                $dates_diff = $dates['start_date']->diffInDays($dates['end_date']);
                if ($dates_diff > 365) {
                    $previous_period_nps_report['promoters_percentage'] = 0;
                    $previous_period_nps_report['detractors_percentage'] = 0;
                }
            }
        }
        Cache::forget(getCacheKeyUserBased('PreviousPeriodDashboard'));
        Cache::remember(getCacheKeyUserBased('PreviousPeriodDashboard'), 3600, function () use ($previous_period_nps_report) {
            return  $previous_period_nps_report;
        });
        return $previous_period_nps_report;
    }



    public function openGraphsModal(): void
    {
        $this->emit('dashboard::set-nps-question', true, $this->period, $this->custom_date, $this->module_type);
        $this->graphsModal = true;
    }

    public function getCustomFilterProperty(){
        return $this->onlyCustomFilter;
    }

    public function setFilters(array $filters, $setResultEmpty = false, $toRemove = []): void
    {

        $this->filters = $filters;
        $this->setResultEmpty = $setResultEmpty;

        if (count($this->filters) == 0) {
            $this->is_custom_nps_filter = false;
            $this->custom_nps_filter = [];
        } else {
            if (isset($this->filters) && isset($this->filters['custom_nps_filter'])) {
                $this->is_custom_nps_filter = true;
                foreach ($this->filters['custom_nps_filter'] as $nps_filter) {
                    $this->custom_nps_filter = $nps_filter;
                }
                unset($this->filters['custom_nps_filter']);
            } else {
                $this->is_custom_nps_filter = false;
                $this->custom_nps_filter = [];
            }
        }
        $this->surveyProgressFilter = setSurveyProgressFilter($this->filters);
        unset($this->filters['survey_progess']);
    }

    public function setPeriod(string $period, string $custom_date): void
    {
        $this->period = $period;
        $this->custom_date = $custom_date;
    }

    public function setModuleType(string $type): void
    {
        $this->module_type = $type;
    }

    public function render()
    {
        return view('livewire.tenant.dashboard.nps.index');
    }


  
    
    public function setComparisionFilter($comparisionFilter, string $comparisionCustomDate): void
    {
        $this->comparisionFilter = $comparisionFilter;
        $this->comparisionCustomDate = $comparisionCustomDate;
    }
    public function resetComparision(){
        $this->comparisionFilter = '';
    }

    public function clearCache()
    {
        $cacheKey = getCacheKeyDashboard('nps_card',$this->module_type,$this->period,$this->custom_date,$this->filters,$this->custom_nps_filter,surveyProgressFilter:$this->surveyProgressFilter);
        Cache::forget($cacheKey);
        $cacheKey = getCacheKeyDashboard('nps_report_central',$this->period,$this->custom_date,$this->module_type,$this->filters,$this->custom_nps_filter,surveyProgressFilter:$this->surveyProgressFilter);
        Cache::forget($cacheKey);
    }

    public function setBenchmarkSchoolFilter($filter = null )
    {
        $this->benchmarkSchoolFilter = $filter;
    }

}
