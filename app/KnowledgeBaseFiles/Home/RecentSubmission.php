<?php

namespace App\Http\Livewire\Tenant\Home;

use App\Models\Tenant;
use App\Models\Tenant\SurveyAnswer;
use App\Scopes\PeriodScope;
use Livewire\Component;
use Livewire\WithPagination;
use Session;
use Illuminate\Database\Eloquent\Builder;

class RecentSubmission extends Component
{

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $module_type = '';
    public bool   $modal = false;
    public const SORT_ASC        = 'asc';
    public const SORT_DESC       = 'desc';
    public const SORT_DIRECTIONS = [
        self::SORT_ASC  => 'Oldest',
        self::SORT_DESC => 'Newest',
    ];
    public string $search = '';
    public array $filters = [];
    public $sortDirection = self::SORT_DESC;
    use WithPagination;
    public $permissions;
    protected $listeners = [

        'filters::period' => 'setPeriod',
        'home-page::change_module_type' => 'setModuleType',
        'survey-deleted' => '$refresh',
        'close-recent-submission-modal' => 'closeModal'
    ];

	public function closeModal()
	{
		$this->modal = false;
	}


    public function getAnswersProperty()
    {
        $this->filters = getFiltersFromRestrictions($this->module_type);
        return $this->getAnswers()
        ->groupBy('survey_answers.survey_invite_id')
        ->orderBy('survey_invites.updated_at','desc')
        ->take(5)
        ->get();
    }

    public function mount(string $module_type): void {
        if(Session::has('period')){
            $this->period=Session::get('period');
        }
        if(Session::has('custom_period')){
            $this->custom_date=Session::get('custom_period');
          }
        $this->module_type = $module_type;
    }


    public function setPeriod(string $period,string $custom_date): void
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
        return view('livewire.tenant.home.recent-submission');
    }

    public function showAllSubmissions() {
        $this->modal = true;
        $this->reset('search','sortDirection');
        $this->resetPage();
    }
    public function updatedSearch(){
        $this->resetPage();
    }

    public function getRecordsProperty(){

        return $this->getAnswers()
        ->when($this->search, function ($query) {
            if (strtolower($this->search) == 'anonymous') {
                $query->where('anonymity', 1);
            } else {
                $customEmailQuery = "IF (
                    survey_invites.people_id is not NULL, 
                    CONCAT(people.first_name, ' ', people.last_name) LIKE '%$this->search%' and anonymity = 0,
                    IF( 
                        survey_invites.student_id is not NULL, 
                        CONCAT(students.first_name, ' ', students.last_name) LIKE '%$this->search%' and anonymity = 0,
                      IF( 
                        survey_invites.employee_id is not NULL, 
                        CONCAT(employees.firstname, ' ', employees.lastname) LIKE '%$this->search%' and anonymity = 0,
                        custom_email LIKE  '%$this->search%' and anonymity = 0
                        )   
                    )
                )";
                $query->whereRaw("((CONCAT(people.first_name, ' ', people.last_name) LIKE '%$this->search%' and anonymity = 0 )
                OR (CONCAT(students.first_name, ' ', students.last_name) LIKE '%$this->search%' and anonymity = 0)
                OR (CONCAT(employees.firstname, ' ', employees.lastname) LIKE '%$this->search%' and anonymity = 0 )
                OR ($customEmailQuery)
                )");
            }

        })
        ->groupBy('survey_answers.survey_invite_id')
        ->orderBy('survey_invites.updated_at',$this->sortDirection)
        ->paginate(5)
        ->onEachSide(0);
    }

    public function getAnswers() {
        $dashboardPermission = getPermissionForModule('_view_dashboard');
        $enabled_modules = array_unique(array_merge(
//            getPermissionForModule('_view_comments_replies'),
            $dashboardPermission

        ));
        $this->permissions = array_keys($enabled_modules);
        $answers = SurveyAnswer::query()
            ->selectRaw(<<<SQL
                survey_answers.*,custom_email,
                CONCAT(people.first_name, ' ', people.last_name) as people_name,
                CONCAT(students.first_name, ' ', students.last_name) as student_name,
                CONCAT(employees.firstname, ' ', employees.lastname) as employee_name
            SQL)
            ->join('survey_invites', 'survey_invites.id', 'survey_answers.survey_invite_id')
            ->leftJoin('people', 'people.id', 'survey_invites.people_id')
            ->leftJoin('students', 'students.id', 'survey_invites.student_id')
            ->leftJoin('employees', 'employees.id', 'survey_invites.employee_id')
            ->when(isSequence($this->period), function ($query) {
                $query->sequenceFilter($this->period);
            }, function ($query) {
                $query->periodFilter($this->period, $this->custom_date);
            })
            ->filterAnswers($this->filters,[])
            ->where('survey_invites.status','answered');
            if($this->module_type == Tenant::ALL){
                $answers = $answers->whereNotNull('survey_answers.module_type')
                ->whereIn('survey_answers.module_type', array_keys($enabled_modules));
            }
            elseif($this->module_type == Tenant::ALLPULSE)
            {
                $answers = $answers->whereIn('survey_answers.module_type',array_intersect(array_keys($dashboardPermission), pulseModules()));
            }
            else {
                $answers = $answers->whereIn('survey_answers.module_type',array_intersect(array_keys($dashboardPermission), [$this->module_type]));
            }

        return $answers;
    }
}
