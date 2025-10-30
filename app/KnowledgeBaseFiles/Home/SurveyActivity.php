<?php

namespace App\Http\Livewire\Tenant\Home;

use App\Models\Tenant;
use App\Models\Tenant\SurveyAnswer;
use App\Models\Tenant\SurveyInvite;
use App\Models\Tenant\User;
use App\Reports\NpsReport;
use App\Scopes\PeriodScope;
use Livewire\Component;
use Session;

/**
 * @property-read int $surveysSent
 * @property-read int $partialResponses
 * @property-read int $completedResponses
 * @property-read int $completedRate
 */
class SurveyActivity extends Component
{
    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $module_type = '';
    public $displayActivity = true;

    public array $survey_sent_modules =   [
        'total_count' => 0,
        'parent_count' => 0,
        'student_count' => 0,
        'employee_count' => 0,
    ];
    public array $survey_partial_responses_modules =   [
        'total_count' => 0,
        'parent_count' => 0,
        'student_count' => 0,
        'employee_count' => 0,
    ];
    public array $survey_completed_responses_modules =   [
        'total_count' => 0,
        'parent_count' => 0,
        'student_count' => 0,
        'employee_count' => 0,
    ];
    public array $survey_response_rates_modules =   [
        'total_count' => 0,
        'parent_count' => 0,
        'student_count' => 0,
        'employee_count' => 0,
    ];
    public array $survey_completed_rates_modules =   [
        'total_count' => 0,
        'parent_count' => 0,
        'student_count' => 0,
        'employee_count' => 0,
    ];

    protected $listeners = [
        'filters::period' => 'setPeriod',
        'home-page::change_module_type' => 'setModuleType',
        'survey-deleted' => '$refresh'
    ];

    public function mount(string $module_type): void
    {

        if (Session::has('period')) {
            $this->period = Session::get('period');
        }
        if (Session::has('custom_period')) {
            $this->custom_date = Session::get('custom_period');
        }
        $this->module_type = $module_type;
        //         // If only custom survey is enabled , no survey Activity will be show on All survey
        foreach(getActiveModules(true) as $module){
            $modulePermissions[] = getModuleUserPermissions($module);
        }
        
        if((empty(getActiveModules(true)) || getUserPulsePermissions() == 0 ) && ($this->module_type == \App\Models\Tenant::ALL) ){
             $this->displayActivity = false;
        }
    }


    public function getSurveysSentProperty(): array
    {
        $filters = getFiltersFromRestrictions($this->module_type) ?? [];
        $base_query = SurveyInvite::query()
            ->whereIn('status', [SurveyInvite::ANSWERED, SurveyInvite::SEND]);
        $base_query = $base_query->whereIn('module_type', getPermissionForModule('_view_dashboard'));
        $base_query->when(isSequence($this->period), function ($query) {
            $query->where('survey_cycle_id',$this->period);
        }, function ($query) {
            $query->periodFilter($this->period, $this->custom_date, 'send_at');
        });
        $base_query->when($filters, function ($query) use ($filters) {
            $query->whereHas('answers', function ($query) use ($filters) {
                $query->filterAnswers($filters, []);
            });
        });

        if ($this->module_type == Tenant::ALL || $this->module_type == Tenant::ALLPULSE) {
            $total_count = clone $base_query;
            $parent_count = clone $base_query;
            $employee_count = clone $base_query;
            $student_count = clone $base_query;
            $total_count = $total_count->count();
            $parent_count = $parent_count->module('parent')->count();
            $student_count = $student_count->module('student')->count();
            $employee_count = $employee_count->module('employee')->count();


            $this->survey_sent_modules['total_count'] = $total_count;
            $this->survey_sent_modules['parent_count'] = $parent_count;
            $this->survey_sent_modules['student_count'] = $student_count;
            $this->survey_sent_modules['employee_count'] = $employee_count;
            return $this->survey_sent_modules;
        } else {

            $total_count  = clone $base_query;
            $this->survey_sent_modules['total_count'] = $total_count->module($this->module_type)->count();
            $this->survey_sent_modules[$this->module_type . '_count'] = $this->survey_sent_modules['total_count'];

            if ($this->module_type == 'parent') {

                $this->survey_sent_modules['student_count'] = 0;
                $this->survey_sent_modules['employee_count'] = 0;
            } else if ($this->module_type == 'student') {

                $this->survey_sent_modules['parent_count'] = 0;
                $this->survey_sent_modules['employee_count'] = 0;
            } else {
                $this->survey_sent_modules['parent_count'] = 0;
                $this->survey_sent_modules['student_count'] = 0;
            }

            return $this->survey_sent_modules;
        }
    }

