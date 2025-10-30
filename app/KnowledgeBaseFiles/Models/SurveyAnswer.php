<?php

namespace App\Models\Tenant;

use App\Contracts\SurveyBuilder\SurveyQuestion;
use App\Models\Tenant\Question as TenantQuestion;
use App\Models\{Question, Tenant};
use App\Scopes\{PeriodScope, PreviousPeriodScope};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasManyThrough, HasOne, MorphTo};
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Query;
use Illuminate\Support\{Collection, Str};
use Log;
use Stancl\Tenancy\Database\Concerns\TenantConnection;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

/**
 * App\Models\Tenant\SurveyAnswer
 *
 * @property int $id
 * @property int $survey_invite_id
 * @property string $questionable_type
 * @property int $questionable_id
 * @property string $question_type
 * @property bool $answering_multiple
 * @property int $parent_id
 * @property int $answer_order
 * @property \Illuminate\Support\Collection|string|int|null $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $questionable
 * @property-read \App\Models\Tenant\SurveyInvite $surveyInvite
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer benchmark()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer comments()
 * @method static \Database\Factories\Tenant\SurveyAnswerFactory factory(...$parameters)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer filterActiveQuestions()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer filterAnswers(array $filters)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer FilterBenchmarkAnswers(array $filters)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer filtering()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer hasAnswersBelongsToQuestion(\App\Contracts\SurveyBuilder\SurveyQuestion $question)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer latestSurvey()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer nps()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer periodFilter(string $filter)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer previousPeriodFilter(string $filter)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\SurveyAnswer query()
 * @mixin \Eloquent
 */
class SurveyAnswer extends Model
{
    use HasFactory, TenantConnection;

    protected $fillable = [
        'questionable_type',
        'questionable_id',
        'survey_invite_id',
        'question_type',
        'module_type',
        'value',
        'other_option_text',
        'answering_multiple',
        'parent_id',
        'answer_order',
        'comments',
        'is_read',
        'value_en'
    ];

    protected $casts = [
        'answers' => 'collection',
        'filtered_ans' => 'collection',
    ];

