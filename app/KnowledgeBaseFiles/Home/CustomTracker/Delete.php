<?php

namespace App\Http\Livewire\Tenant\Home\CustomTracker;

use App\Models\Tenant\Tracker;
use Livewire\Component;

class Delete extends Component
{
    public Tracker $tracker;

    public bool $modal = false;

    public function destroy()
    {
        $this->tracker->delete();

        $this->modal = false;
        $this->emit('custom-tracker::delete');
        $this->notify('Custom tracker deleted successfully.');
    }

    public function render()
    {
        return view('livewire.tenant.home.custom-tracker.delete');
    }
}
