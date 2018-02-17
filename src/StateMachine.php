<?php

namespace Bidzm\StateMachine;

use Bidzm\StateMachine\Exceptions\InvalidStateTransition;
use Carbon\Carbon;

trait StateMachine
{
    protected $fromTransition;
    protected $toTransition;
    // protected $transitions = [
    //     [
    //         'from' => [],
    //         'to' => '',
    //         'on' => ''
    //     ],
    // ];

    public static function bootStateMachine()
    {
        static::creating(function ($model) {
            $fieldState = $model->getFieldState();
            if (empty($model->$fieldState)) {
                $model->$fieldState = $model->getInitialState();
            }
            $model->beforeTransition($model->fromTransition, $model->toTransition);
            $model->setStateChangeAt();
        });
        static::created(function ($model) {
            $model->afterTransition($model->fromTransition, $model->toTransition);
        });
        static::saving(function ($model) {
            $model->beforeTransition($model->fromTransition, $model->toTransition);
            $model->setStateChangeAt();
        });
        static::saved(function ($model) {
            $model->afterTransition($model->fromTransition, $model->toTransition);
        });
    }

    public function __call($method, $arguments)
    {
        if (preg_match('/^(can)[A-Z]+[A-z]*/', $method)) {
            $action = preg_replace('/^(can)/', '', $method);
            $actionSnakeCase = snake_case($action);
            if ($this->isAvailableAction($action) || $this->isAvailableAction($actionSnakeCase)) {
                return $this->can($action) || $this->can($actionSnakeCase);
            }
        } else if (preg_match('/^(is)[A-Z]+[A-z]*/', $method)) {
            $state = preg_replace('/^(is)/', '', $method);
            $stateSnakeCase = snake_case($state);
            if ($this->isAvailableState($state) || $this->isAvailableState($stateSnakeCase)) {
                return $this->isState($state) || $this->isState($stateSnakeCase);
            }
        } else if (preg_match('/[A-z]+(At)$/', $method)) {
            $state = preg_replace('/(At)$/', '', $method);
            $stateSnakeCase = snake_case($state);
            if ($this->isAvailableState($state) || $this->isAvailableState($stateSnakeCase)) {
                return $this->stateChangeAt($state) ?? $this->stateChangeAt($stateSnakeCase);
            }
        } else {
            if ($this->isAvailableAction($method)) {
                $actionSnakeCase = snake_case($method);
                if ($this->can($method) || $this->can($actionSnakeCase)) {
                    return $this->saveState($method);
                } else {
                    $fieldState = $this->getFieldState();
                    $fromState = $this->$fieldState;
                    throw new InvalidStateTransition("Invalid state transition from {$fromState} to {$method}");
                }
            }
        }
        return parent::__call($method, $arguments);
    }

    public function can(String $action) : bool
    {
        foreach ($this->getTransitions() as $transition) {
            $on = array_get($transition, 'on');
            if ($action === $on) {
                $from = array_flatten([array_get($transition, 'from', [])]);
                $fieldState = $this->getFieldState();
                if (in_array($this->$fieldState, $from)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function isState(String $action) : bool
    {
        $fieldState = $this->getFieldState();
        return $action === $this->$fieldState;
    }

    public function stateChangeAt(String $state) : ?Carbon
    {
        $timestamp = null;
        if ($this->isActiveStateChangeAt()) {
            $stateSnakeCase = snake_case($state);
            $stateChangeAt = $this->state_change_at ?? [];
            $time = array_get($stateChangeAt, $state) ?? array_get($stateChangeAt, $stateSnakeCase) ?? 'Not Found';
            try {
                $timestamp = Carbon::parse($time);
            } catch (\Exception $e) {}
        }
        return $timestamp;
    }

    public function getFieldState() : String
    {
        $fieldState = $this->fieldState ?? 'state';
        if (empty($this->$fieldState)) {
            $this->$fieldState = $this->getInitialState();
        }
        return $fieldState;
    }

    public function getInitialState() : String
    {
        return $this->initialState ?? 'initiated';
    }

    public function getTransitions() : Array
    {
        return $this->transitions ?? [];
    }

    public function isActiveStateChangeAt() : bool
    {
        return $this->useChangeAt ?? false;
    }

    public function getCasts()
    {
        $this->casts['state_change_at'] = 'array';
        return parent::getCasts();
    }

    public function beforeTransition($from, $to) {}

    public function afterTransition($from, $to) {}

    private function isAvailableAction(String $action) : bool
    {
        foreach ($this->getTransitions() as $transition) {
            $validAction = array_get($transition, 'on');
            if ($validAction && $validAction === $action) {
                return true;
            }
        }
        return false;
    }

    private function isAvailableState(String $state) : bool
    {
        $states = [$this->getInitialState()];
        foreach ($this->getTransitions() as $transition) {
            $fromStates = array_flatten([array_get($transition, 'from', [])]);
            $toState = array_get($transition, 'to');
            array_push($states, $fromStates);
            array_push($states, $toState);
        }
        $states = array_flatten($states);
        return in_array($state, $states);
    }

    private function saveState(String $action) : bool
    {
        $actionSnakeCase = snake_case($action);
        $fieldState = $this->getFieldState();
        $this->fromTransition = $this->$fieldState;
        foreach ($this->getTransitions() as $transition) {
            $onState = array_get($transition, 'on');
            $toState = array_get($transition, 'to');
            if ($action === $onState) {
                $this->toTransition = $toState;
                $this->$fieldState = $toState;
            }
        }
        return $this->save();
    }

    private function setStateChangeAt() : void
    {
        if ($this->isActiveStateChangeAt()) {
            $stateChangeAt = $this->state_change_at ?? [];
            $fieldState = $this->getFieldState();
            $state = $this->$fieldState;
            if (!empty($state)) {
                $stateChangeAt[$state] = Carbon::now()->toW3cString();
            }
            $this->state_change_at = $stateChangeAt;
        }
    }
}
