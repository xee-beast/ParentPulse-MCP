<?php

namespace App\Models;

use App\Contracts\SurveyBuilder\SurveyQuestion;
use App\Models\Tenant\{PreviewSurveyInvite, QuestionFilter, QuestionHistory, Survey, SurveyAnswer, SurveyCycle, SurveyInvite};
use App\Scopes\PeriodScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany, MorphMany};
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Query;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * App\Models\Question
 *
 * @property int $id
 * @property int|null $question_category_id
 * @property string $name
 * @property string $type
 * @property string|null $quick_start
 * @property string|null $position
 * @property bool $active
 * @property bool $system_default
 * @property bool $allow_multiple_answers
 * @property bool $allow_not_applicable
 * @property bool $parent_answer_type_required
 * @property bool $parent_answer_type_allow
 * @property bool $allow_dynamic_question
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\QuestionAnswer[] $answers
 * @property-read \App\Models\QuestionCategory|null $category
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Tenant\QuestionFilter[] $filters
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion active()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion annualQuickStart()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion benchmark()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion comment()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion default()
 * @method static \Database\Factories\QuestionFactory factory(...$parameters)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion filtering()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion nps()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion onBottom()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion onTop()
 * @method static \Illuminate\Database\Query\Builder|\App\Models\CentralQuestion onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion pivotOrdered()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion quarterlyQuickStart()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion search(?string $search)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion unselected(\App\Models\Tenant\Survey $survey)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\CentralQuestion withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion withoutDefault()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CentralQuestion withoutQuickStart()
 * @method static \Illuminate\Database\Query\Builder|\App\Models\CentralQuestion withoutTrashed()
 * @mixin \Eloquent
 */
class CentralQuestion extends Model implements SurveyQuestion
{
    use HasFactory;
    use CentralConnection;
    use SoftDeletes;

    public const ANNUAL    = 'annual';
    public const QUARTERLY = 'quarterly';

    public const NPS       = 'nps';
    public const BENCHMARK = 'benchmark';
    public const BENCHMARKED = 'benchmarked';
    public const FILTERING = 'filtering';
    public const COMMENT   = 'comment';
    const MULTIPLE_CHOICE = 'multiple_choice';
    const RANK_ORDER = 'rank_order';
    const RATING_GRID = 'rating_grid';

    public const TYPES     = [
        self::NPS       => 'NPS',
        self::BENCHMARK => 'Benchmark',
        self::FILTERING => 'Demographic',
        self::COMMENT   => 'Comment',
        self::RANK_ORDER => 'Rank Order',
        self::RATING_GRID => 'Rating Grid',
    ];

    public const TYPES_WITHOUT_RANK_ORDER     = [
        self::NPS       => 'NPS',
        self::BENCHMARK => 'Benchmark',
        self::FILTERING => 'Demographic',
        self::COMMENT   => 'Comment',
    ];

    public const TOP       = 'top';
    public const BOTTOM    = 'bottom';
    public const POSITIONS = [self::TOP, self::BOTTOM];

    public const  PARENT = 'parent';
    public const  EMPLOYEE = 'employee';
    public const  STUDENT = 'student';

     const NPS_ID        = 1;
     const BENCHMARK_ID  = 2;
     const FILTERING_ID  = 3;
     const COMMENT_ID    = 4;
     const MULTIPLE_CHOICE_ID = 5;
     const RANK_ORDER_ID = 6;
     const RATING_GRID_ID = 7;

     const Questionable_TYPE = 1;
    protected $fillable = [
        'question_category_id',
        'name',
        'type',
        'module_type',
        'quick_start',
        'position',
        'active',
        'system_default',
        'allow_multiple_answers',
        'allow_not_applicable',
        'allow_other_option',
        'survey_builder_set_identifier',
        'editable',
        'quick_start_module',
		'parent_answer_type_required',
		'parent_answer_type_allow',
		'allow_dynamic_question',
		'allow_to_survey_cycle_ids',
        'school_type',
		'client_type_id',
        'client_type_fields',
    ];

    protected $casts = [
        'active'                 => 'boolean',
        'system_default'         => 'boolean',
        'allow_multiple_answers' => 'boolean',
        'lng' => 'array',
    ];

