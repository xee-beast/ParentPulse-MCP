<?php

namespace App\Reports;

use App\Models\Question;
use App\Models\Tenant;
use App\Models\Tenant\Question as TenantQuestion;
use App\Services\QueryFilterService;
use DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;

class MultipleChoiceAnswersReportCentral
{
    public function __construct(
        private array $filtersAnswers,
        private string $period,
        private string $custom_date = '',
        private bool $activeSurvey,
        private $filtersTo = [],
        private $surveyProgressFilter = [],
        private string $module_type = 'all',
        private $getPermissionForModule = [],
        private $timeExecution=1800
    ) {
        if (!count($this->getPermissionForModule)) {
            $this->getPermissionForModule = getPermissionForModule('_view_dashboard');
        }
    }

    public function run($cacheReset = false)
    {
        $selectedModuleType = [];
        if ($this->module_type == Tenant::ALL) {
            $selectedModuleType = array_keys($this->getPermissionForModule);
        } elseif ($this->module_type == Tenant::ALLPULSE) {
            $selectedModuleType = array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]);
        } else {
            $selectedModuleType = [$this->module_type];
        }
        $cacheKey = $this->generateCacheKey();
    
        $tenant = tenant();
        tenancy()->central(function () use (&$results, $tenant, $selectedModuleType, $cacheKey, $cacheReset) {
            if ($cacheReset) {
                Cache::forget($cacheKey);
            }
            $results = Cache::remember($cacheKey, $this->timeExecution, function () use ($tenant, $selectedModuleType) {  
                $results = DB::table('central_survey_answers AS csa')
                        ->selectRaw('MIN(csa.survey_answer_id) AS id,
                        csa.questionable_id,
                        COALESCE(central_questions.name, custom_question.name) as question_name,
                        csa.module_type,
                        CONCAT(GROUP_CONCAT(csa.survey_invite_id)) AS invites,
                        questionable_type,
                        question_type,
                        CONCAT("[", GROUP_CONCAT(value), "]") AS answers,
                        CONCAT(
                        "[",
                        GROUP_CONCAT(
                            JSON_QUOTE(other_option_text)
                            SEPARATOR ","
                        ),
                        "]"
                        ) AS other_option_text,
                         custom_question.label_start,
                         custom_question.nickname as nickname,
                         custom_question.label_end,
                        COUNT(DISTINCT csa.survey_invite_id) AS answers_count
                    ')
                    ->leftJoin('questions AS central_questions', function ($join) {
                        $join->on('csa.questionable_id', '=', 'central_questions.id')
                            ->where('csa.questionable_type', '=', Question::Questionable_TYPE);
                    })
                    ->leftJoin(DB::raw('`' . $tenant->tenancy_db_name . '`.questions custom_question'), function ($join) {
                        $join->on('csa.questionable_id', '=', 'custom_question.id')
                            ->where('csa.questionable_type', '=', TenantQuestion::Questionable_TYPE);
                    })
                    ->join('central_survey_invites AS csid', function ($join) use ($tenant) {
                        $join->on('csa.survey_invite_id', '=', 'csid.survey_invite_id')
                            ->where('csid.tenant_id', '=', $tenant->id);
                    })
                    ->whereIn('csa.module_type', $selectedModuleType)
                    ->whereNotNull('csa.survey_answers_updated_at')
                    ->where('csa.tenant_id', $tenant->id)
                    ->where('value', '!=', '[]')
                    ->where(function ($query) use ($tenant) {
                        $query->whereExists(function ($subquery) use ($tenant) {
                            $subquery->select(DB::raw(1))
                                ->from(DB::raw('`' . $tenant->tenancy_db_name . '`.question_survey as question_survey'))
                                ->join(DB::raw('`' . $tenant->tenancy_db_name . '`.surveys as surveys'), 
                                    'question_survey.survey_id', '=', 'surveys.id')
                                ->whereColumn('csa.module_type', 'question_survey.module_type')
                                ->where(function ($q) {
                                    $q->where(function ($inner) {
                                        $inner->where('csa.questionable_type', Question::Questionable_TYPE)
                                            ->whereColumn('csa.questionable_id', 'question_survey.question_id')
                                            ->whereNull('question_survey.custom_question_id');
                                    })->orWhere(function ($inner) {
                                        $inner->where('csa.questionable_type', TenantQuestion::Questionable_TYPE)
                                            ->whereColumn('csa.questionable_id', 'question_survey.custom_question_id');
                                    });
                                });
                        })->orWhereExists(function ($subquery) {
                            $subquery->select(DB::raw(1))
                                ->from(DB::raw('`parent_pulse_local`.questions'))
                                ->where('system_default', 1)
                                ->where('active', 1)
                                ->whereNull('deleted_at')
                                ->where('csa.questionable_type', Question::Questionable_TYPE)
                                ->whereColumn('csa.questionable_id', 'questions.id');
                        });
                    })
                    ->where(function ($query) use ($tenant) {
                        $query->where(function ($q) use ($tenant) {
                            $q->where('csa.questionable_type', TenantQuestion::Questionable_TYPE)
                                ->whereExists(function ($subquery) use ($tenant) {
                                    $subquery->select(DB::raw(1))
                                        ->from(DB::raw('`' . $tenant->tenancy_db_name . '`.`questions` as questions'))
                                        ->whereColumn('csa.questionable_id', 'questions.id')
                                        ->whereIn('type', [TenantQuestion::MULTIPLE_CHOICE, TenantQuestion::FILTERING, TenantQuestion::RANK_ORDER, TenantQuestion::RATING_GRID])
                                        ->whereNull('questions.deleted_at');
                                });
                        })->orWhere(function ($q) {
                            $q->whereNotNull('value')
                                ->whereIn('csa.question_type', [Question::FILTERING_ID, Question::MULTIPLE_CHOICE_ID, TenantQuestion::RANK_ORDER, TenantQuestion::RATING_GRID]);
                        });
                    });

                if (isSequence($this->period)) {
                    $results = QueryFilterService::applySequence($results, $this->period, 'csa', '=', $tenant);
                } else {
                    $results = QueryFilterService::applyDateFilter($results, $this->period, $this->custom_date);
                }

                $results = QueryFilterService::activeSurveyExsits($results, $this->activeSurvey, $tenant, 'csa');
                $results = QueryFilterService::applyFilterAnswers($results, $this->filtersAnswers, $this->filtersTo, tenant: $tenant);
                $results = QueryFilterService::npsFilter($results, $this->filtersTo);
                $results = QueryFilterService::surveyProgressFilter($this->period, $results, $this->surveyProgressFilter, $tenant, 'csa');
                
                $results = $results->groupBy('questionable_id', 'questionable_type', 'module_type', 'csa.tenant_id')->get();
                return $results;
            });
        });

        return $results;
    }


    /**
     * Generate a unique cache key based on all query parameters
     *
     * @return string
     */
    public function generateCacheKey(): string
    {
        $keyParts = [
            'multiple_choice_answers',
            'tenant_' . tenant()->id,
            'module_type_' . implode('-', (array)$this->module_type),
            'period_' . $this->period,
            'custom_date_' . ($this->custom_date),
            'active_survey_' . ($this->activeSurvey ? '1' : '0'),
            'filters_answers_' . md5(json_encode($this->filtersAnswers)),
            'filters_to_' . md5(json_encode($this->filtersTo)),
            'survey_progress_' . md5(json_encode($this->surveyProgressFilter ?? 'none')),
        ];

        // Add selected module types
        $selectedModuleType = [];
        if ($this->module_type == Tenant::ALL) {
            $selectedModuleType = array_keys($this->getPermissionForModule);
        } elseif ($this->module_type == Tenant::ALLPULSE) {
            $selectedModuleType = array_intersect(
                array_keys($this->getPermissionForModule), 
                [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]
            );
        } else {
            $selectedModuleType = [$this->module_type];
        }
        $keyParts[] = 'selected_modules_' . implode('-', $selectedModuleType);

        // Add a version number to easily invalidate all caches if needed
        $keyParts[] = 'v1';

        return implode(':', $keyParts);
    }
}
