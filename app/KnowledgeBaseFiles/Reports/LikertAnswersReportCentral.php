<?php

namespace App\Reports;

use App\Models\{Question, Tenant};
use App\Models\Tenant\Question as TenantQuestion;
use App\Scopes\PeriodScope;
use App\Services\QueryFilterService;
use App\Traits\Dashboard\DashboardResultTrait;
use Illuminate\Database\Query;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Str;

class LikertAnswersReportCentral
{
    use DashboardResultTrait;
    private $selectedModuleType = [];

    public function __construct(
        private string $period,
        private string $custom_date = '',
        private array  $filters = [],
        private bool   $scopeActiveSurvey = true,
        private bool   $filterBenchmark = false,
        private $filtersTo = [],
        private $surveyProgressFilter = [],
        private string $module_type = 'all',
        private bool  $calculateBenchmakr = true,
        private  $comparisionFilter = null,
        private array $getPermissionForModule = []

    ) {
        if (!count($this->getPermissionForModule)) {
            $this->getPermissionForModule = getPermissionForModule('_view_dashboard');
        }
    }

    public function run(): mixed
    {
        $this->setSelectModule();
        $tenant = tenant();

        tenancy()->central(function () use (&$results, $tenant) {

            $score = "ROUND(AVG(csa.nps_benchmark_value) * 10)";
            $results = DB::table('central_survey_answers as csa')
                ->select([
                    DB::raw('MIN(csa.survey_answer_id) as id'),
                    DB::raw("JSON_ARRAYAGG(csa.survey_invite_id) as invites"), // Replacing GROUP_CONCAT
                    'csa.questionable_id',
                    'csa.questionable_type',
                    'csa.module_type',
                    DB::raw('COALESCE(questions.name, custom_question.name) as question_name'),
                    DB::raw("$score as score"),
                    DB::raw('COUNT(DISTINCT csa.survey_invite_id) as answers_count'),
                ])
                ->leftJoin('questions', function ($join) {
                    $join->on('csa.questionable_id', '=', 'questions.id')
                        ->where('csa.questionable_type', '=', Question::Questionable_TYPE);
                })
                ->leftJoin(DB::raw('`' . $tenant->tenancy_db_name . '`.questions custom_question'), function ($join) {
                    $join->on('csa.questionable_id', '=', 'custom_question.id')
                        ->where('csa.questionable_type', '=', TenantQuestion::Questionable_TYPE);
                })
                ->where('csa.tenant_id', $tenant->id);

            // Apply Sequence or DateFilter Conditionally
            if (isSequence($this->period)) {
                // Apply sequence filter for the main period
                $results = QueryFilterService::applySequence($results, $this->period, 'csa', '=', $tenant);
            } else {
                // Apply date filter if it's not a sequence
                $results = QueryFilterService::applyDateFilter($results, $this->period, $this->custom_date, 'csa.survey_answers_updated_at');

                // Apply comparison filter if it is a sequence
                if (isSequence($this->comparisionFilter)) {
                    $results = QueryFilterService::applySequence($results, $this->comparisionFilter, 'csa', '!=', $tenant);
                }
            }

            // Apply Additional Filters
            $results = QueryFilterService::applyFilterAnswers($results, $this->filters, $this->filtersTo, 'csa', $tenant);

            // Ensure Active Surveys Exist
            $results = QueryFilterService::activeSurveyExsits($results, $this->scopeActiveSurvey, $tenant, 'csa');

            // Apply NPS Filter
            $results = QueryFilterService::npsFilter($results, $this->filtersTo);

            $period = $this->period;
            if (isSequence($this->comparisionFilter)) {
                $period = $this->comparisionFilter;
            }
            // Apply Survey Progress Filter
            $results = QueryFilterService::surveyProgressFilter($this->period, $results, $this->surveyProgressFilter, $tenant, 'csa', $this->comparisionFilter);

            // Finalize Query
            $results = $results
                ->whereNotNull('csa.nps_benchmark_value')
                ->where('csa.question_type', Question::BENCHMARK_ID)
                ->whereIn('csa.module_type', $this->selectedModuleType)
                ->groupBy('csa.questionable_id', 'csa.questionable_type', 'csa.module_type')
                ->get();
        });
        return $results;
    }

