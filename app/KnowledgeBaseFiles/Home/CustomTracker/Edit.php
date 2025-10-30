<?php

namespace App\Http\Livewire\Tenant\Home\CustomTracker;

use App\Contracts\SurveyBuilder\SurveyQuestion;
use App\Models\Question;
use App\Models\Tenant\{Question as TenantQuestion, QuestionSurvey, Survey,SurveyCycle, Tracker};
use App\Reports\LikertAnswersReport;
use App\Scopes\PeriodScope;
use Illuminate\Database\Query;
use Illuminate\Support\{Collection, Str};
use Livewire\Component;
use Session;
use App\Models\Tenant as ModelTenant;
use App\Reports\MultipleChoiceAnswersReportCentral;

/**
 * @property-read SurveyQuestion $questionable
 * @property-read int $currentScore
 * @property-read int $previousScore
 * @property-read int $periodDiff
 */
class Edit extends Component
{
    public Tracker $tracker;

    public bool $editTracker = false;

    public ?string $question = null;

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public $question_module;
    public string $module_type = 'parent';

    protected $listeners = [
        'filters::period' => 'fillPeriod',
        'home-page::change_module_type' => 'setModuleType'
    ];

    public function rules(): array
    {
        return [
            'question' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $trackerExists = Tracker::query()
                        ->whereMorphedTo('questionable', $this->questionable)
                        ->where('user_id', user()->id)
                        ->where('module_type',$this->question_module)
                        ->exists();

                    if ($trackerExists) {
                        $fail('You already have this question tracked.');
                    }
                },
            ],
        ];
    }

    public function mount()
    {
        if(Session::has('period')){
            $this->period=Session::get('period');
        }
        if(Session::has('custom_period')){
            $this->custom_date=Session::get('custom_period');
          }
        $this->question = $this->tracker->module_type . '.'. $this->tracker->questionable_type . '.' . $this->tracker->questionable_id;

    }

    public function getQuestionsProperty(): Collection
    {
        $question_survey =  QuestionSurvey::query()
        ->when(in_array($this->module_type,[ModelTenant::ALLPULSE,ModelTenant::PARENT,ModelTenant::STUDENT,ModelTenant::EMPLOYEE]), function ($query) {
           $query->wherehas('surveyCycle',function($query){
                $query->whereIn('status',[SurveyCycle::ACTIVE,SurveyCycle::INACTIVE]);
           });
        }, function ($query) {
            $query->whereExists(function (Query\Builder $query) {
                return $query
                    ->selectRaw(1)
                    ->from('surveys')
                    ->where('status', Survey::ACTIVE)
                    ->latest();
            });
        })
        ->where(function($query) {
            $query->whereNull('custom_question_id')
                  ->distinct('question_id')
                  ->orWhere(function($q) {
                      $q->whereNull('question_id')
                        ->distinct('custom_question_id');
                  });
        });
         if($this->module_type !== ModelTenant::ALL && $this->module_type !== ModelTenant::ALLPULSE ){
             $question_survey  = $question_survey->module($this->module_type);
         }else{
            if(getActiveModules()){
                $question_survey=$question_survey->whereIn('module_type', getActiveModules());
            }
         }
         $question_survey = $question_survey->where('type','!=', Question::COMMENT)->when($this->module_type == ModelTenant::ALLPULSE, function ($q){
            $q->orderByRaw("FIELD(module_type, 'parent', 'student', 'employee')");
        })
         ->get();

         return $question_survey;
    }

    public function getScoreProperty(): Collection
    {
        $scores = (new LikertAnswersReport($this->period, $this->custom_date,[],scopeActiveSurvey: false,module_type: $this->module_type,calculateBenchmakr: false))
            ->run()
            ->where('survey_answers.questionable_type', $this->tracker->questionable_type)
            ->where('survey_answers.module_type', $this->tracker->module_type)
            ->where('survey_answers.questionable_id', $this->tracker->questionable_id)->first();

        if($this->period == PeriodScope::Custom){

            if(isset($this->custom_date) && !empty($this->custom_date)){
                $dates = convertCustomDateRange($this->custom_date);
                $dates_diff = $dates['start_date']->diffInDays( $dates['end_date'] );
                if($dates_diff>365){
                    $scores['period_diff'] = 0;
                }

            }
        }
        $scores =  collect($scores);
        return $scores;
    }

    public function getMultipleChoiceScore()
    {
        $answer = (new MultipleChoiceAnswersReportCentral([],$this->period, $this->custom_date,false,module_type: $this->module_type))
            ->run();
        $questionAbleType = $this->tracker->questionable_type == TenantQuestion::class ? "2" : "1";

         $answer = $answer->where('questionable_id', $this->tracker->questionable_id)->where('questionable_type', $questionAbleType)->where('module_type',$this->tracker->module_type)->first();

        return $answer;
    }

    public function getQuestionableProperty(): ?SurveyQuestion
    {
        if (!$this->question) {
            return null;
        }

        [$module_type,$classModel, $id] = Str::of($this->question)->explode('.');
        $this->question_module=$module_type;
        return $classModel::query()->whereJsonContains('module_type',$module_type)->find($id);

    }

    public function fillPeriod(string $period, string $custom_date): void
    {
        if(Session::has('period')){
            $this->period=Session::get('period');
        }
        if(Session::has('custom_period')){
            $this->custom_date=Session::get('custom_period');
          }
    }

    public function save(): void
    {
        $this->validate();

        $this->tracker->questionable()->associate($this->questionable);
        $this->tracker->user()->associate(user());
        $this->tracker->module_type = $this->question_module;
        $this->tracker->save();

        $this->hideFormTracker();

        $this->emit('custom-tracker::notify-period-change',$this->period,$this->custom_date);
        $this->emit('custom-tracker::updated');
        $this->notify('Custom tracker updated successfully.');


    }

    public function showFormTracker(): void
    {
        $this->editTracker = true;
    }

    public function hideFormTracker(): void
    {
        $this->editTracker = false;
    }

    public function setModuleType(string $type): void
    {
        $this->module_type = $type;
    }

    public function render()
    {
        return view('livewire.tenant.home.custom-tracker.edit');
    }
}
