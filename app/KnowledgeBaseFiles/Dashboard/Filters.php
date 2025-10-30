<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use App\Models\Tenant\SurveyAnswer;
use Auth;
use App\Models\{Question, QuestionAnswer, Tenant};
use App\Models\Tenant\EditableQuestionAnswer;
use App\Models\Tenant\Survey;
use App\Models\Tenant\SurveyInvite;
use App\Models\Tenant\SurveySetting;
use App\Scopes\PeriodScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

/** @property-read Collection $answers */
class Filters extends Component
{
	public Collection $filtersSelected;
	public Collection $applyForceFilters;

	public bool $scopeActiveSurvey = true;
	public bool $applyBenchmarkFilter = false;
	public bool $showClearFilterConfirmation = false;
    public $showRestrictionModal = false;

    public $nps_filters = ['promoter','passive','detractor'];
    public $activeTenantModules = [];
	public array $preloadedAnswer = [];

    public $filters = [];
    public $removeFilters = [];
    protected $inActiveQuestion = [];
    public string $filterByPeriod = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public $doNotApplyFilters= false;
    public string $module_type='all';
    protected $queryString = [
        'filterByPeriod'      => ['except' => PeriodScope::DEFAULT_PERIOD],
        'custom_date'      => ['except' => ''],
    ];
    public $showActiveSign = true;
    protected $listeners = [
        'filters::period'  => 'setPeriod',
        'filters::surveys' => 'setScopeActiveSurveys',
        'dashboard-page::change_module_type' => 'setModuleType',
        'pageLoaded' => 'updateFilters',
    ];
    public function setModuleType(string $type): void
    {
        $this->module_type = $type;
        $this->mount();        
        $this->getAnswersProperty();
        $this->updateFilters();
    }
    public function mount()
    {
        $this->filtersSelected = collect();
        $this->applyForceFilters = collect();
        $this->preloadedAnswer = [];
        if ($this->filters) {
            $this->doNotApplyFilters = true;
        }

        $this->surveyProgressFilter();
    }

	public function updateFilters()
	{
        
		$this->filtersSelected = $this->applyForceFilters;
		
		if (count($this->filtersSelected)) {

			// Applying the filter
			$this->filtersSelected = $this->filtersSelected->map($this->cleanFilterCollection())->filter();

			$this->applyFilters();
            if ($this->doNotApplyFilters) {
                $this->doNotApplyFilters = false;
                return;
            }
			$this->emit('filters::apply', $this->filters);
		}
        $this->surveyProgressFilter();

	}

    public function updatedFiltersSelected(bool $checked, $value)
    {
        $this->showActiveSign = true;
        if (!$checked) {
            // Applying the filter
            $this->filtersSelected = $this->filtersSelected->map($this->cleanFilterCollection())->filter();
        }
        $this->applyFilters();
        $setResultsEmpty = $this->isFilterMissing();  
        if ($this->doNotApplyFilters) {
            $this->doNotApplyFilters = false;
            return;
        }
        $this->emit('filters::apply', $this->filters, $setResultsEmpty,$this->removeFilters);
        incrementDashboardFilterMetrics();
    }

    public function getAnswersProperty(): Collection
    {
        if (in_array($this->module_type,[Tenant::ALL,Tenant::ALLPULSE])) {
            return collect([]);
        }
        $answers = $this->getAnswersOfModuleType();
        if ($this->getInactiveQuestion()) {
            $this->emit('filters::apply', $this->filters,true);
            $this->applyForceFilters = collect();
        }
        return $answers;
    }

    public function clearFilters(): void
    {
		$this->filtersSelected = $this->applyForceFilters;
		$this->filtersSelected = $this->filtersSelected->map($this->cleanFilterCollection())->filter();
		$this->applyFilters();
		
        $this->applyBenchmarkFilter = false;
		$this->showClearFilterConfirmation = false;
        $this->emit('filters::apply', $this->filters);
        incrementDashboardFilterMetrics();

    }