    /**
     * @return string
     */
    public function getDateCondition(): string
    {
        $date = now()->subMonths(3)->format('Y-m-d');
        $filterDateCondition = "AND DATE(csa.`survey_answers_updated_at`) >= $date";
        if ($this->period == QueryFilterService::LAST_365_DAYS) {
            $date = now()->subDays(365)->format('Y-m-d');
            $filterDateCondition = "AND DATE(csa.`survey_answers_updated_at`) >= $date";
        }
        if ($this->period == QueryFilterService::LAST_THREE_MONTHS) {
            $date = now()->subMonths(3)->format('Y-m-d');
            $filterDateCondition = "AND DATE(csa.`survey_answers_updated_at`) >= $date";
        }
        if ($this->period == QueryFilterService::LAST_THREE_MONTHS) {
            $date = now()->subDays(30)->format('Y-m-d');
            $filterDateCondition = "AND DATE(csa.`survey_answers_updated_at`) >= $date";
        }
        if ($this->period == QueryFilterService::TODAY) {
            $date = now()->format('Y-m-d');
            $filterDateCondition = "AND DATE(csa.`survey_answers_updated_at`) = $date";
        }
        if ($this->period == QueryFilterService::ALL_TIME) {
            $filterDateCondition = "AND DATE(csa.`survey_answers_updated_at`) IS NOT NULL";
        }
        if ($this->period == QueryFilterService::Custom && isset($this->custom_date) && !empty($this->custom_date)) {
            $dates = convertCustomDateRange($this->custom_date);
            if (count($dates) > 0) {
                $startDate = $dates['start_date']->format('Y-m-d');
                $endDate = $dates['end_date']->format('Y-m-d');
                $filterDateCondition = "AND DATE(csa.`survey_answers_updated_at`) BETWEEN '$startDate' AND '$endDate'";
            } else {
                $date = now()->format('Y-m-d');
                $filterDateCondition = "AND DATE(csa.`survey_answers_updated_at`) = $date";
            }
        }

        return $filterDateCondition;
    }


    public function getComparisionFilterSubQuery($benchmarkReport, $comparisionFilter = null, $comparisionCustomDate)
    {
        if ($comparisionFilter == PeriodScope::Custom) {
            if (isset($comparisionCustomDate) && !empty($comparisionCustomDate)) {
                $dates = convertCustomDateRange($comparisionCustomDate);
                $dates_diff = $dates['start_date']->diffInDays($dates['end_date']);
                if ($dates_diff > 365) {
                    return collect();
                }
            }
        }
        $tenant = tenant();
        $previousScore = collect();
        tenancy()->central(function () use ($tenant, $benchmarkReport, &$previousScore, $comparisionFilter, $comparisionCustomDate) {
            $this->setSelectModule();
            $previousScore =  DB::table('central_survey_answers as previous_score_query')
                ->select(
                    DB::raw('ROUND(AVG(previous_score_query.nps_benchmark_value) * 10) as previous_score'),
                    DB::raw('count(distinct previous_score_query.survey_invite_id) as previous_answers_count'),
                )
                ->where('previous_score_query.tenant_id', $tenant->id)
                ->whereNotNull('previous_score_query.nps_benchmark_value')
                ->where('previous_score_query.question_type', Question::BENCHMARK_ID)
                ->where('previous_score_query.questionable_type', $benchmarkReport->questionable_type)
                ->where('previous_score_query.questionable_id', $benchmarkReport->questionable_id)
                ->where('previous_score_query.module_type', $benchmarkReport->module_type);
            if ($comparisionFilter != PeriodScope::ALL_TIME) {

                if (isSequence($comparisionFilter)) {
                    $previousScore = QueryFilterService::applySequence($previousScore, $comparisionFilter, 'previous_score_query', '=', $tenant);
                } else {
                    $previousScore = QueryFilterService::applyPreviousDateFilter($previousScore, $comparisionFilter, $comparisionCustomDate, 'previous_score_query.survey_answers_updated_at');
                }
            }

            $previousScore = QueryFilterService::applyFilterAnswers($previousScore, $this->filters, $this->filtersTo, 'previous_score_query', tenant: $tenant);
            $previousScore = QueryFilterService::activeSurveyExsits($previousScore, $this->scopeActiveSurvey, $tenant, 'previous_score_query');
            $previousScore = QueryFilterService::npsFilter($previousScore, $this->filtersTo)
                ->first();
        });
        return $previousScore;
    }

    public function getBenchmarkSubQuery($questionable_id, $benchmarkSchoolFilter = null)
    {

        $results = collect();
        $tenant = tenant();
        tenancy()->central(function () use (&$results, $questionable_id, $benchmarkSchoolFilter, $tenant) {
            $this->setSelectModule();
            $results = DB::table('central_survey_answers as benchmark_query')
                ->select(
                    DB::raw('ROUND(AVG(nps_benchmark_value) * 10) as benchmark'),
                )
                ->whereNotNull('nps_benchmark_value')
                ->where('question_type', Question::BENCHMARK_ID)
                ->where('questionable_type', Question::Questionable_TYPE)
                ->where('module_type', $this->module_type)
                ->when($this->filterBenchmark, function (Query\Builder $query) {
                    $results = QueryFilterService::applyDateFilter($query, $this->period, $this->custom_date, 'benchmark_query.survey_answers_updated_at');
                    $results = QueryFilterService::applyBenchmarkFilterAnswers($results, $this->filters, 'benchmark_query');
                    return $results;
                })
                ->where('questionable_id', $questionable_id)
                ->groupBy('questionable_id', 'module_type', 'tenant_id');
            if ($tenant) {
                $results = $results->where('client_type_id', $tenant->client_type_id);
                if ($benchmarkSchoolFilter) {
                    $tenantIds = getTenantsByClientTypeAndOtherFields($benchmarkSchoolFilter, $tenant->client_type_id, $tenant->id);
                    $results = $results->whereIn('tenant_id', $tenantIds);
                }
            }
            $results = $results->get();
        });

        if (count($results->toArray())) {
            return round($results->sum('benchmark') / count($results->toArray()));
        }

        return 'N/A';
    }
}
