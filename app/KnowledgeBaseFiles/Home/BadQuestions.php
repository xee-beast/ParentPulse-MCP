<?php

namespace App\Http\Livewire\Tenant\Home;

use App\Reports\LikertAnswersReport;
use App\Scopes\PeriodScope;
use Illuminate\Support\Collection;
use Livewire\Component;
use Session;

/**
 * @property-read Collection $answers
 * @property-read string $title
 * */
class BadQuestions extends Component
{
    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $module_type = '';
    public bool $hideBadQuestion = false;

    protected $listeners = [
        'filters::period' => 'setPeriod',
        'home-page::change_module_type' => 'setModuleType',
        'survey-deleted' => '$refresh'
    ];

    public function mount(string $module_type): void {

        if(Session::has('period')){
            $this->period=Session::get('period');
        }
        if(Session::has('custom_period')){
            $this->custom_date=Session::get('custom_period');
          }
        $this->module_type = $module_type;
    }

    public function getAnswersProperty(): Collection
    {
        $this->hideBadQuestion = false;
        $filters = getFiltersFromRestrictions($this->module_type) ?? [];
        $answers  = (new LikertAnswersReport($this->period,$this->custom_date,$filters,scopeActiveSurvey: false, module_type:$this->module_type,calculateBenchmakr: false))
            ->run();


        $answers = $answers->orderBy('score')
        ->limit(3)
        ->get();

          if($this->period == PeriodScope::Custom){

              if(isset($this->custom_date) && !empty($this->custom_date)){
                  $dates = convertCustomDateRange($this->custom_date);
                  $dates_diff = $dates['start_date']->diffInDays( $dates['end_date'] );
                  if($dates_diff>365){
                      foreach ($answers as $key=> $answer){
                          $answers[$key]['period_diff'] = 0;
                      }
                  }

              }
          }

          return $answers;
    }

    public function getTitleProperty(): string
    {
        return match ($this->period) {
            PeriodScope::ALL_TIME          => 'Here are your lowest scores all-time',
            PeriodScope::LAST_365_DAYS     => 'Here are your lowest scores over the past 365 days',
            PeriodScope::LAST_THREE_MONTHS => 'Here are your lowest scores over the past three months',
            PeriodScope::LAST_THIRTY_DAYS  => 'Here are your lowest scores over the past 30 days.',
            PeriodScope::TODAY             => 'Here are your lowest scores over the past day',
            PeriodScope::Custom             => 'Here are your lowest scores over the custom date range',
            default                         =>  '',
        };
    }

    public function setPeriod(string $period, string $custom_date): void
    {
        if(Session::has('period')){
            $this->period=Session::get('period');
        }
        if(Session::has('custom_period')){
            $this->custom_date=Session::get('custom_period');
          }
    }

    public function setModuleType(string $type): void
    {
        $this->module_type = $type;
    }

    public function render()
    {
        return view('livewire.tenant.home.bad-questions');
    }
}
