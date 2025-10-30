<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use Cache;
use App\Models\{Question, SurveySetting, Tenant};
use App\Models\Tenant\SurveyInvite;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant\{Question as TenantQuestion, SurveyAnswer, SurveyCycle};
use App\Reports\{LikertAnswersReport, LikertAnswersReportCentral, MultipleChoiceAnswersReport, MultipleChoiceAnswersReportCentral};
use App\Scopes\PeriodScope;
use App\Services\QueryFilterService;
use Illuminate\Support\Collection;
use Livewire\Component;
use Illuminate\Database\Query;

/**
 * @property-read Collection $multipleChoiceAnswers
 * @property-read Collection $benchmarkAnswers
 */
class Results extends Component
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

    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';
    public string $comparisionCustomDate = '';
    public string  $module_type = 'all';
    public $userPermissions = [];
    public $sequences;
    public $comparisionFilter;
    public $comparisonAnswers;
    public array $filters = [];
    public array $custom_nps_filter = [];

    public array $surveyProgressFilter = [SurveyInvite::ANSWERED,SurveyInvite::SEND];

    public string $sortBy = 'highest_to_lowest';
    public $getPermissionForModule = [];
    public $userPermisisons = [];
    public $setResultEmpty = false;
    public $cacheReset = false;
    public $showBenchmarkOptions = false;
    public array $optionsSorting = [
        self::HIGHEST_TO_LOWEST      => 'Highest to lowest',
        self::LOWEST_TO_HIGHEST      => 'Lowest to Highest',
        self::POSITIVE_CHANGE        => 'Positive Change',
        self::NEGATIVE_CHANGE        => 'Negative Change',
        self::POSITIVE_BENCHMARK_GAP => 'Positive Benchmark Gap',
        self::NEGATIVE_BENCHMARK_GAP => 'Negative Benchmark Gap',
    ];

    protected $listeners = [
        'filters::period'          => 'setPeriod',
        'filters::surveys'         => 'setScopeActiveSurveys',
        'filters::apply'           => 'setFilters',
        'dashboard-page::change_module_type' => 'setModuleType',
        'filters::comparision'          => 'setComparisionFilter',
        'comparision::reset'       => 'resetComparision',
        'filters::clear-cache' => 'clearCache',
    // 'filters::apply-benchmark' => 'setApplyBenchmarkFilters',
    ];


    public function mount() {
        $this->userPermissions = userPermissions();
        if (!$this->filters) {
            $this->filters = getFiltersFromRestrictions($this->module_type);
        }
        
        $this->showBenchmarkOptions = getBenchmarkOption();
        if (!$this->showBenchmarkOptions) {
             array_pop($this->optionsSorting);
             array_pop($this->optionsSorting);
        }
        $this->surveyProgressFilter = getSurveyProgressFilter($this->filters);
    }

    public function getMultipleChoiceAnswersProperty(): Collection
    {
        if ($this->setResultEmpty) {
            Cache::forget(getCacheKeyUserBased('multipleChoiceDashboard'));
            Cache::remember(getCacheKeyUserBased('multipleChoiceDashboard'), 3600, function () {
                return collect([]);
            });
            return collect([]);
        }
        $multiple_choice_answers =  (new MultipleChoiceAnswersReportCentral(
            $this->filters,
            $this->period,
            $this->custom_date,
            $this->scopeActiveSurvey,
            $this->custom_nps_filter,
            $this->surveyProgressFilter,
            $this->module_type,
            getPermissionForModule:$this->getPermissionForModule
        ))->run($this->cacheReset);

        if ($this->comparisionFilter) {
            $this->comparisonAnswers =   (new MultipleChoiceAnswersReportCentral(
                $this->filters,
                $this->comparisionFilter,
                $this->comparisionCustomDate,
                $this->scopeActiveSurvey,
                $this->custom_nps_filter,
                $this->surveyProgressFilter,
                $this->module_type,
                getPermissionForModule:$this->getPermissionForModule
            ))->run($this->cacheReset);
        }

        $grouped = $multiple_choice_answers->mapToGroups(function ($item, $key) {
            if ($this->comparisionFilter) {
                $item->previous_answers_count = $this->comparisonAnswers->where('questionable_id', $item->questionable_id)->first()->answers_count ?? 'N/A';
                $item->previous_answers =   $this->comparisonAnswers->where('questionable_id', $item->questionable_id)->first()->answers ?? 'N/A';
            }
            return [$item->questionable_id  => $item];
        });
        Cache::forget(getCacheKeyUserBased('multipleChoiceDashboard'));
        Cache::remember(getCacheKeyUserBased('multipleChoiceDashboard'), 3600, function () use ($grouped) {
            return $grouped;
        });
        return $grouped;
    }


    public function getBenchmarkAnswersProperty(): Collection
    {
        if ($this->setResultEmpty) {
            Cache::forget(getCacheKeyUserBased('benchmarkAnswers'));
            Cache::remember(getCacheKeyUserBased('benchmarkAnswers'), 3600, function () {
                return collect([]);
            });
            return collect([]);
        }
        $cacheKey = getCacheKeyDashboard('benchmarkAnswers',$this->module_type,$this->period,$this->custom_date,$this->filters,$this->custom_nps_filter,$this->scopeActiveSurvey,$this->surveyProgressFilter);
        $cacheKey =  $this->comparisionFilter ? $cacheKey . '_' . $this->comparisionFilter . '_' . md5($this->comparisionCustomDate) : $cacheKey;
        if($this->cacheReset)
        {
            Cache::forget($cacheKey);
        }
        $benchmarkReports = Cache::remember($cacheKey, config('constants.CACHE_TIME'), function () {
            $likertAnswersReport =  (new LikertAnswersReportCentral(
            $this->period,
            $this->custom_date,
            $this->filters,
            $this->scopeActiveSurvey,
                $this->applyBenchmarkFilter,
                $this->custom_nps_filter,
                $this->surveyProgressFilter,
                $this->module_type,
                comparisionFilter:$this->comparisionFilter,
                getPermissionForModule: $this->getPermissionForModule
            ));
            $benchmarkReports = $likertAnswersReport->run();
            $benchmarkReports = $benchmarkReports->map(function($benchmarkReport) use($likertAnswersReport){
                $benchmarkReport->period_diff = 0;
                $benchmarkReport->previous_score = null;
                $benchmarkReport->previous_answers_count = null;

                if ($this->comparisionFilter) {
                    $comparisionQuery = $likertAnswersReport->getComparisionFilterSubQuery($benchmarkReport, $this->comparisionFilter, $this->comparisionCustomDate);
                    if (!is_null($comparisionQuery) && isset($comparisionQuery->previous_score)) {
                        $benchmarkReport->period_diff = $comparisionQuery->previous_score == 0 ? 'N/A' : $benchmarkReport->score - $comparisionQuery->previous_score;
                        $benchmarkReport->previous_score = $comparisionQuery->previous_score;
                    $benchmarkReport->previous_answers_count = $comparisionQuery->previous_answers_count;
                    }else
                    {
                    $benchmarkReport->period_diff = 'N/A';
                    }
                }
                return $benchmarkReport;
            }); 
            return $benchmarkReports;
        });
    
        $benchmarkReports = $benchmarkReports->when($this->sortBy == self::HIGHEST_TO_LOWEST, fn (Collection $benchmark) => $benchmark->sortByDesc('score'))
            ->when($this->sortBy == self::LOWEST_TO_HIGHEST, fn (Collection $benchmark) => $benchmark->sortBy('score'))
            ->when($this->sortBy == self::POSITIVE_CHANGE, fn (Collection $benchmark) => $benchmark->sortByDesc('period_diff'))
            ->when($this->sortBy == self::NEGATIVE_CHANGE, fn (Collection $benchmark) => $benchmark->sortBy('period_diff'))
            ->when($this->sortBy == self::POSITIVE_BENCHMARK_GAP, fn (Collection $benchmark) => $benchmark->sortByDesc(fn ($benchmarkQuestion) => $benchmarkQuestion->score - $this->getBenchmarkValue($benchmarkQuestion, true)))
            ->when($this->sortBy == self::NEGATIVE_BENCHMARK_GAP, fn (Collection $benchmark) => $benchmark->sortBy(fn ($benchmarkQuestion) => $benchmarkQuestion->score - $this->getBenchmarkValue($benchmarkQuestion, true)));
        $grouped = $benchmarkReports->mapToGroups(function ($item, $key) {
            return [$item->questionable_id . $item->questionable_type => $item];
        });
        Cache::forget(getCacheKeyUserBased('benchmarkAnswers'));
         Cache::remember(getCacheKeyUserBased('benchmarkAnswers'), 3600, function () use ($grouped) {
             return $grouped;
         });
        return $grouped;
    }

    public function getRoundedBenchmark($answer): int|String
    {
        return cache()->has('benchmark'.$answer->questionable_id.$answer->module_type) ?
            ( Cache::get('benchmark'.$answer->questionable_id.$answer->module_type) ? Cache::get('benchmark'.$answer->questionable_id.$answer->module_type) : 'N/A' )
            : getBenchmark('benchmark'.$answer->questionable_id.$answer->module_type,$answer->questionable_id, $this);
    }

    public function setPeriod(string $period, string $custom_date): void
    {
        $this->period = $period;
        $this->custom_date = $custom_date;
        $this->cacheReset = false;
    }

    public function setComparisionFilter($comparisionFilter, string $comparisionCustomDate): void
    {
        $this->comparisionFilter = $comparisionFilter;
        $this->comparisionCustomDate = $comparisionCustomDate;
        $this->cacheReset = false;

    }

    public function setScopeActiveSurveys(bool $onlyActive): void
    {
        $this->scopeActiveSurvey = $onlyActive;
    }

    public function setFilters(array $filters, $setResultEmpty = false, $toRemove = []): void
    {
        $this->filters = $filters;
        $this->setResultEmpty = $setResultEmpty;
        if (count($this->filters) == 0) {
            $this->is_custom_nps_filter = false;
            $this->custom_nps_filter = [];
        } else {
            if (isset($this->filters) && isset($this->filters['custom_nps_filter'])) {
                $this->is_custom_nps_filter = true;
                foreach ($this->filters['custom_nps_filter'] as $nps_filter) {
                    $this->custom_nps_filter = $nps_filter;
                }
                unset($this->filters['custom_nps_filter']);
            } else {
                $this->is_custom_nps_filter = false;
                $this->custom_nps_filter = [];
            }
        }
        $this->surveyProgressFilter = setSurveyProgressFilter($this->filters);
        unset($this->filters['survey_progess']);
        $this->cacheReset = false;


    }

    private function getBenchmarkValue($benchMarkQuestion, $returnInteger = false): int | string
    {
        $value = getQuestionBenchmarkValue($benchMarkQuestion,$this->module_type);
        if($returnInteger) {
            return $value == "N/A" ? 0 : $value;
        }
        return $value;
    }


    public function getMultipleAnswerCount($answer, $choice)
    {
        return _getMultipleAnswerCount($answer,$choice);
    }

    public function getRatingGridCount($answer, $choice)
    {
        return _getRatingGridCount($answer,$choice);
    }

    public function getRankOrderCount($answer, $choice)
    {
        return _getRankOrderCount($answer,$choice);
    }

    public function setModuleType(string $type): void
    {
        $this->module_type = $type;
        $this->cacheReset = false;
    }
    public function setApplyBenchmarkFilters(bool $apply)
    {
        $this->applyBenchmarkFilter = $apply;
        $this->cacheReset = false;

    }

    protected function calculateBenchmarkOnNpsFilter($database, $benchmarkReport)
    {


        $nps_filters = DB::table("{$database}.survey_answers")
            ->selectRaw(
                <<<SQL
                   GROUP_CONCAT(survey_invite_id) as nps_invites
            SQL
            )
            ->where('survey_answers.value', 'not LIKE', '%N/A%')
            ->whereNotNull('survey_answers.value')
            ->where('survey_answers.question_type', Question::NPS)
            ->where('survey_answers.questionable_type', Question::class);

        $operator = 'where';

        $nps_filters = $nps_filters->where(function ($query) use ($operator) {
            if (in_array('promoter', $this->custom_nps_filter)) {
                $nps_filters = $query->where('survey_answers.value', '>=', 9);
                $operator = 'orWhere';
            }

            if (in_array('passive', $this->custom_nps_filter)) {
                $operator = $operator . 'Between';
                $nps_filters = $query->$operator('survey_answers.value', [7, 8]);
                $operator = 'orWhere';
            }

            if (in_array('detractor', $this->custom_nps_filter)) {
                $nps_filters = $query->$operator('survey_answers.value', '<=', 6);
            }
        });

        $nps_filters = $nps_filters->first();

        $avgCount = 'AVG(value)';
        $benchmark =  DB::table("{$database}.survey_answers")
            ->selectRaw(
                <<<SQL
                ROUND($avgCount * 10,0) as score
            SQL
            )
            ->where('survey_answers.value', 'not LIKE', '%N/A%')
            ->whereNotNull('survey_answers.value')
            ->when($this->applyBenchmarkFilter, function (Query\Builder $query) use ($database) {
                return $query
                    ->tap(fn (Query\Builder $query) => $this->applyPeriodFilter($query))
                    ->tap(fn (Query\Builder $query) => SurveyAnswer::filterBenchmarkAnswers($query, $this->filters, $database));
            })
            ->where('survey_answers.question_type', Question::BENCHMARK);
        if ($this->module_type == Tenant::ALL) {
            $benchmark = $benchmark->whereIn('survey_answers.module_type', array_keys($this->getPermissionForModule));
        } elseif ($this->module_type == Tenant::ALLPULSE) {
            $benchmark = $benchmark->whereIn('survey_answers.module_type', array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
        } else {
            $benchmark = $benchmark->where('survey_answers.module_type', $this->module_type);
        }
        $benchmark = $benchmark->where('survey_answers.questionable_type', Question::class)
            ->whereIn('survey_answers.survey_invite_id', explode(',', $nps_filters->nps_invites))
            ->where('survey_answers.questionable_id', $benchmarkReport->questionable_id);

        return $benchmark;
    }

    private function applyPeriodFilter(Query\Builder $query): Query\Builder
    {
        if (isSequence($this->period)) {
            return QueryFilterService::applySequence($query,$this->period);
        }
        if ($this->period == PeriodScope::Custom) {

            if (isset($this->custom_date) && !empty($this->custom_date)) {
                $dates = convertCustomDateRange($this->custom_date);
            }
        }

        return match ($this->period) {
            PeriodScope::ALL_TIME          => $query->whereNotNull('updated_at'),
            PeriodScope::LAST_365_DAYS     => $query->whereDate('updated_at', '>=', now()->subDays(365)->format('Y-m-d')),
            PeriodScope::LAST_THREE_MONTHS => $query->whereDate('updated_at', '>=', now()->subMonths(3)->format('Y-m-d')),
            PeriodScope::LAST_THIRTY_DAYS  => $query->whereDate('updated_at', '>=', now()->subDays(30)->format('Y-m-d')),
            PeriodScope::TODAY             => $query->whereDate('updated_at', '=', now()->format('Y-m-d')),
            PeriodScope::Custom             => (isset($this->custom_date) && !empty($this->custom_date) && count($dates) > 0) ? $query->whereDate('updated_at', '>=', $dates['start_date']->format('Y-m-d'))->whereDate('updated_at', '<=', $dates['end_date']->format('Y-m-d')) : $query->whereDate('updated_at', '=', now()->format('Y-m-d')),
        };
    }

    protected function baseBenchmarkQuery($benchmarkReport, $answer_count_query, $is_previous)
    {
        $bechmarkR = SurveyAnswer::query()
            ->selectRaw(
                <<<SQL
                               ROUND(AVG(value)*10,0) as score
                    SQL
            )
            ->benchmark()
            ->filterAnswers($this->filters)
            ->where("questionable_id", $benchmarkReport->questionable_id)
            ->where('value', 'not LIKE', '%N/A%');

        if ($this->module_type == Tenant::ALL || $this->module_type == Tenant::ALLPULSE) {
            $bechmarkR = $bechmarkR->whereIn('module_type', array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
        } else {
            $bechmarkR = $bechmarkR->where('module_type', $this->module_type);
        }
        $bechmarkR = $bechmarkR->whereNotNull('value');
        if ($is_previous) {
            $bechmarkR = $bechmarkR->previousPeriodFilter($this->period, $this->custom_date)->whereIn('survey_invite_id', explode(',', $answer_count_query->all_nps_previous_people_invites));
        } else {
            $bechmarkR = $bechmarkR->periodFilter($this->period, $this->custom_date)->whereIn('survey_invite_id', explode(',', $answer_count_query->all_nps_people_invites));
        }
        $bechmarkR = $bechmarkR->first();

        return $bechmarkR;
    }

    public function resetComparision(){
        $this->comparisionFilter = '';
    }
    public function render()
    {
        return view('livewire.tenant.dashboard.results');
    }

    public function clearCache()
    {
        $this->cacheReset = true;
    }
}
