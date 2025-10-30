<?php

namespace App\Http\Livewire\Tenant\Dashboard\Nps;

use App\Models\Tenant\SurveyAnswer;
use App\Scopes\PeriodScope;
use Carbon\{Carbon, CarbonPeriod};
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class Chart extends Component
{
    public string $period = PeriodScope::DEFAULT_PERIOD;
    public string $custom_date = '';

    public ?string $groupDataChart = 'week';

    public array $data = [];

    protected $listeners = ['filters::period' => 'setPeriod'];

    public function mount()
    {
        $this->fillChart();
    }

    public function fillChart(): void
    {
        $this->fillDataChart();
        $this->fillPeriodChart();
    }

    private function fillDataChart(): void
    {
        $this->data = match ($this->period) {
        PeriodScope::LAST_365_DAYS,
            PeriodScope::ALL_TIME          => $this->findAllTimeAnswers($this->period),
            PeriodScope::LAST_THREE_MONTHS => $this->findLastThreeMonthsAnswers(),
            PeriodScope::LAST_THIRTY_DAYS  => $this->findLastThirtyDaysAnswers(),
            PeriodScope::TODAY             => $this->findTodayAnswers(),
            PeriodScope::Custom             => $this->findCustomAnswers(),
        };
    }

    private function fillPeriodChart(): void
    {
        $this->groupDataChart = match ($this->period) {
        PeriodScope::ALL_TIME,
            PeriodScope::LAST_365_DAYS     => 'year',
            PeriodScope::LAST_THREE_MONTHS => 'month',
            PeriodScope::LAST_THIRTY_DAYS  => 'week',
            PeriodScope::TODAY             => 'hour',
            PeriodScope::Custom             => 'custom'
        };
    }

    private function findAllTimeAnswers(string $period): array
    {
        if ($period === PeriodScope::LAST_365_DAYS) {
            $intervals = collect((new CarbonPeriod())
                ->every('1 year')
                ->since(now()->subDays(365))
                ->until(now()));

            $years = $intervals->map(fn (Carbon $date) => (string) $date->year)->toArray();

            $query = $this->baseQuery()->tap(fn (Builder $query) => $query->whereYear('updated_at', array_shift($years)));

            foreach ($years as $year) {
                $query->union($this->baseQuery()->tap(fn (Builder $query) => $query->whereYear('updated_at', $year)));
            }
        } else {
            $query = $this->baseQuery();
        }

        return $this->formatData($query);
    }

    private function findLastThreeMonthsAnswers(): array
    {
        $intervals = collect((new CarbonPeriod())
            ->every('1 month')
            ->since(now()->subMonths(3))
            ->until(now()));

        $months = $intervals->map(fn (Carbon $date) => $date->month)->toArray();

        $query = $this->baseQuery()->tap(fn ($query) => $query->whereMonth('updated_at', array_shift($months)));

        foreach ($months as $month) {
            $query->union($this->baseQuery()->tap(fn (Builder $query) => $query->whereMonth('updated_at', $month)));
        }

        return $this->formatData($query);
    }

    private function findLastThirtyDaysAnswers(): array
    {
        $query = $this->baseQuery();

        return $this->formatData($query);
    }

    private function findCustomAnswers(): array
    {
        $query = $this->baseQuery();

        return $this->formatData($query);
    }

    private function findTodayAnswers(): array
    {
        $query = $this->baseQuery();

        return $query->get()
            ->map(fn (SurveyAnswer $surveyAnswer) => [$surveyAnswer->updated_at->format('Y-m-d H:i:s') => $surveyAnswer->score])
            ->collapse()
            ->toArray();
    }

    private function formatData(Builder $query): array
    {
        return $query
            ->get()
            ->map(fn (SurveyAnswer $surveyAnswer) => [$surveyAnswer->updated_at->format('Y-m-d') => $surveyAnswer->score])
            ->collapse()
            ->toArray();
    }

    private function baseQuery(): Builder|SurveyAnswer
    {
        return SurveyAnswer::query()
            ->selectRaw(<<<SQL
                IFNULL(
                    ROUND(
                        (SUM(IF (value >= 9, 1, 0)) / COUNT(value)) * 100
                        - (SUM(IF (value <= 6, 1, 0)) / COUNT(value)) * 100
                    ),
                    0) as score,
                    updated_at
            SQL)
            ->benchmark()
            ->periodFilter($this->period,$this->custom_date)
            ->latestSurvey()
            ->groupBy('updated_at');
    }

public function setPeriod(string $period,string $custom_date): void
{
    $this->period = $period;
    $this->custom_date = $custom_date;
}

public function render()
{
    $this->fillChart();

    return view('livewire.tenant.dashboard.nps.chart');
}
}
