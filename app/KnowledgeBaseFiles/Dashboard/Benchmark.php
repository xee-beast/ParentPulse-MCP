<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use App\Contracts\SurveyBuilder\SurveyQuestion;
use App\Models\Question;
use App\Reports\LikertAnswersReportCentral;
use App\Scopes\PeriodScope;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Benchmark extends Component
{
    const HIGHEST_TO_LOWEST      = 'highest_to_lowest';
    const LOWEST_TO_HIGHEST      = 'lowest_to_highest';
    const POSITIVE_CHANGE        = 'positive_change';
    const NEGATIVE_CHANGE        = 'negative_change';
    const POSITIVE_BENCHMARK_GAP = 'positive_benchmark_gap';
    const NEGATIVE_BENCHMARK_GAP = 'negative_benchmark_gap';

    public bool $scopeActiveSurvey = true;

    public bool $applyBenchmarkFilter = false;

    public bool $graphsModal = false;
    public bool $is_custom_nps_filter = false;

    public ?SurveyQuestion $benchmarkQuestionSelected = null;

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string  $module_type = 'all';
    public $userPermissions = [];

    public array $filters = [];
    public array $custom_nps_filter = [];

    public string $sortBy = 'highest_to_lowest';
    public $getPermissionForModule = [];
    public $userPermisisons = [];
    public $benchmark = null;
    public $index,$isFirst,$isLast,$questionType;
    public $questionable_type;
    public $questionableId;
    public $benchmarkSchoolFilter = null;
    
    protected $listeners = [
        'set-benchmark-school-filter' => 'setBenchmarkSchoolFilter',
    ];

    public function render()
    {
        return view('livewire.tenant.dashboard.benchmark');
    }

    public function mount($questionableId) {
        $this->questionableId = $questionableId;
    }

    public function loadBenchmark()
    {
        if ($this->questionType == Question::NPS && $this->questionable_type == Question::Questionable_TYPE) {
            $cacheKey = 'benchmark_'.$this->questionableId.'_'.$this->module_type.($this->benchmarkSchoolFilter ? '_'.strtolower(implode('_', $this->benchmarkSchoolFilter)) : '');
            $cacheKey = str_replace(' ','_',$cacheKey);
            $this->benchmark =  getBenchmark($cacheKey,$this->questionableId, $this, $this->benchmarkSchoolFilter);
        }else{
            $this->benchmark = 'N/A';
        }
        $this->emit('bechmark_'.$this->questionableId.$this->questionable_type, $this->benchmark);
    }

    public function setBenchmarkSchoolFilter($filter = null )
    {
        $this->benchmarkSchoolFilter = $filter;
        $this->benchmark = null;
        $this->loadBenchmark();
    }
}
