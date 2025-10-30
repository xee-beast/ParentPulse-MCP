<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use Livewire\Component;

class BenchmarkFilter extends Component
{
    public $applyBenchmarkFilter = false;
    public function render()
    {
        return view('livewire.tenant.dashboard.benchmark-filter');
    }
    public function updatedApplyBenchmarkFilter(){
        $this->emit('filters::apply-benchmark', $this->applyBenchmarkFilter);
    }

}