    public function getPartialResponsesProperty(): array
    {
        $baseQuery = $this->baseQueryResponses(SurveyInvite::SEND)
        ->when(isSequence($this->period), function ($query) {
            $query->sequenceFilter($this->period);
        }, function ($query) {
            $query->periodFilter($this->period, $this->custom_date);
        })->get();
        $total_count = 0;
        $parent_count = $baseQuery->where('module_type', Tenant::PARENT)->sum('responses');
        $employee_count = $baseQuery->where('module_type', Tenant::EMPLOYEE)->sum('responses');
        $student_count = $baseQuery->where('module_type', Tenant::STUDENT)->sum('responses');
        if ($this->module_type == 'all' || $this->module_type == Tenant::ALLPULSE) {

            $total_count +=  $parent_count;
            $total_count += $student_count;
            $total_count +=  $employee_count;
            $this->survey_partial_responses_modules['total_count'] = $total_count;
            $this->survey_partial_responses_modules['parent_count'] =  $parent_count;
            $this->survey_partial_responses_modules['student_count'] =  $student_count;
            $this->survey_partial_responses_modules['employee_count'] =  $employee_count;
        } elseif ($this->module_type == Tenant::PARENT) {
            $this->survey_partial_responses_modules['total_count'] = $parent_count;
            $this->survey_partial_responses_modules['parent_count'] = $parent_count;
            $this->survey_partial_responses_modules['student_count'] = 0;
            $this->survey_partial_responses_modules['employee_count'] = 0;
        } elseif ($this->module_type == Tenant::STUDENT) {
            $this->survey_partial_responses_modules['total_count'] = $student_count;
            $this->survey_partial_responses_modules['student_count'] = $student_count;
            $this->survey_partial_responses_modules['parent_count'] = 0;
            $this->survey_partial_responses_modules['employee_count'] = 0;
        }
        elseif($this->module_type==Tenant::EMPLOYEE){
            $this->survey_partial_responses_modules['total_count'] = $employee_count;
            $this->survey_partial_responses_modules['employee_count'] = $employee_count;
            $this->survey_partial_responses_modules['parent_count'] = 0;
            $this->survey_partial_responses_modules['student_count'] = 0;
        } else {
            $custom_count = $baseQuery->where('module_type', $this->module_type)->sum('custom_responses');
            $this->survey_partial_responses_modules['total_count'] = $custom_count;
            $this->survey_partial_responses_modules[$this->module_type . '_count'] = $custom_count;
        }
        return $this->survey_partial_responses_modules;
    }

