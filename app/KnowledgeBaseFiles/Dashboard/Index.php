<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use App\Models\DashboardMetrics;
use App\Models\Tenant;
use App\Models\Tenant\SurveyCycle;
use App\Scopes\PeriodScope;
use App\Traits\Sequence\SurveyCycleTrait;
use Artisan;
use Carbon\Carbon;
use Livewire\Component;
use Session;
use Str;

class Index extends Component
{
    use SurveyCycleTrait;
    public const ACTIVE_QUESTIONS = 'active_questions';
    public const ALL_QUESTIONS    = 'all_questions';

    public string $filterActiveSurveys = self::ACTIVE_QUESTIONS;

    public string $custom_date = '';
    public string $module_type;
    public array $enabled_modules;
    public $pulseModuleCount;
    public $customSurveyCount;
    public $getPermissionForModule = [];
    public $userPermisisons = [];
    public $activeTenantModules = [];
    public $filters = [];
    public $latestData = false;
    public $sequences;

    public ?int $selectedBenchmarkQuestion = null;

    public function mount()
    {
        if (Session::has('period')) {
            $this->period = Session::get('period');
        }
        if (Session::has('custom_period')) {
            $this->custom_date = Session::get('custom_period');
        }
        $this->getPermissionForModule = getPermissionForModule('_view_dashboard');
        $this->userPermisisons = userPermissions();
        $this->enabled_modules = tenant('module_enabled') ? tenant('module_enabled')->toArray() : [Tenant::PARENT];
        $this->enabled_modules = $this->getPermissionForModule;
        $this->enabled_modules = getPulseCustomModules($this->enabled_modules);
        $this->activeTenantModules = getActiveModules(true);

        foreach ($this->enabled_modules as $key => $module){
			if (in_array($module, [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE])) {
				$this->enabled_modules[$key] = getMappedClientType($module);
			}
		}
     
        // Eliminate All Surveys Option PP-873
        if (isset($this->enabled_modules[Tenant::ALL])) {
            unset($this->enabled_modules[Tenant::ALL]);
        }
        if (count($this->enabled_modules) > 1) {
            // Eliminate All Surveys Option PP-873

            if(count($this->activeTenantModules) > 1 && count(array_intersect(array_keys($this->enabled_modules), pulseModules()))>0 &&
			array_key_exists("all_pulse_surveys", $this->enabled_modules)){
                $this->module_type = Tenant::ALLPULSE;
            } else {
                $this->module_type = array_key_first($this->enabled_modules);
            }
        } else {
            $this->module_type = array_key_first($this->enabled_modules);
        }
        if (Session::has('module_type') && in_array(Session::get('module_type'), $this->enabled_modules)) {
            $this->module_type = Session::get('module_type');
        }
        
        $this->getSurveyCycles();
        if (!$this->filters) {
            $this->filters = getFiltersFromRestrictions($this->module_type);
        }
        $dashboardMetrics = DashboardMetrics::firstOrCreate(['tenant_id'=>tenant()->id]);
        $dashboardMetrics->dashboard_count = $dashboardMetrics->dashboard_count + 1;
        $dashboardMetrics->save();
    }

    protected $queryString = [
        'filterActiveSurveys' => ['except' => self::ACTIVE_QUESTIONS],
        'period'      => ['except' => PeriodScope::DEFAULT_PERIOD],
        'custom_date'      => ['except' => ''],
    ];

    public function updatedFilterActiveSurveys(string $filter)
    {
        $this->emit('filters::surveys', $filter === self::ACTIVE_QUESTIONS);
        incrementDashboardFilterMetrics();
    }

    public function updatedModuleType(string $type): void
    {
        Session::put('module_type', $this->module_type);
        Session::remove("show_custom");
        if (!in_array($this->module_type, tenant('module_enabled')?->toArray())) {
            Session::put("show_custom", "show");
        }
        $this->emit('dashboard-page::change_module_type', $type);
        $this->getSurveyCycles(false);
        incrementDashboardFilterMetrics();
    }

    public function updatedperiod($period)
    {        

        Session::put('period', $period);
        
        if ($period !== PeriodScope::Custom) {
            $this->custom_date = '';
            $this->emit('filters::period', $period, $this->custom_date);
            $this->emit('comparision::reset');
        }
        incrementDashboardFilterMetrics();

    }

    public function updatedCustomDate(string $custom_date)
    {
        if (str_contains($custom_date, ' to ')) {
            Session::put('custom_period',$custom_date);
            $this->emit('filters::period', $this->period,$custom_date);
        }
        else {
            Session::put('custom_period','');
            return;
        }
    }

    public function render()
    {
        return view('livewire.tenant.dashboard.index');
    }

    public function clearCache()
    {
        Artisan::call('nps:process-reports', [
            '--tenant' => tenant()->id,
            '--user' => auth()->user(),
            '--clear' => 'yes'
        ]);
        $this->latestData = true;
        $this->emit('filters::clear-cache');
    }
}