	public function cleanFilterCollection ()
	{
		return function ($item) use (&$filterFalseValues) {
			if (is_array($item) || $item instanceof Collection) {
                $item = collect($item)->filter($filterFalseValues);
				return $item->isNotEmpty() ? $item->toArray() : null;
			}
			return $item !== false;
		};
	}


    public function applyFilters(): void
    {
        $filters = $this->filtersSelected->mapWithKeys(fn (array $filters, string $classModel) => [
            $classModel => collect($filters)->mapWithKeys(fn (array $answers, int $id) => [
                $id => array_filter($answers),
            ])->filter()->toArray(),
        ])->toArray();

        //unsetting the array if uncheck filters and that filter count is 0

        foreach ($filters as $key=>$filter){
            if(isset($filters[$key]) && count($filters[$key])==0){
                unset($filters[$key]);
            }
        }
        $this->filters = $filters;
        $this->surveyProgressFilter();
    }

    public function setPeriod(string $period, string $custom_date): void
    {
        $this->filterByPeriod = $period;
        $this->custom_date = $custom_date;
        $this->checkForFilters();
    }

    public function setScopeActiveSurveys(bool $onlyActive): void
    {
        $this->scopeActiveSurvey = $onlyActive;
    }

    public function answersCount()
    {
        return $this->answers->count();
    }

    public function render()
    {
        return view('livewire.tenant.dashboard.filters');
    }

    public function surveyProgressFilter()
    {
        if (!$this->filtersSelected->has('survey_progess')) {
            $surveyProgressValue = SurveySetting::where('type', 'response_visibility')->value('value') ?? '';
            if ($surveyProgressValue == SurveyInvite::ANSWERED && isValidSurveyProgressFilterSession() ) {
                $this->filtersSelected->put('survey_progess', collect([
                    ['value' => SurveyInvite::ANSWERED]
                ]));
                $this->showActiveSign = false;
            }
        }
        else
        {
           $surveyProgressFilter =  setSurveyProgressFilterOnlyValues($this->filtersSelected->toArray());
           Session::put('surveyProgressFilter', $surveyProgressFilter);
        
        }
    }
    public function checkForFilters(){

        $answers = $this->answers;

        $questionable_ids = [];
        $all_questionable_ids= [];


        $custom_nps_filter = isset($this->filtersSelected['custom_nps_filter']) ? $this->filtersSelected['custom_nps_filter'] : [];

        foreach ($this->filtersSelected as  $filter){
            $filter = is_array($filter)?$filter:$filter->toArray();
            $questionable_ids = array_keys($filter);
            $all_questionable_ids =  array_merge($all_questionable_ids,$questionable_ids);
        }

        $result_of_questionable_type = array_intersect(array_keys($this->filtersSelected->toArray()),$answers->pluck('questionable_type')->toArray());
        $result_of_question_id = array_intersect($all_questionable_ids,$answers->pluck('questionable_id')->toArray());
        $result_of_answers = array_intersect($this->filtersSelected->collapse()->flatten()->toArray(),$answers->pluck('answers')->collapse()->flatten()->toArray());


        //parent level check
        if(count($result_of_questionable_type)){


            $this->filtersSelected = $this->filtersSelected->mapWithKeys(function($filter,$classModel) use ($result_of_questionable_type){
                if(in_array($classModel,$result_of_questionable_type))
                {
                    return collect([$classModel => $filter]);
                }else{
                    return collect();
                }
            });


            $selectedFilters = clone $this->filtersSelected;
            $selectedFilters = $selectedFilters->toArray();
            $this->filtersSelected = $this->filtersSelected->mapWithKeys(function($filter,$classModel) use ($result_of_question_id,$selectedFilters){
                 return  collect($filter)->mapWithKeys(function($answers,$id) use ($result_of_question_id,$classModel,$selectedFilters){
                    if(!in_array($id,$result_of_question_id))
                    {

                        unset($selectedFilters[$classModel][$id]);
                    }
                     return $selectedFilters;
                });
            });



            //for answers;

            $selectedFilters = clone $this->filtersSelected;
            $selectedFilters = $selectedFilters->toArray();
            $this->filtersSelected = $this->filtersSelected->mapWithKeys(function($filter,$classModel) use ($result_of_answers,$selectedFilters){
                return  collect($filter)->mapWithKeys(function($answers,$questionable_id) use ($result_of_answers,$selectedFilters,$classModel){
                   return collect($answers)->mapWithKeys(function ($answer,$id) use ($result_of_answers,$selectedFilters,$classModel,$questionable_id){

                    if(!in_array($answer,$result_of_answers))
                    {
                     if(is_array($answer)){
                         foreach($selectedFilters[$classModel][$questionable_id] as $key=>$value){
                              unset($selectedFilters[$classModel][$questionable_id][$key]);
                         }
                      }
                      else{
                        unset($selectedFilters[$classModel][$questionable_id][$answer]);
                     }
                    }
                       return $selectedFilters;

                   });
                });
            });

        }
        else {
            $this->filtersSelected = collect();
        }

        if (count($custom_nps_filter))
        {
            $this->filtersSelected->put('custom_nps_filter' ,$custom_nps_filter);
        }
        $this->applyFilters();

    }