    public function getCompletedResponsesProperty(): array
    {
        $baseQuery = $this->baseQueryResponses(SurveyInvite::ANSWERED)
        ->when(isSequence($this->period), function ($query) {
                $query->sequenceFilter($this->period);
            }, function ($query) {
                $query->periodFilter($this->period, $this->custom_date);
            })->get();
        $total_count = 0;
        $parent_count = $baseQuery->where('module_type', Tenant::PARENT)->sum('responses');
        $employee_count = $baseQuery->where('module_type', Tenant::EMPLOYEE)->sum('responses');
        $student_count = $baseQuery->where('module_type', Tenant::STUDENT)->sum('responses');
        if ($this->module_type == Tenant::ALL || $this->module_type == Tenant::ALLPULSE) {
            $total_count +=  $parent_count;
            $total_count +=  $student_count;
            $total_count += $employee_count;
            $this->survey_completed_responses_modules['total_count'] = $total_count;
            $this->survey_completed_responses_modules['parent_count'] =  $parent_count;
            $this->survey_completed_responses_modules['student_count'] = $student_count;
            $this->survey_completed_responses_modules['employee_count'] =  $employee_count;
        } elseif ($this->module_type == Tenant::PARENT) {
            $this->survey_completed_responses_modules['total_count'] = $parent_count;
            $this->survey_completed_responses_modules['parent_count'] = $parent_count;
            $this->survey_completed_responses_modules['student_count'] = 0;
            $this->survey_completed_responses_modules['employee_count'] = 0;
        } elseif ($this->module_type == Tenant::STUDENT) {
            $this->survey_completed_responses_modules['total_count'] = $student_count;
            $this->survey_completed_responses_modules['student_count'] = $student_count;
            $this->survey_completed_responses_modules['parent_count'] = 0;
            $this->survey_completed_responses_modules['employee_count'] = 0;
        } elseif ($this->module_type == Tenant::EMPLOYEE) {
            $this->survey_completed_responses_modules['total_count'] = $employee_count;
            $this->survey_completed_responses_modules['employee_count'] = $employee_count;
            $this->survey_completed_responses_modules['parent_count'] = 0;
            $this->survey_completed_responses_modules['student_count'] = 0;
        } else {
            $custom_count = $baseQuery->where('module_type', $this->module_type)->sum('custom_responses');
            $this->survey_completed_responses_modules['total_count'] = $custom_count;
            $this->survey_completed_responses_modules[$this->module_type . '_count'] = $custom_count;
        }
        return $this->survey_completed_responses_modules;
    }

    public function getResponseRateProperty(): array
    {
        if (($this->completedResponses['total_count'] + $this->partialResponses['total_count']) === 0) {

            $this->survey_response_rates_modules['total_count']  = 0;
            $this->survey_response_rates_modules['parent_count']  = 0;
            $this->survey_response_rates_modules['student_count']  = 0;
            $this->survey_response_rates_modules['employee_count']  = 0;
            return $this->survey_response_rates_modules;
        }

        $this->survey_response_rates_modules['total_count']  =  $this->surveysSent['total_count'] > 0 ? round(($this->completedResponses['total_count'] + $this->partialResponses['total_count']) / $this->surveysSent['total_count'] * 100) : 0;
        $this->survey_response_rates_modules['parent_count']  = $this->surveysSent['parent_count'] > 0 ?  round(($this->completedResponses['parent_count'] + $this->partialResponses['parent_count']) / $this->surveysSent['parent_count'] * 100) : 0;
        $this->survey_response_rates_modules['student_count']  = $this->surveysSent['student_count'] > 0 ? round(($this->completedResponses['student_count'] + $this->partialResponses['student_count']) / $this->surveysSent['student_count'] * 100) : 0;
        $this->survey_response_rates_modules['employee_count']  = $this->surveysSent['employee_count'] > 0 ? round(($this->completedResponses['employee_count'] + $this->partialResponses['employee_count']) / $this->surveysSent['employee_count'] * 100) : 0;

        return $this->survey_response_rates_modules;
    }

