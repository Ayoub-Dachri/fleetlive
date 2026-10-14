<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Accepts a single telemetry object or {"messages": [...]}.
 */
class GpsIngestRequest extends FormRequest
{
    public const MAX_MESSAGES = 500;

    /**
     * The raw, dot-keyed payloads exactly as the tracker sent them.
     *
     * @return list<array>
     */
    public function payloads(): array
    {
        return $this->has('messages') ? array_values((array) $this->input('messages')) : [$this->all()];
    }

    /**
     * Tracker keys contain literal dots ("position.latitude"). Expanding them into
     * nested arrays lets the rules below use Laravel's normal dot notation.
     */
    public function validationData(): array
    {
        $payloads = $this->has('messages') ? $this->input('messages') : [$this->all()];

        return [
            'messages' => is_array($payloads)
                ? array_map(fn ($payload) => is_array($payload) ? Arr::undot($payload) : $payload, $payloads)
                : $payloads,
        ];
    }

    public function rules(): array
    {
        return [
            'messages' => ['required', 'array', 'min:1', 'max:'.self::MAX_MESSAGES],
            'messages.*' => ['required', 'array'],
            'messages.*.ident' => ['required', 'string', 'max:64'],
            'messages.*.timestamp' => ['required', 'numeric', 'min:0'],
            'messages.*.position.latitude' => ['required', 'numeric', 'between:-90,90'],
            'messages.*.position.longitude' => ['required', 'numeric', 'between:-180,180'],
            'messages.*.position.valid' => ['nullable', 'boolean'],
            'messages.*.position.altitude' => ['nullable', 'numeric', 'between:-99999,999999'],
            'messages.*.position.direction' => ['nullable', 'numeric', 'between:0,360'],
            'messages.*.position.speed' => ['nullable', 'numeric', 'between:0,999999'],
            'messages.*.position.hdop' => ['nullable', 'numeric', 'between:0,999'],
            'messages.*.position.satellites' => ['nullable', 'integer', 'between:0,255'],
            'messages.*.engine.ignition.status' => ['nullable', 'boolean'],
            'messages.*.movement.status' => ['nullable', 'boolean'],
            'messages.*.battery.voltage' => ['nullable', 'numeric', 'between:0,999'],
            'messages.*.external.powersource.voltage' => ['nullable', 'numeric', 'between:0,999'],
            'messages.*.gsm.signal.level' => ['nullable', 'integer', 'between:0,65535'],
            'messages.*.vehicle.mileage' => ['nullable', 'numeric', 'between:0,999999999'],
            'messages.*.server.timestamp' => ['nullable', 'numeric', 'min:0'],
            'messages.*.device.id' => ['nullable', 'integer', 'min:0'],
            'messages.*.device.name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Report fields by their tracker name, e.g. "The position.latitude field is required."
     */
    public function attributes(): array
    {
        return collect($this->rules())
            ->keys()
            ->mapWithKeys(fn (string $rule) => [$rule => Str::after($rule, 'messages.*.')])
            ->all();
    }
}