	protected static function booted()
	{
		static::addGlobalScope('client', function (Builder $builder) {
			if (tenant()) {
				$builder->where('questions.client_type_id', tenant()->client_type_id);
			}
		});
	}

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $query->when($search, function (Builder $query, string $search) {
            $search = "%{$search}%";

            return $query
                ->where('questions.name', 'like', $search)
                ->orWhere('questions.type', 'like', $search)
                ->orWhere('questions.quick_start', 'like', $search)
                ->orWhere('questions.position', 'like', $search)
                ->orWhereHas('category', function (Builder $query) use ($search) {
                    $query->where('question_categories.name', 'like', $search);
                });
        });
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('system_default', true);
    }

    public function scopeWithoutDefault(Builder $query): Builder
    {
        return $query->where('system_default', false);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeFiltering(Builder $query): Builder
    {
        return $query->where('questions.type', self::FILTERING);
    }

    public function scopeNps(Builder $query): Builder
    {
        return $query
            ->where('questions.type', self::NPS)
            ->where('questions.position', self::TOP)
            ->where('questions.system_default', true);
    }

    public function scopeBenchmark(Builder $query): Builder
    {
        return $query->where('questions.type', self::BENCHMARK);
    }

    public function scopeComment(Builder $query): Builder
    {
        return $query->where('questions.type', self::COMMENT);
    }

    public function scopeAnnualQuickStart(Builder $query): Builder
    {
        return $query->where('quick_start', self::ANNUAL);
    }

    public function scopeQuarterlyQuickStart(Builder $query): Builder
    {
        return $query->where('quick_start', self::QUARTERLY);
    }

    public function scopeWithoutQuickStart(Builder $query): Builder
    {
        return $query->whereNull('quick_start');
    }

    public function scopeModule(Builder $query,$type,$operator = '='): Builder
    {

        if($operator=="!="){
            return $query->whereJsonDoesntContain('module_type',$type)->OrwhereNull('module_type');

        }
        return $query->whereJsonContains('module_type',$type)->OrwhereNull('module_type');
    }

    public function scopeQuickStartModule(Builder $query,$type,$operator = '='): Builder
    {

        if($operator=="!="){
            return $query->whereJsonDoesntContain('quick_start_module',$type)->OrwhereNull('quick_start_module');

        }
        return $query->whereJsonContains('quick_start_module',$type)->OrwhereNull('quick_start_module');
    }

    public function scopePivotOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('SIGN(question_survey.order) DESC, ABS(question_survey.order)');
    }

    public function scopeOnTop(Builder $query): Builder
    {
        return $query->where('position', self::TOP);
    }

    public function scopeOnBottom(Builder $query): Builder
    {
        return $query->where('position', self::BOTTOM);
    }

    public function scopeUnselected(Builder $query, Survey $survey, $surveyCycleId = null): Builder
    {
        return $query->whereNotExists(function (Query\Builder $query) use ($survey, $surveyCycleId) {
            return $query
                ->select(DB::raw(1))
                ->from(config('database.connections.tenant.database') . '.question_survey')
                ->where('survey_id', $survey->id)
				->where('question_survey.survey_cycle_id', $surveyCycleId)
				->whereColumn('question_id', 'questions.id');
        });
    }

    public function histories(): MorphMany
    {
        return $this->morphMany(QuestionHistory::class, 'questionable');
    }

    public function surveytemplates()
    {
        return $this->belongsToMany(SurveyTemplate::class,'question_survey_template');
    }

    public function lastHistory($survey,$type=null): MorphMany
    {
        return $this->morphMany(QuestionHistory::class, 'questionable')
			->where('is_used', 1)
			->where('survey_id',$survey->id)
			->where('question_type',$type)
			->where('module_type',$survey->module_type)->orderBy('id', 'DESC');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(QuestionCategory::class, 'question_category_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuestionAnswer::class);
    }

    public function filters(): MorphMany
    {
        return $this->morphMany(QuestionFilter::class, 'questionable');
    }

    public function isMultipleChoice(): bool
    {
        return $this->type === self::MULTIPLE_CHOICE;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isFiltering(): bool
    {
        return $this->type === self::FILTERING;
    }

    public function isRating(): bool
    {
        return in_array($this->type, [self::NPS, self::BENCHMARK]);
    }

    public function isRatingGrid(): bool
    {
        return $this->type == self::RATING_GRID;
    }

    public function isNps(): bool
    {
        return $this->type === self::NPS;
    }

    public function isBenchmark(): bool
    {
        return $this->type === self::BENCHMARK;
    }

    public function isComment(): bool
    {
        return $this->type === self::COMMENT;
    }

    public function isDefault(): bool
    {
        return (bool) $this->system_default;
    }

    public function detachFromSurvey(Survey $survey, $surveyCycleId = null): int
    {
        return $surveyCycleId ? $survey->questions()
        ->wherePivot('survey_cycle_id', $surveyCycleId)
        ->wherePivot('question_id', $this->id)
        ->detach($this) : $survey->questions()
        ->detach($this) ;
    }

    public function updateRequiredOption(Survey $survey, bool $isRequired, $surveyCycleId): int
    {
        return $survey->questions()
        ->wherePivot('survey_cycle_id', $surveyCycleId)
        ->updateExistingPivot($this, ['is_required' => $isRequired]);
    }


    public function updateAllowComment(Survey $survey, bool $isAllowedComment, $surveyCycleId): int
    {
        return $survey->questions()
        		->wherePivot('survey_cycle_id', $surveyCycleId)
        ->updateExistingPivot($this, ['is_allow_comment' => $isAllowedComment]);
    }

    public function updateFilteredOption(Survey $survey, bool $isFiltered, $surveyCycleId): int
    {
        return $survey->questions()
        ->wherePivot('survey_cycle_id', $surveyCycleId)
        ->updateExistingPivot($this, ['is_filtered' => $isFiltered]);
    }

    public function updateRequireMultipleAnswersOption(Survey $survey, bool $requireMultipleAnswers, $surveyCycleId): int
    {
        return $survey->questions()
        ->wherePivot('survey_cycle_id', $surveyCycleId)
        ->updateExistingPivot($this, ['require_multiple_answers' => $requireMultipleAnswers]);
    }

    public function updateAllowNotApplicableOption(Survey $survey, bool $allowNotApplicable, $surveyCycleId): int
    {
        return $survey->questions()
            ->wherePivot('survey_cycle_id', $surveyCycleId)
            ->updateExistingPivot($this, ['allow_not_applicable' => $allowNotApplicable]);
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
        if($filters){
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
                $count = $count ? $count : count($pas['passForChildren']) ;
                if(count($pas['passForChildren']) > $count ){
                    $index = $key;
                    $count = count($pas['passForChildren']);
                }
                $questionChildPass[] = $pas['passForChildren'];
            }
        } else{
            $passed_for_all_children = true;
        }

        if($passedFilter && $passedForAllChildrenFilter) {
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

            if ($filters[0]?->condition == QuestionFilter::OR) { // for AND operator
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

    public function wasAnswered($moduleType =null): bool
    {
        $answers = SurveyAnswer::query()->hasAnswersBelongsToQuestion($this)
                                        ->when($moduleType,function ($query) use ($moduleType){
                                            $query->where('module_type',$moduleType);
                                        });
        return $answers->exists();
    }

    public function benchmarkValue(string $period = PeriodScope::DEFAULT_PERIOD, $custom_date=null,$module_type=null): int
    {
        $queryResult = SurveyAnswer::query()
            ->selectRaw('round(avg(value) * 10) as benchmark')
            ->where('questionable_id', $this->id)->where('questionable_type',CentralQuestion::class);
            if($module_type!=='all'){
                $queryResult=$queryResult->where('module_type',$module_type);
            }
            $queryResult=$queryResult->periodFilter($period,$custom_date)
            ->where('value', '!=', 'N/A')
            ->groupBy('questionable_id', 'questionable_type')
            ->first();

        return data_get($queryResult, 'benchmark', 0);
    }

    public function wasInSurvey(string $period = PeriodScope::DEFAULT_PERIOD,$custom_date=null): bool
    {
        $hadHistoryInPeriod = self::histories()
            ->periodFilter($period,$custom_date)
            ->exists();

        if($period==PeriodScope::Custom){

            if(isset($custom_date) && !empty($custom_date)){

                $dates = convertCustomDateRange($custom_date);
            }
        }

        if (!$hadHistoryInPeriod) {

            $beginPeriodDate = match ($period) {
                PeriodScope::LAST_365_DAYS     => now()->subDays(365),
                PeriodScope::LAST_THREE_MONTHS => now()->subMonths(3),
                PeriodScope::LAST_THIRTY_DAYS  => now()->subDays(30),
                PeriodScope::TODAY             => now()->startOfDay(),
                PeriodScope::ALL_TIME          => now()->addDays(2),
                PeriodScope::Custom          => now()->addDays(2),
            };

            $lastHistory = self::histories()
                ->where('created_at', '<', $beginPeriodDate)
                ->orderBy('created_at', 'DESC')
                ->limit(1);

            if ($lastHistory->exists()) {
                return $lastHistory->first()->action === QuestionHistory::ACTION_ADD;
            }
        }

        return $hadHistoryInPeriod;
    }

    public function everWasOnSurvey():bool
    {
        return self::histories()->exists();
    }

    public function scopeSchoolType(Builder $query): Builder
    {
        return isDefaultClientType(tenant()) 
            ? $query->whereJsonContains('school_type', (string) tenant()->school_type_affiliation) 
            : $query;
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(QuestionCategory::class, 'question_category_pivot');
    }

}
    