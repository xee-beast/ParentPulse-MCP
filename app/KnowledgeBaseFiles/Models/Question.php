<?php

namespace App\Models\Tenant;

use App\Contracts\SurveyBuilder\SurveyQuestion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\{Builder, Model, Relations\BelongsToMany, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Query;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Concerns\TenantConnection;

/**
 * App\Models\Question
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property bool $allow_multiple_answers
 * @property bool $allow_not_applicable
 * @property bool $parent_answer_type_required
 * @property bool $parent_answer_type_allow
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Tenant\QuestionAnswer[] $answers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Tenant\QuestionFilter[] $filters
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Tenant\Survey[] $surveys
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\Question benchmark()
 * @method static \Database\Factories\Tenant\QuestionFactory factory(...$parameters)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\Question filtering()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\Question multipleChoice()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\Question newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\Question newQuery()
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Tenant\Question onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\Question pivotOrdered()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\Question query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Tenant\Question unselected(\App\Models\Tenant\Survey $survey)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Tenant\Question withTrashed()
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Tenant\Question withoutTrashed()
 * @mixin \Eloquent
 */
class Question extends Model implements SurveyQuestion
{
    use HasFactory;
    use TenantConnection;
    use SoftDeletes;

    const BENCHMARK       = 'benchmark';
    const FILTERING       = 'filtering';
    const ANNUAL          = 'annual';
    const QUARTERLY       = 'quarterly';
    const MULTIPLE_CHOICE = 'multiple_choice';
    const COMMENT = 'comment';
    const RANK_ORDER = 'rank_order';
    const RATING_GRID = 'rating_grid';


    const TYPES = [
        self::BENCHMARK => 'Rating',
        self::FILTERING => 'Demographic',
        self::COMMENT  => 'Comment',
        self::RANK_ORDER  => 'Rank Order',
        self::RATING_GRID => 'Rating Grid',
        self::MULTIPLE_CHOICE => 'Multiple Choice',
    ];

    protected $fillable = ['name', 'type','lng', 'allow_filters' ,'allow_multiple_answers','allow_parent_filter',
		'allow_student_filter','allow_employee_filter' ,'allow_not_applicable','allow_other_option','module_type',
		'survey_builder_set_identifier','parent_answer_type_required','parent_answer_type_allow','randomize_options','label_start','label_end','generated_through_ai'];
    const BENCHMARK_ID       = 2;
    const FILTERING_ID       = 3;
    const MULTIPLE_CHOICE_ID = 5;
    const RANK_ORDER_ID =6;
    const RATING_GRID_ID = 7;
    const Questionable_TYPE  = 2;
    protected $casts = ['allow_multiple_answers' => 'bool','lng' => 'array'];

    public function scopeFiltering(Builder $query): Builder
    {
        return $query->where('questions.type', self::FILTERING);
    }

    public function scopeMultipleChoice(Builder $query): Builder
    {
        return $query->where('questions.type', self::MULTIPLE_CHOICE);
    }

    public function scopeRankOrder(Builder $query): Builder
    {
        return $query->where('questions.type', self::RANK_ORDER);
    }

    public function scopeRatingGrid(Builder $query): Builder
    {
        return $query->where('questions.type', self::RATING_GRID);
    }

    public function isComment()
    {
        return $this->type==self::COMMENT;
    }
    public function scopeBenchmark(Builder $query): Builder
    {
        return $query->where('questions.type', self::BENCHMARK);
    }
    public function scopeComment(Builder $query)
    {
        return $query->where('questions.type', self::COMMENT);
    }

    public function scopePivotOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('SIGN(question_survey.order) DESC, ABS(question_survey.order)');
    }

    public function scopeUnselected(Builder $query, Survey $survey, $surveyCycleId = null): Builder
    {
        return $query->whereNotExists(function (Query\Builder $query) use ($survey, $surveyCycleId) {
            return $query
                ->select(DB::raw(1))
                ->from('question_survey')
                ->where('survey_id', $survey->id)
				->where('question_survey.survey_cycle_id', $surveyCycleId)
                ->whereColumn('custom_question_id', 'questions.id');
        });
    }

    public function histories(): MorphMany
    {
        return $this->morphMany(QuestionHistory::class, 'questionable');
    }

    public function lastHistory($survey,$type=null): MorphMany
    {
        return $this->morphMany(QuestionHistory::class, 'questionable')
			->where('is_used', 1)
			->where('survey_id',$survey->id)
			->where('question_type',$type)
			->where('module_type',$survey->module_type)->orderBy('id', 'DESC');
    }

    public function answers(): MorphMany
    {
        return $this->morphMany(QuestionAnswer::class, 'questionable');
    }

    public function surveys(): BelongsToMany
    {
        return $this->belongsToMany(Survey::class, 'question_survey');
    }

    public function questionSurveys() 
    {
        return $this->hasMany(QuestionSurvey::class, 'custom_question_id');
    }

    public function filters(): MorphMany
    {
        return $this->morphMany(QuestionFilter::class, 'questionable');
    }

    public function detachFromSurvey(Survey $survey, $surveyCycleId = null): int
    {
        return $surveyCycleId ? $survey->customQuestions()
            ->wherePivot('survey_cycle_id', $surveyCycleId)
            ->wherePivot('custom_question_id', $this->id)
            ->detach($this) : $survey->customQuestions()->detach($this);
    }

    public function isBenchmark(): bool
    {
        return $this->type === self::BENCHMARK;
    }

    public function isFiltering(): bool
    {
        return $this->type === self::FILTERING;
    }

    public function isMultipleChoice(): bool
    {
        return $this->type === self::MULTIPLE_CHOICE;
    }

    public function isRating(): bool
    {
        return $this->type === self::BENCHMARK;
    }

    public function isRatingGrid(): bool
    {
        return $this->type === self::RATING_GRID;
    }

    public function isRankOrder(): bool
    {
        return $this->type === self::RANK_ORDER;
    }

    public function isDefault(): bool
    {
        return false;
    }

    public function isNps(): bool
    {
        return false;
    }

    public function updateRequiredOption(Survey $survey, bool $isRequired, $surveyCycleId): int
    {
        return $survey->customQuestions()        		
        ->wherePivot('survey_cycle_id', $surveyCycleId)
        ->updateExistingPivot($this, ['is_required' => $isRequired]);
    }


    public function updateAllowComment(Survey $survey, bool $isAllowedComment, $surveyCycleId): int
    {
        return $survey->customQuestions()	
        		->wherePivot('survey_cycle_id', $surveyCycleId)
        ->updateExistingPivot($this, ['is_allow_comment' => $isAllowedComment]);
    }
    
    public function updateFilteredOption(Survey $survey, bool $isFiltered, $surveyCycleId): int
    {
        return $survey->customQuestions()        		
        ->wherePivot('survey_cycle_id', $surveyCycleId)
        ->updateExistingPivot($this, ['is_filtered' => $isFiltered]);
    }
    
    public function showingOnSurvey(SurveyInvite | PreviewSurveyInvite $invite): array
    {
        $passedFilter = [];
        $passedForAllChildrenFilter = [];
        $passed = false;
        $passed_for_all_children = false;
        $questionChildPass = [];
        $matchResult = [];
        $count = $index = 0;
        if($invite->survey_cycle_id){
            $filters = $this->filters()->where('question_filters.survey_cycle_id', $invite->survey_cycle_id)->get();
        } else {
            $filters = $this->filters;
        }
        if ($filters) {
            foreach ($filters as $key => $filter) {
                $pas = $filter->passes($invite);
                $passedFilter[$key] = true;
                $passedForAllChildrenFilter[$key] = true;
                if (!$pas['passes']) {
                    $passedFilter[$key] = false;
                }
                if (!$pas['passes_for_all_children']) {
                    $passedForAllChildrenFilter[$key] = false;
                }
                $count = $count ? $count : count($pas['passForChildren']);
                if (count($pas['passForChildren']) > $count) {
                    $index = $key;
                    $count = count($pas['passForChildren']);
                }
                $questionChildPass[] = $pas['passForChildren'];
            }
        } else {
            $passed_for_all_children = true;
        }

        if ($passedFilter && $passedForAllChildrenFilter) {
            if ($filters[0]?->condition == QuestionFilter::AND) { // for AND operator
                if (!in_array(false, $passedForAllChildrenFilter)) {
                    $passed_for_all_children = true;
                }
                if (!in_array(false, $passedFilter)) {
                    $passed = true;
                }
                // Iterate through each key of the first sub-array to compare with others
                foreach ($questionChildPass[$index] as $key => $value) {
                    $allMatch = true; // Assume all elements match initially
                    // Iterate through each sub-array
                    foreach ($questionChildPass as $subArray) {
                        // If a sub-array has only one key and the value is not true
                        if (count($subArray) == 1) {
                            $onlyKey = key($subArray);
                            if ($subArray[$onlyKey] !== true) {
                                $allMatch = false;
                                break;
                            }
                        } else {
                            // Normal comparison
                            if (!isset($subArray[$key]) || $subArray[$key] !== true) {
                                $allMatch = false;
                                break; // No need to check further
                            }
                        }
                    }
                    $matchResult[$key] = $allMatch;
                }
            }

            if ( $filters[0]?->condition == QuestionFilter::OR) { // for AND operator
                if (in_array(true, $passedForAllChildrenFilter)) {
                    $passed_for_all_children = true;
                }
                if (in_array(true, $passedFilter)) {
                    $passed = true;
                }
                foreach ($questionChildPass[$index] as $key => $value) {
                    $allMatch = false; // Assume not all elements match initially
                    // Iterate through each sub-array
                    foreach ($questionChildPass as $subArray) {
                        // If a sub-array has only one key and the value is true
                        if (count($subArray) == 1) {
                            $onlyKey = key($subArray);
                            if ($subArray[$onlyKey] === true) {
                                $allMatch = true;
                                break; // Exit loop as we found a match
                            }
                        } else {
                            // Normal comparison
                            if (isset($subArray[$key]) && $subArray[$key] === true) {
                                $allMatch = true;
                                break; // Exit loop as we found a match
                            }
                        }
                    }
                    $matchResult[$key] = $allMatch;
                }
            }
        } else {
            $passed = true;
            $passed_for_all_children = true;
        }
        return [
            'passed' => $passed,
            'passed_for_all_children' => $passed_for_all_children,
            'passForChildren' => $matchResult,
        ];
    }

    public function wasAnswered($moduleType=null): bool
    {
        $answers = SurveyAnswer::query()->hasAnswersBelongsToQuestion($this)->when($moduleType,function ($query) use ($moduleType){
            $query->where('module_type',$moduleType);
        });
        return $answers->exists();
    }

    public function existsOnSurvey(): bool
    {

    }

    public function scopeModule(Builder $query,$type): Builder
    {
        return $query->whereJsonContains('module_type',$type)->OrwhereNull('module_type');
    }

    public function surveyAnswers()
    {
        return $this->morphMany(SurveyAnswer::class, 'questionable');
    }

    public static function getTypeLabel($type): string
    {
        return match ($type) {
            Question::MULTIPLE_CHOICE => 'Multiple Choice',
            Question::BENCHMARK => 'Rating',
            Question::COMMENT => 'Comment',
            Question::RANK_ORDER => 'Rank Order',
            Question::RATING_GRID => 'Rating Grid',
            default => 'Unknown Type',
        };
    }
}