    protected static function hasBooted()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Str::uuid()->toString();
            }
        });
    }

    public function scopePeriodFilter(Builder $query, string $filter,string $custom_date=null): Builder
    {
        return (new PeriodScope())
            ->column("{$this->getTable()}.updated_at")
            ->apply($query, $filter,$custom_date);
    }

    public function scopeSequenceFilter(Builder $query, $cycleId, $join = false): Builder
    {
        if ($join) {
			$query = $query->join('survey_invites', 'survey_invites.id', '=', 'survey_answers.survey_invite_id');
        }
        return $query->where('survey_invites.survey_cycle_id',$cycleId);
    }
	
	public function scopeSequenceFilterForRatingDistribution(Builder $query, $cycleId, $join = false): Builder
    {
        if ($join) {
			$query = $query->join('survey_invites as si', 'si.id', '=', 'survey_answers.survey_invite_id');
        }
        return $query->where('si.survey_cycle_id',$cycleId);
    }
    
    public function scopeApplySurveyProgressFilter(Builder $query, $join = true): Builder
    {
        if (!$join) {
        $query = $query->join('survey_invites', 'survey_invites.id', '=', 'survey_answers.survey_invite_id');
        }
        return $query->whereIn('survey_invites.status',[SurveyInvite::ANSWERED,SurveyInvite::SEND]);
    }

    public function scopePreviousPeriodFilter(Builder $query, string $filter,string $custom_date=null): Builder
    {
        return (new PreviousPeriodScope())
        ->column("{$this->getTable()}.updated_at")
        ->apply($query, $filter,$custom_date);
    }

    public function scopeFilterAnswers(Builder $query, array $filters, array $custom_nps_filters=[]): Builder
    {
        return self::filterAnswers($query, $filters,$custom_nps_filters);
    }

    public function scopeFilterBenchmarkAnswers(Builder $query, array $filters, string $database): Builder
    {
        return self::filterBenchmarkAnswers($query, $filters, $database);
    }

    public function scopeHasAnswersBelongsToQuestion(Builder $query, SurveyQuestion $question): Builder
    {
        return $query->whereMorphedTo('questionable', $question);
    }

    public function scopeLatestSurvey(Builder $query): Builder
    {
        return $query->whereExists(function (Query\Builder $query) {

            return $query
                ->selectRaw(1)
                ->from('surveys')
                ->whereNotNull('surveys.question_id')
                ->where('survey_answers.questionable_type', Question::class)
                ->whereColumn('survey_answers.questionable_id', 'surveys.question_id')
                ->latest();
        });
    }

    public function scopeComments(Builder $query): Builder
    {
        return $query
            ->whereNotNull('survey_answers.value')
            ->whereRaw('survey_answers.value <> ""')
            ->where('survey_answers.question_type', Question::COMMENT);
    }

    public function scopeContainComments(Builder $query): Builder
    {
        return $query
            ->whereNotNull('survey_answers.value')
            ->whereRaw('survey_answers.value <> ""')
            ->where(function (Builder $q) {
               return $q->where('survey_answers.question_type', Question::COMMENT)
                ->orWhere('survey_answers.comments','<>','');
            })->orWhereExists(function($query){
                $query->select('survey_invite_id')
                    ->from('comments')
                    ->where(function($query){
                        $query->whereNull('survey_answers.value')
                        ->orwhere('survey_answers.value','');
                    })
                    ->where(function($query){
                        $query->whereNull('survey_answers.comments')
                        ->orwhere('survey_answers.comments','');
                    })
                    ->whereColumn('survey_answers.survey_invite_id', 'comments.survey_invite_id');
           });
    }

    public function scopeActiveModules(Builder $query)
    {
            return $query
                ->whereNotNull('survey_answers.module_type')
                ->whereIn('survey_answers.module_type', array_keys(getPermissionForModule('_view_comments_replies')));

    }

    public function scopeNps(Builder $query): Builder
    {
        return $query
            ->whereNotNull('value')
            ->where('survey_answers.question_type', Question::NPS);
    }

    public function scopeFiltering(Builder $query): Builder
    {
        return $query
            ->whereNotNull('value')
            ->where('survey_answers.question_type', Question::FILTERING);
    }
    public function scopeMultiple(Builder $query): Builder
    {
        return $query
            ->whereNotNull('value')
            ->where('survey_answers.question_type', TenantQuestion::MULTIPLE_CHOICE);
    }
    public function scopeMultipleOrFiltering(Builder $query): Builder
    {
        return $query
            ->whereNotNull('value')
            ->where(function ($q){
                $q->where('survey_answers.question_type',TenantQuestion::MULTIPLE_CHOICE)
                    ->orWhere('survey_answers.question_type',TenantQuestion::FILTERING);
            });
    }

    public function scopeBenchmark(Builder $query): Builder
    {
        return $query
            ->whereNotNull('value')
            ->where('survey_answers.question_type', Question::BENCHMARK);
    }

    public function scopeModule(Builder $query,$type): Builder
    {
        return $query->where('survey_answers.module_type', $type);
    }

    public function scopeNotEmptyChoice(Builder $query): Builder
    {
        return $query->where('value', '!=', '[]');
    }

    public function scopeFilterActiveQuestions(Builder $query): Builder
    {
        return  $query->where(function ($query) {
            $query->whereExists(function (Query\Builder $query) {
            return $query
                ->selectRaw(1)
                ->from('question_survey')
                // ->whereColumn('survey_answers.questionable_id', 'question_survey.custom_question_id')
                // ->orWhereColumn('survey_answers.questionable_id', 'question_survey.question_id')
                ->WhereColumn('survey_answers.module_type', 'question_survey.module_type')
                ->where(function ($query) {
                    $query->where(function ($query)  {
                        $query->where("survey_answers.questionable_type", '=', Question::class)
                            ->whereColumn("survey_answers.questionable_id", 'question_survey.question_id')
                            ->whereNull('question_survey.custom_question_id');
                    })
                        ->orWhere(function ($query)  {
                            $query->where("survey_answers.questionable_type", '=', Tenant\Question::class)
                                ->whereColumn("survey_answers.questionable_id", 'question_survey.custom_question_id');
                        });
                })
                ->whereExists(function (Query\Builder $query) {
                    return $query
                        ->selectRaw(1)
                        ->from('surveys')
                        ->whereColumn('question_survey.survey_id', 'surveys.id');
//                        ->where('surveys.status', Survey::ACTIVE);
                });
        })
            ->orWhereExists(function ($query) {
                $query
                    ->selectRaw(1)
                    ->fromCentral('questions')
                    ->where('system_default', '=', true)
                    ->where('active', '=', true)
                    ->whereNull('deleted_at')
                    ->where('survey_answers.questionable_type', Question::class)
                    ->whereColumn('survey_answers.questionable_id', 'questions.id');
                });
            });
            }

    public function surveyInvite(): BelongsTo
    {
        return $this->belongsTo(SurveyInvite::class)->withTrashed();
    }

    public function people()
    {
        return $this->hasManyThrough(People::class, SurveyInvite::class);
    }
    public function student()
    {
        return $this->hasManyThrough(Student::class, SurveyInvite::class);
    }

    public function withTrashedSurveyInvite(): BelongsTo
    {
        return $this->belongsTo(SurveyInvite::class,'survey_invite_id')->withTrashed();
    }


    public function employee()
    {
        return $this->hasManyThrough(Employee::class, SurveyInvite::class);
    }
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'tag_survey_answer');
    }

    public function surveyAnswerLatestComment(): HasOne
    {
        return $this->hasOne(Comment::class)->latestOfMany();
    }

    public function surveyAnswerComments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('id','desc');
    }

    public function surveyAnswerForwards(): HasMany
    {
        return $this->hasMany(CommentForward::class)->orderBy('id','desc');
    }

    public function questionable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function getValueAttribute(): string|int|Collection|null
    {
        $value = data_get($this->attributes, 'value');

        if (($this->question_type === Question::FILTERING || $this->question_type === Question::MULTIPLE_CHOICE) && str_starts_with($value, '[') && str_ends_with($value, ']')) {
            return collect(json_decode($value, true));
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $value;
    }

    public function setValueAttribute(mixed $value)
    {
        if ($this->question_type === Question::FILTERING && (is_object($value) || is_array($value))) {
            $value = json_encode($value);
        }

        $this->attributes['value'] = $value;

        return $this;
    }

    /**
     * @param Query\Builder|Builder $query
     * @param array $filters
     * @return Query\Builder|Builder
     */
    public static function filterBenchmarkAnswers($query, array $filters, string $database)
    {

        collect($filters)->each(function (array $filters) use ($query, $database) {
            foreach ($filters as $id => $answers) {
                $query->whereExists(function (Query\Builder $query) use ($id, $answers, $database) {
                    return $query
                        ->selectRaw(1)
                        ->from("{$database}.survey_answers as sub")
                        ->whereColumn('survey_answers.survey_invite_id', 'sub.survey_invite_id')
                        ->where('sub.questionable_type', Question::class)
                        ->where('sub.questionable_id', $id)
                        ->where('sub.question_type', Question::FILTERING)
                        ->orwhere('sub.question_type', TenantQuestion::MULTIPLE_CHOICE)
                        ->whereNotNull('sub.value')
                        ->where(function (Query\Builder $query) use ($answers) {
                            $answer = array_shift($answers);
                            $query->where('sub.value', 'like', "%\"{$answer}\"%");

                            foreach ($answers as $answer) {
                                $query->orWhere('sub.value', 'like', "%\"{$answer}\"%");
                            }
                        });
                });
            }
        });
    }

    /**
     * @param Query\Builder|Builder $query
     * @param array $filters
     * @return Query\Builder|Builder
     */
    public static function filterAnswers($query, array $filters, array $custom_nps_filters)
    {
        if(count($custom_nps_filters)){

            $query->whereExists(function ($query) use ($custom_nps_filters) {
                $filtersToString = collect($custom_nps_filters)->toStringImploded();
                return $query
                    ->selectRaw(<<<SQL
                            CASE
                                WHEN survey_answers.value >= 9 THEN 'promoter'
                                WHEN survey_answers.value BETWEEN 7 AND 8 THEN 'passive'
                                WHEN survey_answers.value <= 6 THEN 'detractor'
                                ELSE 'unknown'
                            END as type
                        SQL)
                    ->from("survey_answers as sub")
                    ->whereColumn('survey_answers.survey_invite_id', 'sub.survey_invite_id')
                    ->where('sub.questionable_type', Question::class)
                    ->where('sub.question_type', Question::NPS)
                    ->whereNotNull('sub.value')
                    ->havingRaw("type in ({$filtersToString})");
            });

        }
        collect($filters)->each(function (array $filters, string $classModel) use ($query) {

            foreach ($filters as $id => $answers) {
                $query->whereExists(function (Query\Builder $query) use ($classModel, $id, $answers) {
                    return $query
                        ->selectRaw(1)
                        ->from('survey_answers as sub')
                        ->whereColumn('survey_answers.survey_invite_id', 'sub.survey_invite_id')
                        ->where('sub.questionable_type', $classModel)
                        ->where('sub.questionable_id', $id)
                        ->whereNotNull('sub.value')
                        ->whereRaw("
                            CASE 
                                WHEN sub.parent_id IS NOT NULL AND survey_answers.parent_id IS NOT NULL AND sub.answer_order = survey_answers.answer_order 
                                THEN 1
                                WHEN survey_answers.parent_id IS NULL AND sub.parent_id IS NULL AND survey_answers.answering_multiple = 1 
                                THEN 1
                                WHEN survey_answers.parent_id IS NULL AND survey_answers.answering_multiple = 0 
                                THEN 1 
                                ELSE 0 
                            END = 1
                        ")
                        ->whereIn('sub.question_type', [Question::FILTERING, TenantQuestion::MULTIPLE_CHOICE])
                        ->where(function (Query\Builder $query) use ($answers) {
                            $answer = array_shift($answers);
                            if(is_array($answer)){
                                $answer = reset($answer);
                                if(is_array(($answer))){
                                    $answer = reset($answer);
                                }
                            }
                            $query->where('sub.value', 'like', "%\"{$answer}\"%");
                            foreach ($answers as $answer_value) {
                                if(is_array($answer_value)){
                                 $answer_value = reset($answer_value);
                                 if(is_array($answer_value)){
                                    $answer_value = reset($answer_value);
                                 }
                                }
                                $query->orWhere('sub.value', 'like', "%\"{$answer_value}\"%");
                            }
                        });
                });
            }
        });

        return $query;
    }

    public function isFiltering(): bool
    {
        return $this->question_type == Question::FILTERING;
    }

    public function isMultipleChoice(): bool
    {
        return $this->question_type == TenantQuestion::MULTIPLE_CHOICE;
    }
    public function isRankOrder(): bool
    {
        return $this->question_type == TenantQuestion::RANK_ORDER;
    }

    public function isRatingGrid(): bool
    {
        return $this->question_type == TenantQuestion::RATING_GRID;
    }


    public function SurveyInviteComments(): HasMany
    {
        return $this->hasMany(Comment::class,'survey_invite_id','survey_invite_id')
        ->orderBy('id','desc');
    }

    public function SurveyInviteForwards(): HasMany
    {
        return $this->hasMany(CommentForward::class,'survey_invite_id','survey_invite_id')
        ->orderBy('id','desc');
    } 

    public function readStatus()
    {
        return $this->belongsToMany(User::class, 'survey_answer_read_status')
            ->withPivot('is_read')
            ->withTimestamps();
    }

    public function isReadByUser(?User $user = null): bool
    {
        if (!$user) {
            $user = Auth::user();
        }
        return $this->readStatus()->where('user_id', $user->id)->wherePivot('is_read', true)->exists();
    }

    public function markAsRead(?User $user = null): void
    {
        if (!$user) {
            $user = Auth::user();
        }
        $this->readStatus()->syncWithoutDetaching([
            $user->id => ['is_read' => true]
        ]);
    }

    public function markAsUnread(?User $user = null): void
    {
        if (!$user) {
            $user = Auth::user();
        }
        $this->readStatus()->syncWithoutDetaching([
            $user->id => ['is_read' => false]
        ]);
    }
}


