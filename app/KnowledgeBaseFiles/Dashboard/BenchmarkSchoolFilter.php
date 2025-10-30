<?php

namespace App\Http\Livewire\Tenant\Dashboard;

use App\Models\Tenant;
use Illuminate\Support\Str;
use Livewire\Component;

class BenchmarkSchoolFilter extends Component
{

    public $modal = false;
    public $showClear = false;
    public array $dynamicFieldsWithValues = [];
    public $filter = null;
    public $mobile = false;
    public $appliedFilter = null;
    public $allSchoolsText = null;

    protected $listeners = [
        'open-benchmark-school-filter' => 'open',
        'benchmark-school-filter-refresh' => '$refresh',
        'reset-benchmark-school-filter' => 'resetData',
    ];

    public function mount()
    {
        $tenants = Tenant::where('client_type_id', tenant()->client_type_id)->count();
        $this->appliedFilter = null;
        $this->showClear = false;
        $this->allSchoolsText = 'All '. clientMapping()->get('brand_name');
        $this->allSchoolsText .= $tenants > 0 ? " ({$tenants} " . Str::plural(strtolower(clientMapping()->get('label')), $tenants) . ')' : '';
        $this->loadDynamicFieldsWithValues();
    }

    public function loadDynamicFieldsWithValues()
    {
        $tenant = tenant();
        $dynamicData = getTenantDynamicFieldsData($tenant);
        $fields = $dynamicData['fields'];
        $values = $dynamicData['values'];
        $this->dynamicFieldsWithValues = [];
        foreach ($fields as $index => $field) {
            if (!isset($field['allow_dashboard_benchmark']) || !$field['allow_dashboard_benchmark']) {
                continue;
            }
            $identifier = $field['identifier'] ?? null;
            if ($identifier && isset($values[$identifier]) && $values[$identifier] !== '' && $values[$identifier] !== [] && $values[$identifier] !== null) {
                $rawValue = $values[$identifier];

                // Map value(s) to label(s) if options are present
                if (isset($field['options']) && $field['type'] === 'multi_select' && is_array($rawValue)) {
                    foreach ($rawValue as $i => $optionKey) {
                        $actualValue = $field['options'][$optionKey] ?? $optionKey;
                        $displayValue = $actualValue;
                        if ($identifier != 'school_size') {
                            $displayValue .= ' only';
                        }
                        $this->dynamicFieldsWithValues[] = [
                            'field' => $field,
                            'key' =>$field['identifier']."_unique_$i",
                            'value' => $actualValue,
                            'displayValue' => $displayValue,
                            'fieldKey' => 'field_' . $index,
                        ];
                    }
                } else {
                    $displayValue = $rawValue;
                    if (isset($field['options'])) {
                        $displayValue = $field['options'][$rawValue] ?? $rawValue;
                    }
                    if ($identifier != 'school_size') {
                        $displayValue .= ' only';
                    }
                    $this->dynamicFieldsWithValues[] = [
                        'field' => $field,
                        'key' =>$field['identifier'],
                        'value' => $rawValue,
                        'displayValue' => $displayValue,
                        'fieldKey' => 'field_' . $index,
                    ];
                }
            }
        }
    }

    public function open()
    {
        $this->loadDynamicFieldsWithValues();
        $this->modal = true;
    }

    public function save()
    {
        if ($this->filter == null) {
            $this->modal = false;
            return;
        }

        foreach ($this->filter as $key => $value) {
            if ($value === true) {
                $this->filter[$key] = '0';
            } elseif ($value === false) {
                unset($this->filter[$key]);
            }
        }

        if ($this->filter == null) {
            $this->appliedFilter = null;
            $this->modal = false;
            $this->emit('set-benchmark-school-filter');
            $this->mount();
            return;
        }

        if (!empty($this->filter)) {
            $this->appliedFilter = [];
            $fields = collect($this->dynamicFieldsWithValues)->keyBy(fn($item) => $item['field']['identifier']);

            foreach ($this->filter as $key => $value) {
                if (strpos($key, '_unique') !== false) {
                    $key = explode('_unique', $key)[0];
                }
                if (!$fields->has($key)) {
                    continue;
                }
                $item = $fields[$key];
                $field = $item['field'];
                // For multi_select, value can be array or string
                if ($field['type'] == 'multi_select') {
                    $selectedValues = is_array($value) ? $value : [$value];
                    foreach ($selectedValues as $val) {
                        // If options exist, get display value from options, else use raw
                        $display = $field['options'][$val] ?? $val;
                        $this->appliedFilter[] = $display;
                    }
                } else {
                    $this->appliedFilter[] = $item['displayValue'];
                }
            }
        } else {
            $this->filter = null;
            $this->appliedFilter = null;
        }
        $tenants = getTenantsByClientTypeAndOtherFields($this->filter, tenant()->client_type_id, tenant()->id);
        $tenantCount = count($tenants);
        $this->appliedFilter[] = $tenantCount > 0 ? "({$tenantCount} " . Str::plural(strtolower(clientMapping()->get('label')), $tenantCount) . ')' : '';
        $this->showClear = true;
        $this->modal = false;

        $this->emit('set-benchmark-school-filter', $this->filter);
    }

    public function resetData()
    {
        $this->emit('set-benchmark-school-filter');
        $this->filter = [];
        $this->appliedFilter = [];
        $this->showClear = false;
    }

    public function render()
    {
        return view('livewire.tenant.dashboard.benchmark-school-filter');
    }
}
