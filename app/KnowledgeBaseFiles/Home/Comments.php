<?php

namespace App\Http\Livewire\Tenant\Home;

use App\Models\Tenant;
use App\Reports\CommentsReport;
use App\Scopes\PeriodScope;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\{Component, WithPagination};
use Session;

/** @property-read LengthAwarePaginator $answers */
class Comments extends Component
{
    use WithPagination;

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $module_type = '';
	public $gradeFilters = null;

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

    public function getAnswersProperty(): LengthAwarePaginator
    {
		$filters = getFiltersFromRestrictions($this->module_type) ?? [];
        $this->gradeFilters = getFilteredGradesFromRestrictions($filters);
        
        $report =  CommentsReport::query($filters);

        if($this->module_type == Tenant::ALL){
            $report = $report->whereIn('survey_answers.module_type',array_keys(getPermissionForModule('_view_comments_replies')));

        } elseif($this->module_type == Tenant::ALLPULSE)
        {
            $report = $report->whereIn('survey_answers.module_type',array_intersect(array_keys(getPermissionForModule('_view_comments_replies')), pulseModules()));
        }else{
            $report = $report->module($this->module_type);

        }

        $report = $report->when(isSequence($this->period), function ($query) {
            $query->sequenceFilter($this->period);
        }, function ($query) {
            $query->periodFilter($this->period,$this->custom_date);
        })
            ->with(['surveyInvite','surveyAnswerComments.user','surveyAnswerForwards','surveyInviteComments','surveyInviteForwards'])
            ->orderBy('survey_answers.updated_at', 'DESC')->get();
            $grouped = $report->mapToGroups(function ($item, $key) {

                $mergedCollection = $item->surveyAnswerComments->concat($item->surveyAnswerForwards)->sortByDesc('created_at');
                $mergedCollection = $mergedCollection->concat($item->surveyInviteComments)->concat($item->surveyInviteForwards)->sortByDesc('created_at');
                $item->mergedComments = $mergedCollection;
                return [$item['token'].'_'.$item['module_type'].'_'.$item['people_name'].'_'.$item['student_name'].'_'.$item['employee_name'] => $item];
            });


        // $grouped = $report->mapToGroups(function ($item, $key) {
        //     return [$item['token'].'_'.$item['module_type'].'_'.$item['people_name'].'_'.$item['student_name'].'_'.$item['employee_name'] => $item];
        // });
        
        return $grouped->paginate(5)->onEachSide(0);
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
        return view('livewire.tenant.home.comments');
    }
}
