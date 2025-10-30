<?php

namespace App\Http\Livewire\Tenant\Home\SurveyActivity;

use App\Actions\Tenant\Surveys\SurveyCycles;
use Carbon\Carbon;
use Livewire\Component;

/**
 * @property-read int $currentWeekNumber
 * @property-read int $previousWeek
 * @property-read int $currentWeek
 * @property-read int $nextWeek
 */
class WeekLetters extends Component
{
    public function getCurrentWeekNumberProperty(): int
    {
        return (new SurveyCycles(now()))->weekIndex() + 1;
    }

    public function getPreviousWeekProperty(): array
    {
        return $this->makeWeekData(now()->subWeek());
    }

    public function getCurrentWeekProperty(): array
    {
        return $this->makeWeekData(now());
    }

    public function getNextWeekProperty(): array
    {
        return $this->makeWeekData(now()->addWeek());
    }

    private function makeWeekData(Carbon $date): array
    {
        $surveyCycle = new SurveyCycles($date);

        $cycle = $surveyCycle->weekCycle();
        $start = Carbon::parse($cycle['starts_at']);
        $end   = Carbon::parse($cycle['ends_at']);

        return [
            'date'    => "{$start->format('m/d')} - {$end->format('m/d')}",
            'letters' => implode(' ', $surveyCycle->weekLetters()),
        ];
    }

    public function render()
    {
        return view('livewire.tenant.home.survey-activity.week-letters');
    }
}
