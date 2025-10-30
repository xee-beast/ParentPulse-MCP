<?php

namespace App\Http\Livewire\Tenant\Home;

use App\Models\Tenant;
use App\Models\Tenant\SurveyAnswer;
use App\Reports\NpsReport;
use App\Scopes\PeriodScope;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use App\Traits\Livewire\{FullTable, HasBatch};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use App\Exports\NpsIndicatorExport;
use App\Jobs\ProcessBenchmarkAnswersReport;
use App\Jobs\ProcessMultipleChoiceAnswersReport;
use App\Models\Tenant\SurveyInvite;
use Illuminate\Support\Facades\Cache;
use Session;

/**
 * @property-read Collection $currentPeriod
 * @property-read Collection $previousPeriod
 * @property-read int $pointer
 */
class NpsIndicator extends Component
{
    use FullTable, HasBatch;
    private const PROMOTERS  = 'promoters';
    private const PASSIVE    = 'passive';
    private const DETRACTORS = 'detractors';
    public const INDICATORS  = [
        self::PROMOTERS  => 'bg-green-500',
        self::PASSIVE    => 'bg-yellow-400',
        self::DETRACTORS => 'bg-red-500',
    ];
    public $IndicatorModal = false;

    public $selected_indicator = null;

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $module_type = '';
    public bool $shownpsBox = false;
    public array $enabled_modules;
    public array $filters = [];
    public $filtersSelected = false; 
    public array $surveyProgressFilter = [SurveyInvite::ANSWERED,SurveyInvite::SEND];
    

    protected $listeners = [
        'filters::period' => 'setPeriod',
        'indicators::open-indicator' => 'openIndicator',
        'home-page::change_module_type' => 'setModuleType',
        'survey-deleted' => '$refresh'
    ];

    public function mount(string $module_type): void
    {
        $this->enabled_modules = array_unique(array_merge(
            getPermissionForModule('_view_comments_replies'),
            getPermissionForModule('_view_dashboard')

        ));
        if(Session::has('period')){
            $this->period=Session::get('period');
        }
        if(Session::has('custom_period')){
            $this->custom_date=Session::get('custom_period');
          }
        $this->module_type = $module_type;
        $this->showNPSBox();
        $this->surveyProgressFilter = getSurveyProgressFilter([]);
        $this->surveyProgressFilter = Session::get('surveyProgressFilter')?Session::get('surveyProgressFilter'):$this->surveyProgressFilter;
        if(count($this->surveyProgressFilter) == 1 )
        {
            if( in_array(SurveyInvite::ANSWERED,$this->surveyProgressFilter))
            {
                $this->filtersSelected  = true;
            }else
            {
                $this->surveyProgressFilter =getSurveyProgressFilter([]);
            }
        }
    }  

    public function getCurrentPeriodProperty(): Collection
    {
        $this->setFilters($this->filters);
        $cacheKey = getCacheKeyDashboard('nps_card',$this->module_type,$this->period,$this->custom_date,$this->filters,[],true,$this->surveyProgressFilter);
       // Return cached result if exists
       return Cache::remember($cacheKey, config('constants.CACHE_TIME'), function () {
            return (new NpsReport($this->period, $this->custom_date, $this->module_type, $this->filters, $this->surveyProgressFilter))->currentPeriod();
        });

        return $npsReport;
    }

