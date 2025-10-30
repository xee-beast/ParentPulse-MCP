<?php

namespace App\Http\Livewire\Tenant\Home;

use App\Models\Tenant;
use App\Models\Tenant\SurveyCycle;
use App\Scopes\PeriodScope;
use App\Traits\Sequence\SurveyCycleTrait;
use Livewire\Component;
use Session;
use Str;

class Index extends Component
{
    use SurveyCycleTrait;
    public string $custom_date = '';
    public string $module_type;
    public array $enabled_modules;
    public $activeTenantModules = [];
    public $showRestrictionModal = false;
    public $sequences;

    public function mount()
    {

        $this->enabled_modules = array_unique(array_merge(
            getPermissionForModule('_view_comments_replies'),
            getPermissionForModule('_view_dashboard')

        ));
        $newSortedArray = [];
        foreach (getActiveModules(true) as $key) {
            if (isset($this->enabled_modules[$key])) {
				if (in_array($key, ["parent","student","employee"])){
					$newSortedArray[$key] = strtolower(clientMapping()->get("map_$key"));
				}else {
					$newSortedArray[$key] = $this->enabled_modules[$key];
				}
                unset($this->enabled_modules[$key]);
            }
        }
        if (!empty($newSortedArray)) {
            $this->enabled_modules = array_merge($newSortedArray, $this->enabled_modules);
        }

        if(Session::has('period')){
            $this->period = Session::get('period');
        }
        if (Session::has('custom_period')) {
            $this->custom_date = Session::get('custom_period');
        }

        $this->enabled_modules = getPulseCustomModules($this->enabled_modules);
        $this->activeTenantModules = getActiveModules(true);

        if (isset($this->enabled_modules[Tenant::ALL])) {
            unset($this->enabled_modules[Tenant::ALL]);
        }

        if (count($this->enabled_modules) > 1) {
            if (
                count($this->activeTenantModules) > 1 && count(array_intersect(array_keys($this->enabled_modules), pulseModules())) > 0 &&
                array_key_exists("all_pulse_surveys", $this->enabled_modules)
            ) {
                $this->module_type = Tenant::ALLPULSE;
            } else {
                $this->module_type = array_key_first($this->enabled_modules);
            }
        } else {
            $this->module_type = array_key_first($this->enabled_modules) ?? "";
        }
        
        if(Session::has('module_type')){
            $this->module_type = Session::get('module_type') ?? "";
        }
        $this->getSurveyCycles();
    }

    public function updatedModuleType(string $type): void
    {
        $this->module_type = $type;
        Session::put('module_type', $this->module_type);
        Session::remove("show_custom");
        if (!in_array($this->module_type, tenant('module_enabled')?->toArray())) {
            Session::put("show_custom", "show");
        }
        $this->emit('home-page::change_module_type', $type);
        $this->getSurveyCycles(false);
    }

    public function updatedPeriod(string $period)
    {
        Session::put('period', $period);
        if ($period !== 'custom') {
            $this->custom_date = '';
            $this->emit('filters::period', $period, $this->custom_date);
        }
    }

    public function updatedCustomDate(string $custom_date)
    {
        Session::put('custom_period', $custom_date);
        if (str_contains($custom_date, 'to')) {
            $this->emit('filters::period', $this->period, $custom_date);
        } else {
            return;
        }
    }
    public function openRestrictionModal($open = true)
    {
        $this->showRestrictionModal = $open;
    }
    public function render()
    {
        return view('livewire.tenant.home.index');
    }
}
