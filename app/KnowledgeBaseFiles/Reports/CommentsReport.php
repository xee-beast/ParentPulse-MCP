<?php

namespace App\Reports;

use App\Models\Question;
use App\Models\Tenant\Question as TenantQuestion;
use App\Models\Tenant\SurveyAnswer;
use App\Models\Tenant\SurveyInvite;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query;

class CommentsReport
{
    public static function query($filters=[], $excelReport=false, $surveyProgressFilter = [], $showUnreadOnly = false)
    {
       $surveyAnswer= SurveyAnswer::query()

            ->selectRaw(<<<SQL
                survey_answers.*,
                survey_invites.custom_email,
                survey_invites.lng as lng_main,
                    CONCAT(students.first_name, ' ', students.last_name)
                as student_name,
                 people.email as people_email,
                 employees.email as employee_email,
                    CONCAT(employees.firstname, ' ', employees.lastname)
                 as employee_name,
                survey_invites.id as invite_id,
                survey_invites.previous_nps_value,
                nps_answers.value as nps_value, 
                CASE
                    WHEN nps_answers.value >= 9 THEN 'promoter'
                    WHEN nps_answers.value BETWEEN 7 AND 8 THEN 'passive'
                    WHEN nps_answers.value <= 6 THEN 'detractor'
                    ELSE 'unknown'
                END as type,
                    CONCAT(people.first_name, ' ', people.last_name)
                 as people_name,
                 survey_invites.token,
                 CASE
                    WHEN survey_answers.value IS NULL or survey_answers.value = ''
                    AND survey_answers.comments IS NULL or survey_answers.comments = ''
                    AND EXISTS (
                        SELECT 1 FROM comments 
                        WHERE comments.survey_invite_id = survey_answers.survey_invite_id
                    )
                    THEN 'has_external_comments'
                    ELSE 'normal'
                END as comment_type,
                COALESCE(read_status.is_read, false) as is_read
            SQL)
            ->join('survey_invites', 'survey_invites.id', 'survey_answers.survey_invite_id')
            ->leftJoin('survey_answers as nps_answers', function (Query\JoinClause $join) {
                return $join
                    ->on('nps_answers.survey_invite_id', 'survey_answers.survey_invite_id')
                    ->where('nps_answers.questionable_type', Question::class)
                    ->where('nps_answers.question_type', Question::NPS)
                    ->whereNotNull('nps_answers.value');
            })
            ->leftJoin('students', 'students.id', 'survey_invites.student_id')
            ->leftJoin('people', function ($q) {
                $q->on('people.id', 'survey_invites.people_id');
            })
            ->leftJoin('employees', 'employees.id', 'survey_invites.employee_id')
            ->leftJoin('survey_answer_read_status as read_status', function ($join) {
                $join->on('survey_answers.id', '=', 'read_status.survey_answer_id')
                    ->where('read_status.user_id', '=', auth()->id());
            })
            ->when((!$excelReport),function($query) use($surveyProgressFilter,$showUnreadOnly){
                if ($surveyProgressFilter) {
                    $query = $query->whereIn('survey_invites.status',$surveyProgressFilter);
                }else
                {
                    $query = $query->where('survey_invites.status','answered'); 
                }
                $query = $query->activeModules()
                     
            ->when($showUnreadOnly, function($query) {
                $query->where(function($q) {
                    $q->whereHas('readStatus', function($subQuery) {
                        $subQuery->where('user_id', auth()->id())
                                ->where('is_read', false);
                    })
                    ->orWhereDoesntHave('readStatus', function($subQuery) {
                        $subQuery->where('user_id', auth()->id());
                    });
                });
            })->containComments();
                return $query;
           })->applySurveyProgressFilter();
        collect($filters)->each(function (array $filters, string $classModel) use ($surveyAnswer) {
            foreach ($filters as $id => $answers) {
                $surveyAnswer->whereExists(function (Query\Builder $query) use ($classModel, $id, $answers) {
                    return $query
                        ->selectRaw(1)
                        ->from('survey_answers as sub')
                        ->whereColumn('survey_answers.survey_invite_id', 'sub.survey_invite_id')
                        ->where('sub.questionable_type', $classModel)
                        ->where('sub.questionable_id', $id)
                        ->whereNotNull('sub.value')
                        ->whereIn('sub.question_type', [Question::FILTERING, TenantQuestion::MULTIPLE_CHOICE])
                        ->where(function (Query\Builder $query) use ($answers) {
                            $answer = array_shift($answers);
                            if (is_array($answer)) {
                                $answer = reset($answer);
                                if (is_array(($answer))) {
                                    $answer = reset($answer);
                                }
                            }
                            $query->where('sub.value', 'like', "%\"{$answer}\"%");

                            foreach ($answers as $answer_value) {
                                if (is_array($answer_value)) {
                                    $answer_value = reset($answer_value);
                                    if (is_array($answer_value)) {
                                        $answer_value = reset($answer_value);
                                    }
                                }
                                $query->orWhere('sub.value', 'like', "%\"{$answer_value}\"%");
                            }
                        });
                });
            }
        });
        return $surveyAnswer;
    }
}