	private function getAnswersOfModuleType()
	{
		$filterQuery = getAnswersOfModuleType($this->filterByPeriod, $this->custom_date, $this->module_type, $this->scopeActiveSurvey);
		$results = $filterQuery->get();

        $restrictions = getUserRestrictions($this->module_type);
		$results = $results->reject(function ($item) use ($restrictions) {
            if (isset($restrictions[$item->questionable_type]) && isset($restrictions[$item->questionable_type][$item->questionable_id])
				&& $restrictions[$item->questionable_type][$item->questionable_id] === 'hide') {
                    return true;
			}
			return false;
		});

        // Loop through the results and filter the answers
		return $results->map(function ($item) use ($restrictions) {
			
			if (isset($restrictions[$item->questionable_type][$item->questionable_id])) {
			
                $answersArray = getRestrictionEditableAnswers($restrictions[$item->questionable_type][$item->questionable_id]);
                $questionAnswersIds =$answersArray[1];
                $editableAnswerIds = $answersArray[0]; 
                
                if ($item->questionable_type == "App\Models\Question") {
                    $answers = QuestionAnswer::whereQuestionId($item->questionable_id)
                        ->whereIn('id', $questionAnswersIds)
                        ->pluck('name')->toArray();
                   
                }else
                {
                    $answers = Tenant\QuestionAnswer::whereQuestionableId($item->questionable_id)
					->whereIn('id', $questionAnswersIds)
					->pluck('name')->toArray();
                }

                $editableAnswer = EditableQuestionAnswer::where('questionable_id',$item->questionable_id)
                ->whereIn('id', $editableAnswerIds)
                ->pluck('name')->toArray();

                $answers = array_merge($answers,$editableAnswer);    
				// Prepare the answers in the required structure
				$formattedAnswers = $this->filterDemographicQuestions($item, $answers);

                // Update the answers field in the item with the correct JSON structure
				$item->setAttribute('answers', $formattedAnswers);
			}	
			return $item;
		});

	}

