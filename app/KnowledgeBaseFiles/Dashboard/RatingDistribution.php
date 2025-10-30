<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use App\Exports\NpsIndicatorExport;
use App\Exports\RatingRespondentsExport;
use App\Models\Question;
use App\Models\Tenant\SurveyInvite;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Tenant\SurveyAnswer;
use App\Scopes\PeriodScope;
use App\Traits\Livewire\FullTable;
use App\Traits\Livewire\HasBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use App\Models\Tenant\Question as TenantQuestion;
use App\Models\Tenant\QuestionAnswer;
use Illuminate\Support\Facades\DB;

/** @property-read Collection $answers */
class RatingDistribution extends Component
{
    use FullTable, HasBatch;
    public const ANSWERS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 'N/A'];

    public $surveyAnswer = null;

    public bool $modal = false;
    public bool $is_custom_nps_filter = false;

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public $custom_date = '';
    public string $module_type = 'parent';

    public array $filters = [];
    public array $custom_nps_filter = [];
    public array $surveyProgressFilter = [];

    public $selected_ans_ids = [];
    public $selected_rating = '';
    public $respondent_modal = false;
    public $percentages = [];

    protected $listeners = [
        'results::open-rating' => 'open',
        'rating::open-respondents' => 'showRespondents',
        'filters::period'      => 'setPeriod',
        'filters::apply'       => 'setFilters',
        'survey-deleted'      => '$refresh'
    ];

    public function getAnswersProperty(): Collection
    {
          
            if(isset($this->surveyAnswer['question_type']) && $this->surveyAnswer['question_type']== Question::RANK_ORDER_ID)
            {
            
				if ($this->surveyAnswer['questionable_type'] == 1){
					return Question::where('id', $this->surveyAnswer['questionable_id'])->where('type', Question::RANK_ORDER)->with('answers')->first()->answers;
				}
				return TenantQuestion::where('id', $this->surveyAnswer['questionable_id'])->where('type', Question::RANK_ORDER)->with('answers')->first()->answers;
               
            }else
            {
             
                //        if (count($this->custom_nps_filter)==0)
                //        {
                //            $answers = SurveyAnswer::query()
                //                ->benchmark()
                //                ->periodFilter($this->period)
                //                ->filterAnswers($this->filters)
                //                ->whereIn('survey_invite_id', explode(',',$this->surveyAnswer['invites']))
                //                ->where("questionable_id" ,$this->surveyAnswer['questionable']['id'])
                //                ->where("questionable_type" ,$this->surveyAnswer['questionable']['id'])
                //                ->get();

                $questionableType = $this->surveyAnswer['questionable_type'] == 1 ? Question::class : TenantQuestion::class;

                $invites = json_decode($this->surveyAnswer['invites']) ?? []; 
                $answers = SurveyAnswer::query()
                ->selectRaw(DB::raw('survey_answers.*'))
                ->benchmark()
                ->module($this->module_type)
                ->when(isSequence($this->period), function ($query) {
                    $query->sequenceFilterForRatingDistribution($this->period,true);
                }, function ($query) {
                    $query->periodFilter($this->period, $this->custom_date);
                })
                ->filterAnswers($this->filters)
                ->where(function($query) use($invites){
                    $query->whereIn('survey_invite_id',$invites);
                })
                ->where("questionable_id" ,$this->surveyAnswer['questionable']['id'])
                ->where("questionable_type",$questionableType)
                ->where("answering_multiple",0)
                ->when($this->surveyProgressFilter,function($query){
                    $query = $this->surveyProgressFilterQuery($query);
                })
                ->get();
            if(count($this->custom_nps_filter)){

                $nps_people = SurveyAnswer::query()->selectRaw(
                    <<<SQL
                         GROUP_CONCAT(survey_invite_id) as nps_invites

                    SQL)
                    ->nps()
                    ->module($this->module_type)
                    ->when(isSequence($this->period), function ($query) {
                        $query->sequenceFilterForRatingDistribution($this->period,true);
                    }, function ($query) {
                        $query->periodFilter($this->period, $this->custom_date);
                    })
                    ->filterAnswers($this->filters)
                    ->where(function($query) use($invites){
                        $query->whereIn('survey_invite_id',$invites);
                    });

                $operator = 'where';

                $nps_people = $nps_people->where(function ($query) use ($operator){
                    if(in_array('promoter',$this->custom_nps_filter))
                    {
                        $nps_people = $query->where('value','>=',9);
                        $operator = 'orWhere';
                    }

                    if(in_array('passive',$this->custom_nps_filter))
                    {
                        $operator = $operator.'Between';
                        $nps_people = $query->$operator('value',[7,8]);
                        $operator = 'orWhere';
                    }

                    if(in_array('detractor',$this->custom_nps_filter))
                    {
                        $nps_people = $query->$operator('value','<=',6);
                    }
                });

                $nps_people = $nps_people->first();

                $answers = SurveyAnswer::query()
                    ->selectRaw(DB::raw('survey_answers.*'))
                    ->benchmark()
                    ->module($this->module_type)
                    ->when(isSequence($this->period), function ($query) {
                        $query->sequenceFilterForRatingDistribution($this->period, true);
                    }, function ($query) {
                        $query->periodFilter($this->period, $this->custom_date);
                    })
                    ->filterAnswers($this->filters)
                    ->where(function ($query) use ($invites) {
                        $query->whereIn('survey_invite_id', $invites);
                    })
                    ->where("questionable_id", $this->surveyAnswer['questionable']['id'])
                    ->when($this->surveyProgressFilter, function ($query) {
                        $query = $this->surveyProgressFilterQuery($query);
                    })
                    ->where("questionable_type", $questionableType)
                    ->where("answering_multiple", 0)
                    ->get();
            }

            }
         return $answers;
    }

    public function open($surveyAnswer,string $period=null,string $custom_date=null,string $module_type=null): void
    {
        $this->surveyAnswer = $surveyAnswer;
        $this->period = $period;
        $this->custom_date = $custom_date;
        $this->module_type = $module_type;
        $this->modal        = true;
        if (!$this->filters) {
            $this->filters = getFiltersFromRestrictions($this->module_type);
        }

        if (isset($surveyAnswer['question_type']) && $surveyAnswer['question_type'] == Question::RANK_ORDER_ID) {
            $invites  = $surveyAnswer['invites'];
            $questionableType = $surveyAnswer['questionable_type'] == 1 ? Question::class : TenantQuestion::class;
            $submittedAnswers = SurveyAnswer::whereIn('survey_invite_id', explode(',', $invites))
                ->where('questionable_type', $questionableType)
                ->where('questionable_id', $surveyAnswer['questionable_id'])
                ->where('question_type', Question::RANK_ORDER)
                ->where('answering_multiple', 0)
                ->get()
                ->pluck('value', 'id');

            // dd(count($submittedAnswers));
            $answersCount = [
                $this->surveyAnswer['choice'] => array_fill(0, count($this->answers), ['value' => 0, 'ans_ids' => []]), // Dynamic count based on answers
                'total' => 0,
            ];

            foreach ($submittedAnswers as $answerId => $row) {
                foreach (json_decode($row, true) as $index => $value) {
                    if (!isset($answersCount[$this->surveyAnswer['choice']][$index])) {
                        $answersCount[$this->surveyAnswer['choice']][$index] = ['value' => 0, 'ans_ids' => []]; // Initialize ans_ids array
                    }
                    if ($value === $this->surveyAnswer['choice']) {
                        $answersCount[$this->surveyAnswer['choice']][$index]['value']++;
                        $answersCount[$this->surveyAnswer['choice']][$index]['ans_ids'][] = $answerId; // Add answerId to ans_ids
                        $answersCount['total']++;
                    }
                }
            }
            if (isset($answersCount[$this->surveyAnswer['choice']]) && is_array($answersCount[$this->surveyAnswer['choice']])) {
                foreach ($answersCount[$this->surveyAnswer['choice']] as $index => $countData) {
                    $this->percentages[$index]['percentage'] = $answersCount['total'] > 0 ? round(($countData['value'] / $answersCount['total']) * 100, 2) : 0;
                    $this->percentages[$index]['count'] = $countData['value']; // Add count to percentages array
                    $this->percentages[$index]['ans_ids'] = $countData['ans_ids']; // Assign ans_ids
                }
            }
        }

        if (isset($surveyAnswer['question_type']) && $surveyAnswer['question_type'] == Question::RATING_GRID_ID) {
            $invites  = $surveyAnswer['invites'];
            $choice = $surveyAnswer['choice'];
            $submittedAnswers =  SurveyAnswer::whereIn('survey_invite_id', explode(',', $invites))
                ->where('questionable_type', TenantQuestion::class)
                ->where('questionable_id', $surveyAnswer['questionable_id'])
                ->where('question_type', Question::RATING_GRID)
                ->where('answering_multiple', 0)
                ->get();

            $answers = $submittedAnswers->pluck('value')->map(fn($item) => json_decode($item, true));
            $choice = trim($choice);
            $uniqueKeys = collect($answers)->flatMap(fn($item) => array_keys($item))->unique()->values()->all();

            $choiceId = QuestionAnswer::whereIn('id', $uniqueKeys)->where('name', $choice)->first()?->id;

            $choiceScores = collect($answers)->map(function ($item) use ($choiceId) {
                return is_numeric($item[$choiceId]) ? (int) $item[$choiceId] : $item[$choiceId]; // Cast to int if numeric, else return as string
            });
            $this->answers = [1, 2, 3, 4, 5, 'N/A'];
            foreach ($this->answers as $value) {
                $count = $choiceScores->filter(fn($score) => $score === $value)->count();
                $this->percentages[$value]['percentage'] = round(($count / $choiceScores->count()) * 100);
                $this->percentages[$value]['count'] = $count;
                $this->percentages[$value]['ans_ids'] = $count != 0 ? $submittedAnswers->filter(fn($answer) => isset(json_decode($answer->value, true)[$choiceId]) && json_decode($answer->value, true)[$choiceId] == $value)->pluck('id')->toArray() : [];
            }
        }
      
    }

    public function setPeriod(string $period, string $custom_date): void
    {
        $this->period = $period;
        $this->custom_date = $custom_date;
    }

    public function setFilters(array $filters, $setResultEmpty = false, $toRemove = []): void
    {
        $this->filters = $filters;

        if(count($this->filters)==0){
            $this->is_custom_nps_filter = false;
            $this->custom_nps_filter = [];
        }
        else {
            if(isset($this->filters) && isset($this->filters['custom_nps_filter']) ){
                $this->is_custom_nps_filter = true;
                foreach ($this->filters['custom_nps_filter'] as $nps_filter){
                    $this->custom_nps_filter = $nps_filter;
                }
                unset($this->filters['custom_nps_filter']);
            }
            else {
                $this->is_custom_nps_filter = false;
                $this->custom_nps_filter = [];
            }
        }
        $this->surveyProgressFilter = setSurveyProgressFilter($this->filters);
        unset($this->filters['survey_progess']);
    }

    public function render()
    {
        return view('livewire.tenant.dashboard.rating-distribution');
    }

    public function showRespondents($ans_ids,$rating){

        $this->selected_ans_ids = $ans_ids;
        $this->selected_rating = $rating;
        $this->resetPage();
        $this->respondent_modal = true;

    }

    public function getRecordsProperty()
    {
        if (!$this->selected_ans_ids) {
            return SurveyAnswer::query()->paginate(0);
        }
        $records = SurveyAnswer::query()
            ->selectRaw(<<<SQL
                    survey_answers.id,
                    survey_answers.module_type,
                    survey_answers.created_at as date_submitted,
                    survey_answers.value,
                    survey_answers.survey_invite_id,
                    CONCAT(people.first_name, ' ', people.last_name) as people_name,
                    CONCAT(students.first_name, ' ', students.last_name) as student_name,
                    CONCAT(employees.firstname, ' ', employees.lastname) as employee_name,
                    survey_invites.custom_email
            SQL)->whereIn('survey_answers.id', $this->selected_ans_ids)
            ->module($this->module_type)
            ->join('survey_invites', 'survey_invites.id', '=', 'survey_answers.survey_invite_id')
            ->leftJoin('people', 'people.id', '=', 'survey_invites.people_id')
            ->leftJoin('students', 'students.id', '=', 'survey_invites.student_id')
            ->leftJoin('employees', 'employees.id', '=', 'survey_invites.employee_id')
            ->where('survey_invites.anonymity', 0)
            ->where('survey_invites.status', 'answered')
            ->orderBy($this->sortBy, $this->sortDirection)->paginate($this->perPage);;
        return $records;
    }

    public function export(){

        if(count($this->selected_ans_ids)>0){

            $fileName = sprintf('export_respondents-%s.xlsx', date('Y-m-d'));
            return Excel::download(new RatingRespondentsExport($this->selected_ans_ids), $fileName);
        }
        return false;

    }

    public function surveyProgressFilterQuery($query)
    {
        if ($this->surveyProgressFilter) {
            $query = $query->when($this->surveyProgressFilter,function ($query) {
                $query->join('survey_invites',function($join){
                    $join->on('survey_answers.survey_invite_id','=','survey_invites.id');
                })->whereIn('survey_invites.status',$this->surveyProgressFilter); 
            });
        }
        return $query;
    }
}
