<?php

namespace App\Reports;

use App\Models\Tenant;
use App\Models\Tenant\Question;
use App\Models\Tenant\SurveyAnswer;
use Illuminate\Database\Eloquent\Builder;

class MultipleChoiceAnswersReport
{
    public function __construct(
        private array $filtersAnswers,
        private string $period,
        private string $custom_date = '',
        private bool $activeSurvey,
        private $filtersTo = [],
        private string $module_type = 'all',
        private $getPermissionForModule = []
    ) {
        if(!count($this->getPermissionForModule)){
            $this->getPermissionForModule = getPermissionForModule('_view_dashboard');
        }
    }

    public function run(): Builder
    {
        $multiple_choice_answers =  SurveyAnswer::query()
            ->selectRaw(<<<SQL
                MIN(id) as id,
                questionable_id,
                module_type,
                CONCAT( GROUP_CONCAT(survey_invite_id)) as invites,
                questionable_type,
                CONCAT('[', GROUP_CONCAT(value), ']') as answers,
                COUNT(value) as answers_count
            SQL);

            $userPermission = getPermissionForModule('_view_dashboard');
            if($this->module_type == Tenant::ALL){
                $multiple_choice_answers = $multiple_choice_answers->whereIn('survey_answers.module_type',array_keys($this->getPermissionForModule));
            }
            elseif($this->module_type == Tenant::ALLPULSE)
            {
                $multiple_choice_answers = $multiple_choice_answers->whereIn('survey_answers.module_type',array_intersect(array_keys($this->getPermissionForModule), [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE]));
            }
            else {
                $multiple_choice_answers = $multiple_choice_answers->module($this->module_type);
            }

            $multiple_choice_answers  = $multiple_choice_answers->filterAnswers($this->filtersAnswers)
            ->periodFilter($this->period,$this->custom_date)
            ->when($this->activeSurvey, fn (Builder $query) => $query->filterActiveQuestions())
            ->notEmptyChoice()
            ->where(function ($query) {
                $query->whereMorphRelation(
                    relation: 'questionable',
                    types: Tenant\Question::class,
                    column: 'type',
                    operator: '=',
                    value: Tenant\Question::MULTIPLE_CHOICE
                )
                ->orWhereMorphRelation(
                    relation: 'questionable',
                    types: Question::class,
                    column: 'type',
                    operator: '=',
                    value: Question::FILTERING
                )
                ->orWhere(fn(Builder $query) => $query->filtering());
            })
            ->groupBy('questionable_id', 'questionable_type','module_type');
        return $multiple_choice_answers;
    }
}