	/**
	 * @param $item
	 * @param $answers
	 * @return array
	 */
	public function filterDemographicQuestions($item, $answers): array
	{
		return array_map(function($answer) use ($item) {
			// Ensure the first-level collection structure exists
			if (!$this->applyForceFilters->has($item->questionable_type)) {
				$this->applyForceFilters->put($item->questionable_type, collect());
			}

			// Ensure the second-level collection (for the questionable_id) exists
			$questionTypeCollection = collect($this->applyForceFilters->get($item->questionable_type));
			if (!$questionTypeCollection->has($item->questionable_id)) {
				$questionTypeCollection->put($item->questionable_id, collect());
			}

			// Get the inner collection for the current questionable_id
			$questionIdCollection = collect($questionTypeCollection->get($item->questionable_id));

			// Update the applyForceFilters collection
			$questionIdCollection->put($answer, $answer);

			// Set the updated collection back in applyForceFilters
			$questionTypeCollection->put($item->questionable_id, $questionIdCollection);
			$this->applyForceFilters->put($item->questionable_type, $questionTypeCollection);

			$this->preloadedAnswer[] = $item->questionable_id;
			return [$answer]; // Wrap each answer in an array
		}, $answers);
		
	}

	public function preCheckClearFilter()
	{
		if ($this->applyForceFilters->count()){
			$this->showClearFilterConfirmation = true;
			return;
		}
		
		$this->clearFilters();;
	}


    public function isFilterMissing()
    {
        $allMissing = true;
        $this->removeFilters = [];
        $this->applyForceFilters->forget('survey_progess');
        foreach ($this->applyForceFilters as $model => $defaultForcedFilters) {
            foreach ($defaultForcedFilters as $questionId => $filter) {
                if (!isset($this->filters[$model][$questionId])) {
                    $this->filters[$model][$questionId] = $filter;
                    $this->removeFilters[$model][$questionId] = $filter;
                } else {
                    // If any filter is found, set allMissing to false
                    $allMissing = false;
                }
            }
        }
        if ($allMissing && count($this->applyForceFilters) > 0) {
            return true;
        }

        return false;
    }

    public function getInactiveQuestion()
    {
       
        // Get the user restrictions for the module type
        $userRestriction = getUserRestrictions($this->module_type);
        // Convert the applyForceFilters collection to an array
        $applyForceFiltersArray = $this->applyForceFilters->toArray();

        // Get the models from the $applyForceFiltersArray
        $applyForceFiltersModel = array_keys($applyForceFiltersArray);

        // Get the models from the $userRestriction array
        $userRestrictionModel = array_keys($userRestriction);

        sort($applyForceFiltersModel);
        sort($userRestrictionModel);
        
        // Get the system questions from the $userRestriction array where the hide option is not set
        $userRestrictionSystemQuestion = isset($userRestrictionModel[0]) ? array_keys(array_filter($userRestriction[$userRestrictionModel[0] ?? []], 'is_array')) : [];

        // Get the custom questions from the $userRestriction array where the hide option is not set
        $userRestrictionCustomQuestion = isset($userRestrictionModel[1]) ? array_keys(array_filter($userRestriction[$userRestrictionModel[0] ?? []], 'is_array')) : [];

        // Get the system questions from the $applyForceFilters array
        $applyForceFiltersSystemQuestion = isset($applyForceFiltersModel[0]) ? array_keys($applyForceFiltersArray[$applyForceFiltersModel[0]]) : [];

        // Get the custom questions from the $applyForceFilters array
        $applyForceFiltersCustomQuestion = isset($applyForceFiltersModel[1]) ? array_keys($applyForceFiltersArray[$applyForceFiltersModel[1]]) : [];

        // Calculate the inactive questions
        $this->inActiveQuestion = [
            Question::class => array_diff($userRestrictionSystemQuestion, $applyForceFiltersSystemQuestion),
            Tenant\Question::class => array_diff($userRestrictionCustomQuestion, $applyForceFiltersCustomQuestion),
        ];

        return isset($this->inActiveQuestion[Question::class]) ? count($this->inActiveQuestion[Question::class]) : 
        (isset($this->inActiveQuestion[Tenant\Question::class]) ? count($this->inActiveQuestion[Tenant\Question::class]) : 0);
    }

    public function openRestrictionModal($open = true)
    {
        $this->showRestrictionModal = $open;
    }

}
