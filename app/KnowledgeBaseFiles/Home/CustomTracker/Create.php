<?php

namespace App\Http\Livewire\Tenant\Home\CustomTracker;

use App\Contracts\SurveyBuilder\SurveyQuestion;
use App\Models\Question;
use App\Reports\LikertAnswersReport;
use App\Scopes\PeriodScope;
use App\Models\Tenant\{QuestionSurvey, Survey, SurveyCycle, Tracker};
use Illuminate\Database\Eloquent\Collection;
use App\Models\Tenant as ModelTenant;
use Illuminate\Database\Query;
use Illuminate\Support\Str;
use Livewire\Component;
use Session;

/**
 * @property-read SurveyQuestion $questionable
 * @property-read Collection $questions
 */
class Create extends Component
{
    public Tracker $tracker;

    public ?string $question = null;
    public $question_module;
    public bool $showFormTracker = false;

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $module_type = 'parent';

    protected $listeners = [
        'filters::period' => 'fillPeriod',
        'home-page::change_module_type' => 'setModuleType'
        ];

    public function fillPeriod(string $period, string $custom_date): void
    {

        if(Session::has('period')){
            $this->period=Session::get('period');
        }
        if(Session::has('custom_period')){
            $this->custom_date=Session::get('custom_period');
          }
    }

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
                        ->where('module_type', $this->question_module)
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
    }
    public function getQuestionableProperty(): ?SurveyQuestion
    {
        if (!$this->question) {

            return null;
        }
        [$module_type,$classModel,$id] = Str::of($this->question)->explode('.');
        $this->question_module=$module_type;
        return $classModel::query()->find($id);
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
            if($this->module_type == ModelTenant::ALL){
                $trackers = $question_survey->whereIn('module_type',array_keys(getPermissionForModule('_view_dashboard')));
            }
            elseif($this->module_type == ModelTenant::ALLPULSE)
            {
                $trackers = $question_survey->whereIn('module_type',array_intersect(array_keys(getPermissionForModule('_view_dashboard')), pulseModules()));
            }
            else {
                $trackers = $question_survey->module($this->module_type);
            }
        $question_survey = $question_survey->where('type', '!=', Question::COMMENT)
            ->when($this->module_type == ModelTenant::ALLPULSE, function ($q){
                $q->orderByRaw("FIELD(module_type, 'parent', 'student', 'employee')");
            })
            ->get();
        
        return $question_survey;
    }

    public function showFormTracker(): void
    {
        $this->showFormTracker = true;
        $this->initTracker();
    }

    public function hideFormTracker(): void
    {
        $this->showFormTracker = false;
        $this->resetFields();
    }

    public function initTracker(): void
    {
        $this->tracker = new Tracker();
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
        $this->emit('custom-tracker::created');
        $this->notify('Custom tracker created successfully.');


    }

    public function setModuleType(string $type): void
    {
        $this->module_type = $type;
    }

    public function resetFields(): void
    {
        $this->initTracker();
        $this->reset('question');
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.tenant.home.custom-tracker.create');
    }
}
