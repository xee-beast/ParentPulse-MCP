<?php

namespace App\Http\Livewire\Tenant\Dashboard\ScoreOverTime;

use App\Contracts\SurveyBuilder\SurveyQuestion;
use App\Models\Question;
use App\Models\Tenant\Question as TenantQuestion;
use App\Models\Tenant\QuestionAnswer;
use App\Models\Tenant\SurveyAnswer;
use App\Scopes\PeriodScope;
use App\Reports\{NpsOverTimeReport, ScoreOverTimeReport};
use Livewire\Component;

class Index extends Component
{
    public ?int $benchmarkQuestionId = null;

    public ?string $benchmarkQuestionableType = null;

    public ?array $scoreOverMonthToChart = null;
    public ?string $score_color = "#4488d3";

    public array $monthCategories = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public array $yearsOptions = [];

    public int $xAxisMin = 0;

    public int $xAxisMax = 100;

    public int $xAxisTick = 1;

    public ?array $barsColorsRange = null;

    public string $scoreOverMonthYear = '';
    public bool $isNps = false;

    public $period = PeriodScope::DEFAULT_PERIOD;
    public $custom_date = '';
    public string  $module_type = 'parent';

    public bool $is_custom_nps_filter = false;
    public bool $is_benchmark_question_graph = false;
    public array $filters = [];
    public array $custom_nps_filter = [];
    public array $surveyProgressFilter = [];

    protected $listeners = [
        'dashboard::set-benchmark-question' => 'setSelectedBenchmarkQuestion',
        'dashboard::set-nps-question'       => 'loadScoreOverMonth',
        'filters::apply'       => 'setFilters',
        'filters::clear-cache' => '$refresh',
    ];

    public ?SurveyQuestion $benchmarkQuestionSelected = null;
    public $graphsModal = false;
    public $npsGraphsModal = false;

    public function mount()
    {
        $this->scoreOverMonthYear = now()->year;
        $this->yearsOptions       = range(now()->year, 2021);
    }

    public function setSelectedBenchmarkQuestion(?int $questionableId = null, ?string $questionableType = null,string $period=null,string $custom_date=null,string $module_type=null): void
    {
        $this->benchmarkQuestionId = $questionableId;

        $this->period = $period;
        $this->custom_date = $custom_date;
        $this->module_type = $module_type;
        if (!is_null($this->benchmarkQuestionId)) {
            $this->is_benchmark_question_graph = true;
            $this->benchmarkQuestionableType = $questionableType;
            $this->benchmarkQuestionSelected = match ($questionableType) {
				(string)TenantQuestion::Questionable_TYPE => TenantQuestion::withTrashed()->find($questionableId),
                default => Question::withTrashed()->find($questionableId),
            };
            $this->loadScoreOverMonth(false,$this->period,$this->custom_date,$this->module_type);
            $this->graphsModal = true;
        }
        if (!$this->filters) {
            $this->filters = getFiltersFromRestrictions($this->module_type);
        }
    }

    public function getGraphsModalTitleProperty()
    {
        $title = $this->isNps ? 'Net Promoter Score' : $this->benchmarkQuestionSelected?->name;

        return $title ?? 'Graphs';
    }
    public function setSelectedNpsQuestion():void
    {
        $this->reset('benchmarkQuestionId', 'benchmarkQuestionType','is_benchmark_question_graph');
        $this->loadScoreOverMonth(true,$this->period,$this->custom_date,$this->module_type);
    }

    public function resetScoreOverMonthToChart(): void
    {
       $this->reset('module_type');
        for ($i = 0; $i < 12; $i++) {
            $this->scoreOverMonthToChart[$i] = [
                'y'                         => 0,
                'x'                         => $this->monthCategories[$i],
                'totalQuantity'             => 0,
                'tooltipTimeLabel'          => $this->monthCategories[$i] . '/' . $this->scoreOverMonthYear,
                'tooltipDataLabel'          => 'Score',
                'tooltipTotalQuantityLabel' => 'Answers',
            ];
        }
    }

    public function loadScoreOverMonth(bool $isNps = false, string $period=null,string $custom_date=null,$module_type = null): void
    {
        $this->resetScoreOverMonthToChart();
        $this->period = $period;
        $this->custom_date = $custom_date;
        $this->module_type = $module_type;

        if ($this->module_type == 'parent') {

            $this->score_color = '#4488d3';

        } elseif ($this->module_type == 'student') {

            $this->score_color = '#db874a';

        } elseif ($this->module_type == 'employee') {

            $this->score_color = '#ae45ca';
        }
        else {

            $this->score_color = '#4488d3';
        }
        $this->is_benchmark_question_graph = true;
        if($isNps){
            $this->benchmarkQuestionId = null;
            $this->benchmarkQuestionableType = null;
            $this->is_benchmark_question_graph = false;
        }
        if($this->benchmarkQuestionableType == Question::Questionable_TYPE){
            $this->benchmarkQuestionableType = Question::class;
        }elseif($this->benchmarkQuestionableType == TenantQuestion::Questionable_TYPE){
            $this->benchmarkQuestionableType = TenantQuestion::class;
        }
        $registerByMonth = match ($isNps) {
            false => (new ScoreOverTimeReport($this->benchmarkQuestionId, $this->benchmarkQuestionableType, $this->scoreOverMonthYear,$this->custom_nps_filter,$this->surveyProgressFilter,$this->period,$this->custom_date,$this->filters,$this->module_type))->monthlyGrouped(),
            true  => (new NpsOverTimeReport($this->scoreOverMonthYear,$this->custom_nps_filter,$this->surveyProgressFilter,$this->period,$this->custom_date,$this->filters,$this->module_type))->monthlyGrouped()
        };
        foreach ($registerByMonth as $data) {
            $this->scoreOverMonthToChart[data_get($data, 'month')] = [
                'y'                         => intval(data_get($data, 'score_average', 0)),
                'x'                         => $this->monthCategories[data_get($data, 'month')],
                'totalQuantity'             => data_get($data, 'answers_count', 0),
                'tooltipTimeLabel'          => $this->monthCategories[data_get($data, 'month')] . '/' . $this->scoreOverMonthYear,
                'tooltipDataLabel'          => 'Score',
                'tooltipTotalQuantityLabel' => 'Answers',
            ];
        }
        if($isNps){
            $this->npsGraphsModal = true;
        }
    }

    public function updatedScoreOverMonthYear(): void
    {
        $is_nps = true;
        if(!is_null($this->benchmarkQuestionId)){
            $is_nps = false;
        }
        $this->loadScoreOverMonth($is_nps,$this->period,$this->custom_date,$this->module_type);
    }

    public function render()
    {
        if($this->isNps){
            return view('livewire.tenant.dashboard.nps.score-over-time.index');
        }
        return view('livewire.tenant.dashboard.score-over-time.index');
    }

    public function setFilters(array $filters,$setResultEmpty = false): void
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

}