    public function getCompletedRateProperty(): array
    {

        if ($this->completedResponses['total_count'] == 0) {

            $this->survey_completed_rates_modules['total_count']  = 0;
            $this->survey_completed_rates_modules['parent_count']  = 0;
            $this->survey_completed_rates_modules['student_count']  = 0;
            $this->survey_completed_rates_modules['employee_count']  = 0;

            return $this->survey_completed_rates_modules;
        }

        $this->survey_completed_rates_modules['total_count']  = $this->partialResponses['total_count'] + $this->completedResponses['total_count'] > 0 ? round($this->completedResponses['total_count'] / ($this->partialResponses['total_count'] + $this->completedResponses['total_count']) * 100) : 0;
        $this->survey_completed_rates_modules['parent_count']  = $this->partialResponses['parent_count'] + $this->completedResponses['parent_count'] > 0 ?  round($this->completedResponses['parent_count'] / ($this->partialResponses['parent_count'] + $this->completedResponses['parent_count']) * 100) : 0;
        $this->survey_completed_rates_modules['student_count']  = $this->partialResponses['student_count'] + $this->completedResponses['student_count'] > 0 ?  round($this->completedResponses['student_count'] / ($this->partialResponses['student_count'] + $this->completedResponses['student_count']) * 100) : 0;
        $this->survey_completed_rates_modules['employee_count']  = $this->partialResponses['employee_count'] + $this->completedResponses['employee_count'] > 0 ?  round($this->completedResponses['employee_count'] / ($this->partialResponses['employee_count'] + $this->completedResponses['employee_count']) * 100) : 0;

        if (!in_array($this->module_type, getActiveModules(true)) && $this->module_type !== Tenant::ALL && $this->module_type !== Tenant::ALLPULSE) {
            $this->survey_completed_rates_modules[$this->module_type . '_count']  = $this->partialResponses[$this->module_type . '_count'] + $this->completedResponses[$this->module_type . '_count'] > 0 ?  round($this->completedResponses[$this->module_type . '_count'] / ($this->partialResponses[$this->module_type . '_count'] + $this->completedResponses[$this->module_type . '_count']) * 100) : 0;
        }

        return $this->survey_completed_rates_modules;
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
        $this->displayActivity = true;
        // If only custom survey is enabled , no survey Activity will be show on All survey
        if((empty(getActiveModules(true)) || getUserPulsePermissions() == 0 ) && ($this->module_type == \App\Models\Tenant::ALL) ){
            $this->displayActivity = false;
       }

    }

    public function baseQueryResponses($type)
    {
        $filters = getFiltersFromRestrictions($this->module_type) ?? [];
        $base_query = SurveyAnswer::query()
            ->selectRaw(<<<SQL
            survey_answers.id,
            survey_answers.value,
            survey_answers.module_type,
            COUNT(DISTINCT survey_answers.survey_invite_id) as responses,
            COUNT(DISTINCT survey_invites.id) as custom_responses

        SQL)
            ->join('survey_invites', 'survey_invites.id', '=', 'survey_answers.survey_invite_id')
            ->where('survey_invites.status', $type)
            ->where('survey_answers.questionable_type', '!=', '')
            ->whereNotNull('value')
            ->where('value', 'not LIKE', '%N/A%')
            ->when($filters,function($query) use ($filters){
                $query->filterAnswers($filters,[]);
            });
        // Remove nps scope for custom survey, for custom survey if only one question irrespective of type is filled by user  then we will calculate the stats on the basis of survey invite
        if ($this->module_type == Tenant::ALL || $this->module_type == Tenant::ALLPULSE) {
            if (getActiveModules()) {
                $base_query = $base_query->whereIn('survey_answers.module_type', array_intersect(array_keys(getPermissionForModule('_view_dashboard')), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]))->nps();
            }
        } else {
            if (in_array($this->module_type, getActiveModules(true))) {
                $base_query = $base_query->module($this->module_type)->nps();
            } else {
                $base_query = $base_query->module($this->module_type);
            }
        }
        if (in_array($this->module_type, getActiveModules(true))) {
            $base_query  = $base_query->groupBy(
                'survey_answers.questionable_id',
                'survey_answers.questionable_type',
                'survey_answers.module_type'
            );
        } else {
            $base_query  = $base_query->groupBy(
                'survey_answers.survey_invite_id',
            );
        }
        return $base_query;
    }
    public function render()
    {
        return view('livewire.tenant.home.survey-activity');
    }
}
