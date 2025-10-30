<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use App\Exports\SurveyAnswersExport;
use App\Models\Tenant;
use App\Models\Tenant\SurveyAnswer;
use Barryvdh\DomPDF\Facade\Pdf;
use Excel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Reports\{CommentsReport, LikertAnswersReport, MultipleChoiceAnswersReport, NpsBenchmarkReport, NpsReport};
use App\Scopes\PeriodScope;
use Illuminate\Support\{Collection, Facades\Cache, Str};
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Models\Tenant\Question as TenantQuestion;
use App\Models\Question as Question;
use Illuminate\Validation\Rule;

/**
 * @property-read Collection $multipleChoiceAnswers
 * @property-read Collection $benchmarkAnswers
 * @property-read Collection $currentPeriod
 * @property-read Collection $previousPeriod
 * @property-read float|int|null $schoolsBenchmark
 */
class Export extends Component
{
	public string $period = PeriodScope::DEFAULT_PERIOD;
	public string $custom_date = '';

	public bool $scopeActiveSurvey = true;

	public bool $applyBenchmarkFilter = false;
	public bool $onlyCustomFilter = false;
	public bool $is_custom_nps_filter = false;

	public array $filters = [];
	public array $custom_nps_filter = [];
	public array $surveyProgressFilter = [];
	public string  $module_type='all';
	protected $listeners = [
		'filters::surveys'         => 'setFilterActiveSurvey',
		'filters::period'          => 'setPeriod',
		'filters::apply'           => 'setFilters',
		'export-dashboard::delete' => 'deletePdf',
		'dashboard-page::change_module_type' => 'setModuleType',
		'exportFiles' => 'exportFiles',
		'filters::apply-benchmark' => 'setApplyBenchmarkFilters',
		'dashboard::onlyCustomFilter' => 'setOnlyCustomFilter'

	];
	public $showDownloadModal = false;

	public $exportType = 'pdf';
	public $getPermissionForModule = [];
	public $userPermisisons = [];
	public $activeTenantModules = [];

	public function setModuleType(string $type): void
	{
		$this->module_type = $type;
	}
	public function setOnlyCustomFilter(bool $apply): void
	{
		$this->onlyCustomFilter = $apply;
	}

	public function setApplyBenchmarkFilters(bool $apply)
	{
		$this->applyBenchmarkFilter = $apply;
	}

	public function setFilterActiveSurvey(bool $active)
	{
		$this->scopeActiveSurvey = $active;
	}

	public function setPeriod(string $period,string $custom_date)
	{
		$this->period = $period;
		$this->custom_date = $custom_date;
	}

