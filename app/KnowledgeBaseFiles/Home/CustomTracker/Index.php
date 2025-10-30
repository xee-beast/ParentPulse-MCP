<?php

namespace App\Http\Livewire\Tenant\Home\CustomTracker;

use App\Models\Tenant;
use App\Models\Tenant\Tracker;
use App\Scopes\PeriodScope;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Session;

/** @property-read Collection $trackers */
class Index extends Component
{
    protected $listeners = [
        'custom-tracker::created' => '$refresh',
        'custom-tracker::updated' => '$refresh',
        'custom-tracker::delete'  => '$refresh',
        'custom-tracker::notify-period-change' =>'setDefaultPeriod',
        'home-page::change_module_type' => 'setModuleType'
    ];

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $module_type = '';


    public function mount(string $module_type): void {

        if(Session::has('period')){
            $this->period=Session::get('period');
        }
        if(Session::has('custom_period')){
            $this->custom_date=Session::get('custom_period');
          }
        $this->module_type = $module_type;
    }

    public function getTrackersProperty(): Collection
    {

        $trackers = Tracker::where('user_id',user()->id);
        if($this->module_type == Tenant::ALL){
            $trackers = $trackers->whereIn('module_type',array_keys(getActiveModules()));
        }
        elseif($this->module_type == Tenant::ALLPULSE)
        {
            $trackers = $trackers->whereIn('module_type',array_intersect(array_keys(getActiveModules()), pulseModules()));
        }
        else {
            $trackers = $trackers->module($this->module_type);
        }
        // if($this->module_type !=="all"){
        //     $trackers  = $trackers->module($this->module_type);
        // }else{
        //     if(getActiveModules()){
        //         $trackers=$trackers->whereIn('module_type', array_keys(getActiveModules()));
        //     }
        //  }
        $trackers = $trackers->get();

        return $trackers;
    }

    public function setDefaultPeriod(string $period, string $custom_date): void
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
        return view('livewire.tenant.home.custom-tracker.index');
    }
}