    public function getPreviousPeriodProperty(): Collection
    {
        $cacheKey = getCacheKeyDashboard('nps_report_central',$this->period, $this->custom_date, $this->module_type,[],[],true,$this->surveyProgressFilter);
        $previous_period_nps_report = Cache::remember($cacheKey, config('constants.CACHE_TIME'), function ()  {
            return (new NpsReport($this->period, $this->custom_date, $this->module_type, [], $this->surveyProgressFilter))->previousPeriod();
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
        return $previous_period_nps_report;
    }

    public function getPointerProperty(): int
    {
        $score = $this->currentPeriod->get('score');

        $width = 128;

        return $score * $width / 100;
    }

    public function setPeriod(string $period, string $custom_date): void
    {
        $this->period = $period;
        $this->custom_date = $custom_date;
    }

    public function setModuleType(string $type): void
    {
        $this->module_type = $type;
        $this->showNPSBox();
    }

    public function render()
    {
        return view('livewire.tenant.home.nps-indicator');
    }

    public function openIndicator($indicator, $module_type)
    {

        $this->selected_indicator = $indicator;
        $this->module_type = $module_type;
        $this->resetPage();
        $this->IndicatorModal = true;
    }

    public function getRecordsProperty($paginate = true)
    {
        $surveyRecords = SurveyAnswer::query()
            ->selectRaw(<<<SQL
                    survey_answers.id,
                    survey_answers.module_type,
                    survey_answers.created_at as date_submitted,
                    survey_answers.survey_invite_id,
                    CONCAT(people.first_name, ' ', people.last_name) as people_name,
                    CONCAT(students.first_name, ' ', students.last_name) as student_name,
                    CONCAT(employees.firstname, ' ', employees.lastname) as employee_name,
                    people.first_name as p_fname,people.last_name as p_lname,
                    students.first_name as s_fname,students.last_name as s_lname,
                    employees.firstname as e_fname,employees.lastname as e_lname,
                    people.email as p_email,
                    students.email as s_email,
                    employees.email as l_email,
                    survey_answers.value,
                    survey_invites.anonymity,
                    survey_invites.updated_at
                   
            SQL)

            ->join('survey_invites', 'survey_invites.id', '=', 'survey_answers.survey_invite_id')
            ->leftJoin('people', 'people.id', '=', 'survey_invites.people_id')
            ->leftJoin('students', 'students.id', '=', 'survey_invites.student_id')
            ->leftJoin('employees', 'employees.id', '=', 'survey_invites.employee_id')
            ->where('value', 'not LIKE', '%N/A%');
            if(!$paginate){
                $surveyRecords = $surveyRecords->where('survey_invites.anonymity', 0);
            }
            // ->where('survey_invites.anonymity', 0)
            $surveyRecords = $surveyRecords->where('survey_invites.status', 'answered')

            ->nps()
            //            ->latestSurvey()
            ->when(isSequence($this->period), function ($query) {
                $query->sequenceFilter($this->period);
            }, function ($query) {
                $query->periodFilter($this->period, $this->custom_date);
            });
        if (!is_null($this->selected_indicator)) {
            if ($this->selected_indicator == 'promoters') {

                $surveyRecords = $surveyRecords->where('value', '>=', 9);
            }
            if ($this->selected_indicator == 'detractors') {
                $surveyRecords = $surveyRecords->where('value', '<=', 6);
            }
            if ($this->selected_indicator == 'passive') {
                $surveyRecords = $surveyRecords->where('value', '>=', 7)->where('value', '<=', 8);
            }
        }

        // if ($this->module_type !== "all") {
        //     $surveyRecords = $surveyRecords->module($this->module_type);
        // }else{
        //     if(getActiveModules()){
        //         $surveyRecords=$surveyRecords->whereIn('survey_answers.module_type', getActiveModules());

        //     }
        // }
        if($this->module_type == Tenant::ALL){
            $surveyRecords = $surveyRecords->whereIn('survey_answers.module_type',array_keys(getActiveModules()));
        }
        elseif($this->module_type == Tenant::ALLPULSE)
        {
            $surveyRecords = $surveyRecords->whereIn('survey_answers.module_type',array_intersect(array_keys(getActiveModules()), pulseModules()));
        }
        else {
            $surveyRecords = $surveyRecords->module($this->module_type);
        }
        if($paginate) {
            $surveyRecords = $surveyRecords->orderBy($this->sortBy,
                $this->sortDirection)->paginate($this->perPage)->onEachSide(1);
        } else{
            return $surveyRecords = $surveyRecords->orderBy($this->sortBy,
                $this->sortDirection)->get();
        }
        return $surveyRecords;
    }

    public function export()
    {
        if ($this->selected_indicator && $this->period) {
            $npsIndicator = $this->getRecordsProperty(false);
            $fileName    = sprintf('export_nps_indicators-%s.xlsx', date('Y-m-d'));
            return Excel::download(new NpsIndicatorExport($npsIndicator), $fileName);
        }
        return false;
    }

    public function showNPSBox()
    {
        $this->shownpsBox = false;
        if (
            (
                array_key_exists(Tenant::PARENT, $this->enabled_modules) ||
                array_key_exists(Tenant::EMPLOYEE, $this->enabled_modules) ||
                array_key_exists(Tenant::STUDENT, $this->enabled_modules) ||
                array_key_exists(Tenant::ALLPULSE, $this->enabled_modules)
            )
            &&
            array_intersect(array_keys(getPermissionForModule('_view_dashboard')), getActiveModules(true))) {
            $this->shownpsBox = true;
            // Incase of custom survey, dont show nps card
            if (!in_array($this->module_type, pulseModules()) && $this->module_type!='all' && $this->module_type!='all_pulse_surveys' ) {
                $this->shownpsBox = false;
            }
            elseif(in_array($this->module_type, pulseModules()) && count(getActiveModules(true)) > 0 && !in_array($this->module_type,array_keys(getPermissionForModule('_view_dashboard')))){
                $this->shownpsBox = false;
            }
            if(count(array_intersect(array_keys(getPermissionForModule('_view_dashboard')), pulseModules())) == count(getActiveModules(true)) ||
               searchValueInArray($this->module_type . '_view_dashboard', userPermissions())){
                $this->shownpsBox = true;
            } else{
                $this->shownpsBox = false;
            }
        }
    }

    public function setFilters(){
     
        $this->filters = getFiltersFromRestrictions($this->module_type);
    }

    public function updatedFiltersSelected($value)
    {
        if ($value) {
            $this->surveyProgressFilter = [SurveyInvite::ANSWERED];
            Session::put('surveyProgressFilter', $this->surveyProgressFilter);
        } else {
            $this->surveyProgressFilter = [SurveyInvite::ANSWERED, SurveyInvite::SEND];
            Session::put('surveyProgressFilter', $this->surveyProgressFilter);
        }
    }
 
}