	public function setFilters(array $filters, $setResultEmpty = false, $toRemove = [])
	{
		$this->filters = $filters;
		foreach ($toRemove as $modelQuestion => $questions) {
			foreach ($questions as $questionableId => $value) {
				unset($this->filters[$modelQuestion][$questionableId]);
			}
		}

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


	public function exportPdf()
	{

		$period = isSequence($this->period)? getSequenceTitle($this->period) :PeriodScope::PERIOD_FILTERS[$this->period] ;
		$showNps = false;
		if(array_intersect(explode(" ",$this->module_type),[Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE, Tenant::ALL, Tenant::ALLPULSE]) && count(getActiveModules(true))>0){
			$showNps = true;
		}

		$date = now()->withTenantTimezone()->format('Y-m-d-H-i');
		$filename = "dashboard-{$this->period}-{$date}.pdf";
		$pdf =  Pdf::loadView('exports.dasboard',[
			'period'                => $period,
			'customDate'            => $this->custom_date,
			'filters'               => $this->filters,
			'custom_nps_filter'      => $this->custom_nps_filter,
			'benchmarkAnswers'      => Cache::get(getCacheKeyUserBased('benchmarkAnswers')),
			'multipleChoiceAnswers' => Cache::get(getCacheKeyUserBased('multipleChoiceDashboard')),
			'currentPeriod'         => Cache::get(getCacheKeyUserBased('currentPeriodDashboard')),
			'previousPeriod'        => Cache::get(getCacheKeyUserBased('PreviousPeriodDashboard')),
			'schoolsBenchmark'      => Cache::get(getCacheKeyUserBased('SchoolsBenchmarkDashboard')),
			'module_type'           => $this->module_type,
			'showNps'               => $showNps,
			'getPermissionForModule' => $this->getPermissionForModule,
			'userPermisisons'       => $this->userPermisisons,
			'activeTenantModules'       => $this->activeTenantModules,
			'applyBenchmarkFilter'=>$this->applyBenchmarkFilter,
			'onlyCustomFilter'=>$this->onlyCustomFilter
		])->setOptions(['defaultFont' => 'sans-serif'])->output();

		return response()->streamDownload(
			fn () => print($pdf),
			$filename,
			[
				'Content-Type' => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"'
			]
		);

	}

	public function generateHtml(): string
	{
		$period = PeriodScope::PERIOD_FILTERS[$this->period];
		$showNps = false;
		if(array_intersect(explode(" ",$this->module_type),[Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE, Tenant::ALL, Tenant::ALLPULSE]) && count(getActiveModules(true))>0){
			$showNps = true;
		}

		return view('exports.dasboard', [
			'period'                => $period,
			'filters'               => $this->filters,
			'custom_nps_filter'      => $this->custom_nps_filter,
			'benchmarkAnswers'      => Cache::get(getCacheKeyUserBased('benchmarkAnswers')),
			'multipleChoiceAnswers' => Cache::get(getCacheKeyUserBased('multipleChoiceDashboard')),
			'currentPeriod'         => Cache::get(getCacheKeyUserBased('currentPeriodDashboard')),
			'previousPeriod'        => Cache::get(getCacheKeyUserBased('PreviousPeriodDashboard')),
			'schoolsBenchmark'      => Cache::get(getCacheKeyUserBased('SchoolsBenchmarkDashboard')),
			'module_type'           => $this->module_type,
			'showNps'               => $showNps,
			'getPermissionForModule' => $this->getPermissionForModule,
			'userPermisisons'       => $this->userPermisisons,
			'activeTenantModules'       => $this->activeTenantModules,
		])->render();
	}

	public function deletePdf(string $path): void
	{
		$storagePath = Str::afterLast($path, 'app/');
		Storage::delete($storagePath);
	}

	public function render(): View
	{
		return view('livewire.tenant.dashboard.export');
	}

	protected function baseBenchmarkQuery($benchmarkReport,$answer_count_query,$is_previous){

		$bechmarkR = SurveyAnswer::query()
			->selectRaw(
				<<<SQL
                                   ROUND(AVG(value)*10,0) as score
                    SQL)
			->benchmark()
			->filterAnswers($this->filters)
			->where("questionable_id" ,$benchmarkReport->questionable_id)
			->where('value', 'not LIKE', '%N/A%')
			->whereNotNull('value');
		if($is_previous){
			$bechmarkR = $bechmarkR->previousPeriodFilter($this->period,$this->custom_date)->whereIn('survey_invite_id',explode(',',$answer_count_query->all_nps_previous_people_invites));
		}
		else {
			$bechmarkR = $bechmarkR->periodFilter($this->period,$this->custom_date)->whereIn('survey_invite_id',explode(',',$answer_count_query->all_nps_people_invites));
		}
		$bechmarkR = $bechmarkR->first();

		return $bechmarkR;
	}

	public function exportCsv(){
		if (!str_contains($this->custom_date, 'to')) {
			$this->custom_date  ='';
		}

		if($this->module_type!='all') {
			$moduleType = $this->module_type;
		} else{
			$moduleType = [];
		}
		$customSurvey = false;
		if (!in_array($moduleType, [Tenant::PARENT, Tenant::STUDENT, Tenant::EMPLOYEE, Tenant::ALL, Tenant::ALLPULSE])){
			$customSurvey = true;
		}


		if($this->module_type == Tenant::ALLPULSE){
			$filtersToStringModule = collect(getActiveModules(true))->toStringImploded();
		}else{
			$filtersToStringModule = collect($moduleType)->toStringImploded();
		}
		$answerFilters = collect($this->custom_nps_filter)->toStringImploded();
		$survey =  CommentsReport::query($this->filters, true)
			->when(isSequence($this->period), function ($query) {
				$query->sequenceFilter($this->period);
			}, function ($query) {
				$query->periodFilter($this->period, $this->custom_date);
			})
			->with(['surveyInvite','surveyInvite.people','surveyInvite.employee','surveyInvite.student','questionable','tags'])
			->when($this->is_custom_nps_filter, fn (Builder $query) => $query->havingRaw("type in ({$answerFilters})"))
			->when($this->surveyProgressFilter, fn (Builder $query) => $query->whereIn('survey_invites.status',$this->surveyProgressFilter))
			->when($moduleType, fn (Builder $query) => $query->havingRaw("module_type in ({$filtersToStringModule})"))
			->where('survey_answers.questionable_type','!=','')
			->where('survey_answers.questionable_id','!=',0)
			->get();
		$questions = [];
		$npsQuestions = [];
		$surveyInvites = [];
		$surveyNpsInvites = [];
		collect($survey)->each(function ($answer) use (&$questions, &$surveyInvites, &$npsQuestions) {
			/*
			 * For accessing Survey Invite
			 * */
			if ( ! isset($surveyInvites[$answer['survey_invite_id']]['SurveyDetail'])) {
				$surveyInvites[$answer['survey_invite_id']]['surveyInvite'] = $answer->surveyInvite;
			}

			/*
			 * List of array for all Answers
			 * */

			$surveyInvites[$answer['survey_invite_id']]['answers'][$answer->questionable_id . '_' . $answer->questionable_type. '_' . $answer->answer_order] = $answer;
			if($answer->questionable && $answer->questionable->type !='nps') {
				/*
				 *  create Question Array and set Flag 0 for any comment type Question
				 *  if tag found against comments
				 * */

				if(!isset($questions[$answer->questionable_id . '_' . $answer->questionable_type. '_' . $answer->answer_order])){
					$questions[$answer->questionable_id . '_' . $answer->questionable_type. '_' . $answer->answer_order] = $answer->questionable->toArray();
					$questions[$answer->questionable_id . '_' . $answer->questionable_type. '_' . $answer->answer_order]['answer_for']= "";
					if(($answer->answering_multiple || $answer->answer_order > 1) ){
						$questions[$answer->questionable_id . '_' . $answer->questionable_type. '_' . $answer->answer_order]['answer_for'] =" (" .getNthOldest($answer->answer_order)." Child)";
					}
					$questions[$answer->questionable_id . '_' . $answer->questionable_type. '_' . $answer->answer_order]['tags'] = 0;
				}
				/*
				 * Set Flag to 1 and Store comment in an array
				 * */
				if($answer->tags->count()) {
					$questions[$answer->questionable_id . '_' . $answer->questionable_type. '_' . $answer->answer_order]['tags'] = 1;
				}
			} else{
				$npsQuestions[$answer->questionable_id . '_' . $answer->questionable_type . '_' . $answer->answer_order] = $answer->questionable?->toArray();
			}

		});
		uksort($questions, function($a, $b) {
			$numA = (int)strstr($a, '_', true);
			$numB = (int)strstr($b, '_', true);
			return $numA <=> $numB;
		});

		$this->toggleModal(false);
		return Excel::download(new SurveyAnswersExport(($surveyInvites), $questions, $npsQuestions, $customSurvey), 'export_survey_answers.xlsx');

	}

	public function exportFiles()
	{
		// Type shoudl be pdf/csv
		$this->validate(['exportType' => ['required',Rule::in('pdf','csv')]]);
		$exportMethod = 'export'.ucfirst($this->exportType);
		$this->notify('File Downloaded Successfully');
		return $this->$exportMethod();
	}

	public function toggleModal($modal = true)
	{
		$this->exportType = 'pdf';
		$this->resetErrorBag();
		$this->showDownloadModal = $modal ? !$this->showDownloadModal : false;
	}

}
